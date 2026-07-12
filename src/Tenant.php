<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * The tenant lifecycle state machine behind transitionTo()
 * (ARCHITECTURE.md §6) and transactional provisioning (§1, §3).
 *
 * Status changes go through transitionTo() — never a direct UPDATE.
 * The method validates the edge against TRANSITIONS, writes the audit
 * row, and (in the product) fires the edge's side effects. Crons,
 * webhook handlers, and operator buttons all call the same method, so
 * an invalid jump is unrepresentable rather than merely discouraged.
 */
final class Tenant
{
    public const PENDING_VERIFICATION = 'PENDING_VERIFICATION';
    public const ACTIVE               = 'ACTIVE';
    public const GRACE                = 'GRACE';
    public const SUSPENDED            = 'SUSPENDED';
    public const PENDING_DELETION     = 'PENDING_DELETION';
    public const DELETED              = 'DELETED';

    /** The allowed edges. Anything not listed throws. */
    private const TRANSITIONS = [
        self::PENDING_VERIFICATION => [self::ACTIVE, self::SUSPENDED],
        self::ACTIVE               => [self::GRACE, self::SUSPENDED, self::PENDING_DELETION],
        self::GRACE                => [self::ACTIVE, self::SUSPENDED],
        self::SUSPENDED            => [self::ACTIVE, self::PENDING_DELETION],
        self::PENDING_DELETION     => [self::ACTIVE, self::SUSPENDED, self::DELETED],
        self::DELETED              => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Validate the edge, apply it, audit it — atomically.
     *
     * @return array the updated tenant row
     */
    public static function transitionTo(Database $db, int|string $tenantId, string $to, string $reason): array
    {
        return $db->transaction(function (Database $db) use ($tenantId, $to, $reason): array {
            $tenant = $db->fetchOne('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
            if ($tenant === null) {
                throw new \RuntimeException("No tenant {$tenantId}");
            }
            if (!self::canTransition($tenant['status'], $to)) {
                throw new \DomainException(
                    "Invalid tenant transition {$tenant['status']} -> {$to}"
                );
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $suspendedAt = match ($to) {
                self::SUSPENDED => $now,
                self::ACTIVE    => null,
                default         => $tenant['suspended_at'],
            };

            $db->query(
                'UPDATE tenants SET status = ?, suspended_at = ? WHERE id = ?',
                [$to, $suspendedAt, $tenantId]
            );
            $db->query(
                'INSERT INTO platform_activity (id, action, target_type, target_id, details, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    bin2hex(random_bytes(16)),
                    'tenants.transition',
                    'tenant',
                    (string) $tenantId,
                    json_encode(['from' => $tenant['status'], 'to' => $to, 'reason' => $reason]),
                    $now,
                ]
            );

            return $db->fetchOne('SELECT * FROM tenants WHERE id = ?', [$tenantId]) ?? [];
        });
    }

    /**
     * Provision a tenant atomically (Postgres only): directory row,
     * CREATE SCHEMA, and the full tenant migration set commit or roll
     * back TOGETHER — Postgres's transactional DDL is what makes a
     * failed provision leave nothing behind (§1).
     *
     * @return array the new tenant row
     */
    public static function provision(
        Database $db,
        int $id,
        string $handle,
        string $tenantMigrationsDir,
    ): array {
        return $db->transaction(function (Database $db) use ($id, $handle, $tenantMigrationsDir): array {
            $schema = 'tenant_' . $id;
            $db->query(
                'INSERT INTO tenants (id, handle, schema_name, status) VALUES (?, ?, ?, ?)',
                [$id, $handle, $schema, self::PENDING_VERIFICATION]
            );
            $db->pdo()->exec('CREATE SCHEMA ' . Database::quoteIdentifier($schema));

            $db->setSchema($schema);
            try {
                (new MigrationRunner($db, $tenantMigrationsDir))->applyAll();
            } finally {
                $db->resetSchema();
            }

            return $db->fetchOne('SELECT * FROM tenants WHERE id = ?', [$id]) ?? [];
        });
    }
}
