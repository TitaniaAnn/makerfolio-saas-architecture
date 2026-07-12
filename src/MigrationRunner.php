<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * SQL-first migration runner — the toy analog of the product's
 * MigrationRunner (ARCHITECTURE.md §3).
 *
 * Migrations are numbered `NNN_*.sql` files. A ledger table records what
 * has been applied *in the schema currently on the search_path* — which
 * is exactly what makes the same runner serve every tenant: point the
 * connection at a schema and the ledger you see is that schema's.
 *
 * Idempotency lives in the FILES, not the runner: every statement is
 * guarded (`CREATE TABLE IF NOT EXISTS`, `INSERT … ON CONFLICT DO
 * NOTHING`), so re-applying a file whose ledger row was lost is a no-op.
 * The runner deliberately does not catch "already exists" errors — SQL
 * DDL variance is too wide for a runner-level catch to classify safely,
 * so the judgment stays in each migration where the context is.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsDir,
        private readonly string $ledgerTable = 'schema_migrations',
    ) {
    }

    /**
     * Apply every not-yet-applied migration, in filename order.
     *
     * @return list<string> filenames applied by this call
     */
    public function applyAll(): array
    {
        $this->ensureLedger();
        $already = $this->appliedFilenames();

        $appliedNow = [];
        foreach ($this->migrationFiles() as $path) {
            $filename = basename($path);
            if (in_array($filename, $already, true)) {
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($path)) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->query(
                "INSERT INTO {$this->ledgerTable} (filename, applied_at) VALUES (?, ?)",
                [$filename, gmdate('Y-m-d\TH:i:s\Z')]
            );
            $appliedNow[] = $filename;
        }

        return $appliedNow;
    }

    /**
     * The product's splitter convention: a statement ends at a `;` that
     * terminates its line, so each statement must end on its own line.
     * Full-line `--` comments are dropped.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $current[] = $line;
            if (str_ends_with($trimmed, ';')) {
                $statements[] = implode("\n", $current);
                $current = [];
            }
        }
        if ($current !== []) {
            $statements[] = implode("\n", $current);
        }

        return $statements;
    }

    private function ensureLedger(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->ledgerTable} (
                filename   TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )"
        );
    }

    /** @return list<string> */
    private function appliedFilenames(): array
    {
        return array_column(
            $this->db->fetchAll("SELECT filename FROM {$this->ledgerTable}"),
            'filename'
        );
    }

    /** @return list<string> sorted absolute paths */
    private function migrationFiles(): array
    {
        $files = glob(rtrim($this->migrationsDir, '/') . '/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files);

        return $files;
    }
}
