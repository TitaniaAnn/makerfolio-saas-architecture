# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

A **reference architecture**, not a runnable product. It documents the multi-tenant SaaS architecture behind makerfolio (`TitaniaAnn/makerfolio-saas` — the private product repo), and makes the load-bearing contracts runnable via a small toy-domain code cut under `src/` + `tests/`. Claims about the *product* are made verifiable by `file:line` references and named tests that point into the product repo; claims about the *patterns* are verified by the test suite here.

Structure mirrors `TitaniaAnn/my-pottery-studio-architecture` (the sibling reference-architecture repo for the mobile app): a README that says *what* and *where*, an ARCHITECTURE.md that says *why*, code that exists to make the documents' claims verifiable.

## Commands

```bash
composer install                                   # dev deps (PHPUnit)
composer test                                      # == vendor/bin/phpunit; runs on in-memory SQLite
vendor/bin/phpunit --filter WebhookDedupTest       # single class
PG_DSN='pgsql:host=...;dbname=toy' vendor/bin/phpunit   # also run the Postgres search_path tests
```

The suite must be green before committing code changes; the 3 `PgSearchPathTest` skips without `PG_DSN` are expected.

## Layout and the four-layer contract

| Layer | Answers | Organized by |
|---|---|---|
| `ARCHITECTURE.md` | *why is it shaped this way* | nine decisions, each: The problem → The decision → Why not the alternatives → Where the seams are → Verified by |
| `docs/01`–`08` | *how the subsystems fit together* | subsystem |
| `code/01`–`10` | *where the product's source does it* | subsystem, `file:line`-grounded |
| `src/` + `sql/` + `tests/` | *the contracts, runnable* | one class per contract, tests keyed to ARCHITECTURE.md §s |

Keep additions in the layer that matches their altitude. A new architectural decision gets an ARCHITECTURE.md section **in the template structure above** (all five parts — "Verified by" must name real tests/smokes in the product repo, not aspirational ones). Subsystem detail goes in the matching `docs/` file. Source-level walkthrough material goes in `code/`. A new contract worth demonstrating gets a `src/` class + a test whose docstring names its ARCHITECTURE.md section.

## Toy-cut conventions (`src/`, `sql/`, `tests/`)

- **The toy domain is generic notes/tags** — never makerfolio product vocabulary (`piece`, shop, plans). The cut demonstrates *patterns*; the walkthroughs point at the *product*.
- **SQL stays portable** (SQLite + Postgres): TEXT ISO 8601 timestamps set by code, no auto-increment (SQLite auto-assigns `INTEGER PRIMARY KEY`; code-generate TEXT ids where Postgres must insert too), `ON CONFLICT DO NOTHING` for seeds, every statement idempotent and ending its line with `;` (the splitter's contract).
- **Schema switching is Postgres-only by design** — `Database::setSchema` throws on other drivers. Dialect-agnostic contracts get SQLite tests; anything needing real `search_path` goes in `PgSearchPathTest` behind the `PG_DSN` skip.
- **Test docstrings name the ARCHITECTURE.md section they verify** (§1, §3, §4, §6, §7). Keep the contract→test mapping traceable, same convention as the template repo.

## Conventions

- **Links into the product repo are absolute GitHub URLs** (`https://github.com/TitaniaAnn/makerfolio-saas/blob/main/...`) — relative paths can't reach across repos. Links within this repo are relative.
- **`code/` is a mirror.** The walkthroughs are copied from the product repo's `design-docs/walkthroughs/code/` with links rewritten; the provenance note at the top of `code/README.md` says the in-repo copy wins on drift. When refreshing, re-copy and re-run the link rewrite (`../../../` → product-repo blob URL; `../../` → `design-docs/`; `../` → `design-docs/walkthroughs/`) — don't hand-edit mirrored content.
- **The product repo is the source of truth for facts.** When a doc here disagrees with `makerfolio-saas` code or its `design-docs/COMPLETION_MATRIX.md`, this repo is stale; fix it here. Never invent as-built claims — verify against the product repo first.
- Line numbers in `code/` walkthroughs drift as the product changes; treat them as "look near here" (the walkthroughs' own README says this). Don't chase line-number drift in isolation — refresh the whole mirror.
- Mermaid diagrams render on GitHub; keep them small enough to read without scrolling.
- Prose style follows the template repo: honest about gaps ("What's not here"), decisions include the rejected alternatives, seams are named explicitly.

## What not to do

- **Do not copy product source code into this repo.** The `src/` cut is a from-scratch expression of the patterns in a toy domain (owner-approved); verbatim product code is not — the product is private, and the walkthroughs' `file:line` references are the verifiability mechanism for the real implementation.
- Do not add operational secrets (hostnames, thresholds, incident details) — summarize and point at the product repo's runbooks.
- The "makerfolio" brand is not MIT-licensed (see README §License); don't reuse it in examples that imply otherwise.

## Currency

Documents were written against the product repo at approximately PR #146 (2026-07). The as-built record there is `design-docs/COMPLETION_MATRIX.md` (last fully refreshed at PR #96; the git log is authoritative past that).
