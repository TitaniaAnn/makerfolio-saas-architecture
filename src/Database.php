<?php

declare(strict_types=1);

namespace MakerfolioArch;

/**
 * Thin PDO wrapper — the toy analog of the product's Database singleton.
 *
 * Two things matter architecturally (ARCHITECTURE.md §1):
 *
 *  - setSchema()/resetSchema() are the ONLY tenancy mechanism. They run
 *    `SET search_path TO "<schema>", public` once per request; every
 *    subsequent unqualified query is scoped to the tenant's schema.
 *    Application code never writes `WHERE tenant_id = ?`.
 *
 *  - A forgotten setSchema() fails LOUD: with search_path at `public`,
 *    a tenant-table query raises `relation "note" does not exist` —
 *    never a silent cross-tenant read.
 *
 * Schema switching is Postgres-only. The rest of the wrapper is
 * dialect-agnostic so the contract tests can run on in-memory SQLite.
 * (The product is a plain global class, not namespaced; the namespace
 * here is repo hygiene, not a product convention.)
 */
final class Database
{
    private \PDO $pdo;
    private string $driver;

    public function __construct(string $dsn, ?string $user = null, ?string $password = null)
    {
        $this->pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public static function sqliteInMemory(): self
    {
        return new self('sqlite::memory:');
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** Parameterized queries only — same contract as the product. */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Run $fn inside a transaction; commit on return, roll back on throw.
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Point every unqualified query at the tenant's schema (§1).
     * `public` stays on the path so platform lookups keep working.
     */
    public function setSchema(string $schema): void
    {
        $this->requirePostgres(__METHOD__);
        $this->pdo->exec(sprintf(
            'SET search_path TO %s, public',
            self::quoteIdentifier($schema)
        ));
    }

    /**
     * Back to platform-only. After this, tenant-table queries fail loud.
     */
    public function resetSchema(): void
    {
        $this->requirePostgres(__METHOD__);
        $this->pdo->exec('SET search_path TO public');
    }

    /**
     * Validate-then-quote for the few places (schema DDL) where an
     * identifier can't be a bound parameter. Rejects rather than escapes:
     * schema names are machine-generated (`tenant_<id>`), so anything
     * outside this alphabet is a bug, not user input to accommodate.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException("Unsafe identifier: {$identifier}");
        }

        return '"' . $identifier . '"';
    }

    private function requirePostgres(string $method): void
    {
        if ($this->driver !== 'pgsql') {
            throw new \LogicException(
                "{$method} requires Postgres (search_path); current driver is {$this->driver}. " .
                'The SQLite-backed tests exercise the dialect-agnostic contracts only.'
            );
        }
    }
}
