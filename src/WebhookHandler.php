<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * The INSERT-first webhook idempotency contract (ARCHITECTURE.md §4),
 * shared by every webhook plane in the product (platform Stripe,
 * Connect, SES).
 *
 * Order of operations is the whole point:
 *
 *  1. INSERT the event id into the dedup ledger FIRST, outside any
 *     transaction. The primary key serializes concurrent deliveries of
 *     the same event: exactly one INSERT wins.
 *  2. A duplicate whose ledger row is already stamped `processed_at`
 *     short-circuits — the handler never re-runs.
 *  3. A duplicate whose row is UNSTAMPED is a crashed-mid-flight retry:
 *     the handler runs again (its previous transaction rolled back).
 *  4. Handler + `processed_at` stamp commit in ONE transaction, so
 *     "handled but not stamped" is unrepresentable.
 *  5. Side effects that must not delay or poison the response (in the
 *     product: mail) run AFTER the commit, via $afterCommit.
 */
final class WebhookHandler
{
    public const PROCESSED = 'processed';
    public const DUPLICATE = 'duplicate';
    public const RETRIED   = 'retried';

    public function __construct(
        private readonly Database $db,
        private readonly string $ledgerTable = 'billing_events',
    ) {
    }

    /**
     * @param callable(Database): void      $handler     the state change
     * @param callable(Database): void|null $afterCommit post-commit side
     *        effects (mail); skipped entirely if the handler throws
     *
     * @return self::PROCESSED|self::DUPLICATE|self::RETRIED
     */
    public function handle(
        string $eventId,
        string $eventType,
        callable $handler,
        ?callable $afterCommit = null,
    ): string {
        $outcome = self::PROCESSED;

        try {
            // (1) INSERT-first, autocommitted: this is the dedup gate.
            $this->db->query(
                "INSERT INTO {$this->ledgerTable} (event_id, event_type, received_at) VALUES (?, ?, ?)",
                [$eventId, $eventType, gmdate('Y-m-d\TH:i:s\Z')]
            );
        } catch (\PDOException $e) {
            if (!self::isUniqueViolation($e)) {
                throw $e;
            }
            $row = $this->db->fetchOne(
                "SELECT processed_at FROM {$this->ledgerTable} WHERE event_id = ?",
                [$eventId]
            );
            if ($row !== null && $row['processed_at'] !== null) {
                return self::DUPLICATE;          // (2) fully processed: 200, done
            }
            $outcome = self::RETRIED;            // (3) crashed mid-flight: re-run
        }

        // (4) state change + stamp are atomic.
        $this->db->transaction(function (Database $db) use ($eventId, $handler): void {
            $handler($db);
            $db->query(
                "UPDATE {$this->ledgerTable} SET processed_at = ? WHERE event_id = ?",
                [gmdate('Y-m-d\TH:i:s\Z'), $eventId]
            );
        });

        // (5) mail-shaped work never runs inside the transaction and
        // never runs at all for a failed handler.
        if ($afterCommit !== null) {
            $afterCommit($this->db);
        }

        return $outcome;
    }

    private static function isUniqueViolation(\PDOException $e): bool
    {
        // SQLSTATE 23505 = Postgres unique_violation; 23000 = SQLite/ANSI
        // integrity constraint violation.
        return in_array($e->getCode(), ['23505', '23000', 23000], true);
    }
}
