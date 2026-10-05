<?php

declare(strict_types=1);

use MakerfolioArch\Database;
use MakerfolioArch\MigrationRunner;
use MakerfolioArch\WebhookHandler;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §4/§5 — why the INSERT-first claim must run
 * autocommitted, never nested inside a transaction that outlives it.
 *
 * The dedup gate works by EXPECTING a unique violation on a retry and
 * catching it. SQLite shrugs that off; Postgres does not: a caught
 * error still aborts the enclosing transaction, so the follow-up
 * "was it already processed?" SELECT fails with SQLSTATE 25P02. The
 * product's Connect webhook hit exactly this (2026-08 audit, L8) and
 * 500'd every retry until the outer transaction was removed.
 *
 * Postgres-only, so it skips without PG_DSN like PgSearchPathTest.
 */
final class PgWebhookTransactionTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        $dsn = getenv('PG_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set PG_DSN (and optionally PG_USER/PG_PASS) to run the Postgres transaction test.');
        }
        $this->db = new Database($dsn, getenv('PG_USER') ?: null, getenv('PG_PASS') ?: null);
        (new MigrationRunner($this->db, __DIR__ . '/../sql/public'))->applyAll();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->db->query("DELETE FROM billing_events WHERE event_id LIKE 'evt_pgtx_%'");
    }

    public function test_caught_unique_violation_inside_an_outer_transaction_poisons_it(): void
    {
        $this->db->query(
            'INSERT INTO billing_events (event_id, event_type, received_at) VALUES (?, ?, ?)',
            ['evt_pgtx_1', 'invoice.paid', gmdate('Y-m-d\TH:i:s\Z')]
        );

        try {
            // The anti-pattern: dedup claim nested in an outer transaction.
            $this->db->transaction(function (Database $db): void {
                try {
                    $db->query(
                        'INSERT INTO billing_events (event_id, event_type, received_at) VALUES (?, ?, ?)',
                        ['evt_pgtx_1', 'invoice.paid', gmdate('Y-m-d\TH:i:s\Z')]
                    );
                } catch (\PDOException $e) {
                    self::assertSame('23505', $e->getCode()); // caught, "handled"...
                }
                // ...but the transaction is already dead.
                $db->fetchOne('SELECT processed_at FROM billing_events WHERE event_id = ?', ['evt_pgtx_1']);
            });
            self::fail('Postgres should refuse statements in an aborted transaction');
        } catch (\PDOException $e) {
            self::assertSame('25P02', $e->getCode()); // in_failed_sql_transaction
        }
    }

    public function test_autocommitted_claim_dedups_and_reruns_a_crashed_event_on_postgres(): void
    {
        $webhook = new WebhookHandler($this->db);
        $runs = 0;
        $handler = function () use (&$runs): void {
            $runs++;
        };

        // A crashed earlier attempt: ledger row claimed, never stamped.
        $this->db->query(
            'INSERT INTO billing_events (event_id, event_type, received_at) VALUES (?, ?, ?)',
            ['evt_pgtx_2', 'invoice.paid', gmdate('Y-m-d\TH:i:s\Z')]
        );

        self::assertSame(WebhookHandler::RETRIED, $webhook->handle('evt_pgtx_2', 'invoice.paid', $handler));
        self::assertSame(WebhookHandler::DUPLICATE, $webhook->handle('evt_pgtx_2', 'invoice.paid', $handler));
        self::assertSame(1, $runs);
    }
}
