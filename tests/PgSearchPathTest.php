<?php

declare(strict_types=1);

use MakerfolioArch\Database;
use MakerfolioArch\MigrationRunner;
use MakerfolioArch\PlatformMigrationRunner;
use MakerfolioArch\Tenant;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §1 — the search_path isolation contract itself, which
 * only Postgres can demonstrate. Skips cleanly unless a PG_DSN env var
 * points at a scratch database, e.g.:
 *
 *   PG_DSN='pgsql:host=localhost;dbname=toy' PG_USER=postgres PG_PASS=... \
 *     vendor/bin/phpunit --filter PgSearchPathTest
 *
 * The test provisions two real tenants (schema + migrations, atomically)
 * and verifies the two halves of the §1 claim: the same unqualified
 * query is scoped per tenant, and a forgotten setSchema fails LOUD
 * instead of leaking.
 */
final class PgSearchPathTest extends TestCase
{
    private const TENANT_DIR = __DIR__ . '/../sql/tenant';

    private ?Database $db = null;

    protected function setUp(): void
    {
        $dsn = getenv('PG_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set PG_DSN (and optionally PG_USER/PG_PASS) to run the Postgres isolation test.');
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
        $pdo = $this->db->pdo();
        $pdo->exec('DROP SCHEMA IF EXISTS tenant_901 CASCADE');
        $pdo->exec('DROP SCHEMA IF EXISTS tenant_902 CASCADE');
        $pdo->exec("DELETE FROM tenants WHERE id IN (901, 902)");
    }

    public function test_same_query_is_scoped_per_tenant_and_forgotten_setschema_fails_loud(): void
    {
        Tenant::provision($this->db, 901, 'annie-toy', self::TENANT_DIR);
        Tenant::provision($this->db, 902, 'bob-toy', self::TENANT_DIR);

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->db->setSchema('tenant_901');
        $this->db->query(
            'INSERT INTO note (id, title, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['n1', "annie's note", $now, $now]
        );
        $this->db->setSchema('tenant_902');
        $this->db->query(
            'INSERT INTO note (id, title, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['n2', "bob's note", $now, $now]
        );

        // The load-bearing claim: the SAME unqualified SQL, scoped by
        // search_path alone. No WHERE tenant_id anywhere.
        $this->db->setSchema('tenant_901');
        self::assertSame(
            ["annie's note"],
            array_column($this->db->fetchAll('SELECT title FROM note ORDER BY id'), 'title')
        );
        $this->db->setSchema('tenant_902');
        self::assertSame(
            ["bob's note"],
            array_column($this->db->fetchAll('SELECT title FROM note ORDER BY id'), 'title')
        );

        // The loud-failure half: with the path back at public, the same
        // query throws "relation does not exist" — never another
        // tenant's rows.
        $this->db->resetSchema();
        try {
            $this->db->fetchAll('SELECT title FROM note');
            self::fail('expected the unscoped query to fail loud');
        } catch (PDOException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
        }
    }

    public function test_fanout_applies_real_migrations_per_schema_with_isolation(): void
    {
        Tenant::provision($this->db, 901, 'annie-toy', self::TENANT_DIR);
        Tenant::provision($this->db, 902, 'bob-toy', self::TENANT_DIR);

        $tenants = $this->db->fetchAll(
            'SELECT id, schema_name FROM tenants WHERE id IN (901, 902) ORDER BY id'
        );
        $results = (new PlatformMigrationRunner($this->db, self::TENANT_DIR))->applyAll($tenants);

        // Provisioning already applied everything: the fleet-wide run is
        // a clean no-op, and each tenant's ledger is its own.
        self::assertSame(['applied' => []], $results[901]);
        self::assertSame(['applied' => []], $results[902]);

        $this->db->setSchema('tenant_901');
        self::assertCount(2, $this->db->fetchAll('SELECT filename FROM schema_migrations'));
        $this->db->resetSchema();
    }

    public function test_failed_provision_rolls_back_schema_and_directory_row(): void
    {
        // A migration that fails AFTER the CREATE SCHEMA: Postgres's
        // transactional DDL must take the directory row AND the schema
        // down with it — a failed provision leaves nothing behind (§1).
        $badDir = sys_get_temp_dir() . '/toy-bad-migrations-' . bin2hex(random_bytes(4));
        mkdir($badDir);
        file_put_contents($badDir . '/001_broken.sql', "THIS IS NOT SQL;\n");

        try {
            Tenant::provision($this->db, 902, 'bob-toy', $badDir);
            self::fail('expected the broken migration to abort provisioning');
        } catch (PDOException) {
        } finally {
            unlink($badDir . '/001_broken.sql');
            rmdir($badDir);
        }

        self::assertNull($this->db->fetchOne('SELECT id FROM tenants WHERE id = ?', [902]));
        self::assertNull(
            $this->db->fetchOne(
                'SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?',
                ['tenant_902']
            ),
            'a failed provision must leave no schema behind'
        );
    }
}
