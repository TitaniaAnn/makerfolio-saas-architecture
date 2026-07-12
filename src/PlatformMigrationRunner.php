<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * Fan the tenant migration runner out across the fleet with per-tenant
 * failure isolation (ARCHITECTURE.md §3).
 *
 * The structural guarantee: one broken tenant records an error and the
 * loop continues; the finally puts the search_path back even on throw,
 * so a failure can never leave the connection pointed at the wrong
 * tenant's schema.
 */
final class PlatformMigrationRunner
{
    public function __construct(
        private readonly Database $db,
        private readonly string $tenantMigrationsDir,
    ) {
    }

    /**
     * @param iterable<array{id: int|string, schema_name: string}> $tenants
     * @param callable(array): array|null $applyOne  override for tests;
     *        defaults to the real per-schema apply
     *
     * @return array<int|string, array{applied?: list<string>, error?: string}>
     */
    public function applyAll(iterable $tenants, ?callable $applyOne = null): array
    {
        $applyOne ??= fn (array $tenant): array => $this->applyToSchema($tenant['schema_name']);

        $results = [];
        foreach ($tenants as $tenant) {
            try {
                $results[$tenant['id']] = ['applied' => $applyOne($tenant)];
            } catch (\Throwable $e) {
                // Isolation, not suppression: the error is recorded per
                // tenant (the operator UI shows it with a retry button)
                // and the remaining tenants still get their migrations.
                $results[$tenant['id']] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** @return list<string> */
    public function applyToSchema(string $schema): array
    {
        $this->db->setSchema($schema);
        try {
            return (new MigrationRunner($this->db, $this->tenantMigrationsDir))->applyAll();
        } finally {
            $this->db->resetSchema();
        }
    }
}
