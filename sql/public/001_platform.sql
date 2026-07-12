-- Platform (public-schema) tables for the toy cut.
--
-- Deliberately portable SQL (runs on SQLite and Postgres) so the test
-- suite runs anywhere with no database server. The production schema is
-- Postgres-dialect: BIGSERIAL ids, TIMESTAMPTZ, JSONB payloads, CHECK
-- constraints on the status enums, and partial/unique indexes the toy
-- omits. Timestamps here are ISO 8601 TEXT, set by code, for the same
-- portability reason.
--
-- Every statement is individually idempotent (ARCHITECTURE.md §3): this
-- file re-applies harmlessly if its ledger row is lost.

CREATE TABLE IF NOT EXISTS tenants (
    id            INTEGER PRIMARY KEY,
    handle        TEXT NOT NULL UNIQUE,
    schema_name   TEXT NOT NULL UNIQUE,
    status        TEXT NOT NULL DEFAULT 'PENDING_VERIFICATION',
    suspended_at  TEXT
);

CREATE TABLE IF NOT EXISTS tenant_domains (
    id                  INTEGER PRIMARY KEY,
    tenant_id           INTEGER NOT NULL,
    hostname            TEXT NOT NULL UNIQUE,
    status              TEXT NOT NULL DEFAULT 'PENDING_DNS',
    verification_token  TEXT,
    updated_at          TEXT
);

-- The webhook dedup ledger (ARCHITECTURE.md §4). event_id's PRIMARY KEY
-- is what serializes concurrent deliveries of the same event.
CREATE TABLE IF NOT EXISTS billing_events (
    event_id      TEXT PRIMARY KEY,
    event_type    TEXT NOT NULL,
    received_at   TEXT NOT NULL,
    processed_at  TEXT
);

-- Append-only audit written by every transitionTo() (ARCHITECTURE.md §6).
-- The id is code-generated TEXT: portable SQL has no auto-increment that
-- parses on both SQLite and Postgres (the product uses BIGSERIAL).
CREATE TABLE IF NOT EXISTS platform_activity (
    id           TEXT PRIMARY KEY,
    action       TEXT NOT NULL,
    target_type  TEXT,
    target_id    TEXT,
    details      TEXT,
    created_at   TEXT NOT NULL
);
