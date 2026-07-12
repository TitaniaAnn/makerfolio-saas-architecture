-- The tenant schema, in the toy's generic notes domain (the product's
-- analog is the full CMS schema: piece, products, events, orders, ...).
--
-- Applied inside each tenant's schema by MigrationRunner — under
-- Postgres the search_path decides WHERE these tables land, which is
-- why the file itself never mentions a schema (ARCHITECTURE.md §1).

CREATE TABLE IF NOT EXISTS note (
    id          TEXT PRIMARY KEY,
    title       TEXT NOT NULL,
    body        TEXT,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    deleted_at  TEXT
);

CREATE TABLE IF NOT EXISTS settings (
    key    TEXT PRIMARY KEY,
    value  TEXT
);
