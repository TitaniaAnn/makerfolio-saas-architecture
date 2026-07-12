<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MakerfolioArch\Database;
use MakerfolioArch\MigrationRunner;

/**
 * Fresh in-memory SQLite database with the platform tables applied.
 * The dialect-agnostic contracts (webhook dedup, state machines,
 * migration idempotency, resolver classification) run here; the
 * Postgres-only search_path behavior is covered by PgSearchPathTest,
 * which skips unless PG_DSN is set.
 */
function toy_platform_db(): Database
{
    $db = Database::sqliteInMemory();
    (new MigrationRunner($db, __DIR__ . '/../sql/public'))->applyAll();

    return $db;
}
