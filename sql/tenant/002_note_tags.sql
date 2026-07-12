-- Example incremental tenant migration (ARCHITECTURE.md §3).
--
-- Individually idempotent: guarded CREATEs and a conflict-tolerant seed
-- INSERT mean re-applying this file after a lost ledger row is a no-op.
-- Each statement ends its line with ';' — the splitter's contract.

CREATE TABLE IF NOT EXISTS tags (
    id    TEXT PRIMARY KEY,
    name  TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS note_tags (
    note_id  TEXT NOT NULL,
    tag_id   TEXT NOT NULL,
    PRIMARY KEY (note_id, tag_id)
);

INSERT INTO tags (id, name) VALUES ('seed-general', 'general') ON CONFLICT DO NOTHING;
