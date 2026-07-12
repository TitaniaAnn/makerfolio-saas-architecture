<?php

declare(strict_types=1);

use MakerfolioArch\Database;
use MakerfolioArch\MigrationRunner;
use MakerfolioArch\PlatformMigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §3 — idempotent per-tenant migrations with failure
 * isolation. The ledger, the re-apply-is-harmless contract, the
 * statement splitter, and the fan-out's one-bad-tenant guarantee.
 */
final class MigrationRunnerTest extends TestCase
{
    private const TENANT_DIR = __DIR__ . '/../sql/tenant';

    public function test_applies_in_order_records_ledger_and_second_run_is_a_noop(): void
    {
        $db = Database::sqliteInMemory();
        $runner = new MigrationRunner($db, self::TENANT_DIR);

        self::assertSame(['001_init.sql', '002_note_tags.sql'], $runner->applyAll());
        self::assertSame([], $runner->applyAll(), 'already-applied files must be skipped');

        $ledger = $db->fetchAll('SELECT filename FROM schema_migrations ORDER BY filename');
        self::assertSame(
            ['001_init.sql', '002_note_tags.sql'],
            array_column($ledger, 'filename')
        );
    }

    public function test_reapply_after_lost_ledger_row_is_harmless(): void
    {
        // §3's core promise: idempotency lives in the files, so a lost
        // ledger row (restored dump, manual surgery) is recoverable by
        // simply re-running.
        $db = Database::sqliteInMemory();
        $runner = new MigrationRunner($db, self::TENANT_DIR);
        $runner->applyAll();

        $db->query('DELETE FROM schema_migrations WHERE filename = ?', ['002_note_tags.sql']);

        self::assertSame(['002_note_tags.sql'], $runner->applyAll());

        $seeds = $db->fetchAll("SELECT * FROM tags WHERE id = 'seed-general'");
        self::assertCount(1, $seeds, 'conflict-tolerant seed must not duplicate on re-apply');
    }

    public function test_splitter_breaks_on_statement_terminating_lines_and_drops_comments(): void
    {
        $sql = (string) file_get_contents(self::TENANT_DIR . '/002_note_tags.sql');
        $statements = MigrationRunner::splitStatements($sql);

        self::assertCount(3, $statements);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS tags', trim($statements[0]));
        self::assertStringStartsWith('INSERT INTO tags', trim($statements[2]));
        foreach ($statements as $statement) {
            self::assertStringNotContainsString('--', $statement, 'full-line comments are dropped');
        }
    }

    public function test_platform_fanout_isolates_a_failing_tenant(): void
    {
        // §3: one bad tenant records an error; the rest still migrate.
        $db = Database::sqliteInMemory();
        $fanout = new PlatformMigrationRunner($db, self::TENANT_DIR);

        $tenants = [
            ['id' => 1, 'schema_name' => 'tenant_1'],
            ['id' => 2, 'schema_name' => 'tenant_2'],
            ['id' => 3, 'schema_name' => 'tenant_3'],
        ];
        $applied = [];
        $results = $fanout->applyAll($tenants, function (array $tenant) use (&$applied): array {
            if ($tenant['id'] === 2) {
                throw new RuntimeException('this tenant is broken');
            }
            $applied[] = $tenant['id'];

            return ['001_init.sql'];
        });

        self::assertSame([1, 3], $applied, 'tenants after the failure must still run');
        self::assertSame(['applied' => ['001_init.sql']], $results[1]);
        self::assertSame(['error' => 'this tenant is broken'], $results[2]);
        self::assertSame(['applied' => ['001_init.sql']], $results[3]);
    }
}
