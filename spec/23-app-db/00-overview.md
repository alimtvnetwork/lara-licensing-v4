# App DB

**Version:** 3.2.0  
**Updated:** 2026-04-16  
**AI Confidence:** Draft  
**Ambiguity:** None

---

## Keywords

`app-db` · `schema` · `migrations` · `queries` · `data-model`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present | ✅ |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Application-specific database (App DB) specifications for whatever project this repo ships , web app, Chrome extension, CLI, plugin, mobile app, etc. Covers the app's data model, table designs, migration strategies, query patterns, and any database decisions unique to this application. Complements the core `04-database-conventions/` (general naming/schema rules) and `05-split-db-architecture/` (SQLite partitioning) with app-specific schema details.

---

## Document Inventory

| # | File | Purpose |
|---|------|---------|
| 01 | [`01-schema.md`](./01-schema.md) | Full table-by-table schema for LaraLicensingV1. |
| 01 | [`01-erd.mmd`](./01-erd.mmd) | Entity relationship diagram. |
| 02 | [`02-jwt-flow.mmd`](./02-jwt-flow.mmd) | JWT auth sequence. |
| 03 | [`03-oauth-client-credentials.mmd`](./03-oauth-client-credentials.mmd) | OAuth2 client-credentials sequence. |
| 09 | [`09-verify-sequence.mmd`](./09-verify-sequence.mmd) | Serial, hash, verify final flow. |
| 10 | [`10-reseller-shard-split-db.md`](./10-reseller-shard-split-db.md) | Per-reseller shard mapping onto the split-DB tiers. |

---

## Cross-References

- [Database Conventions (Core)](../04-database-conventions/00-overview.md) , General naming, PK/FK, ORM conventions
- [Split DB Architecture](../05-split-db-architecture/00-overview.md) , SQLite partitioning and migration patterns
- [App](../21-app/00-overview.md) , App-specific features and workflows
- [Consolidated Database Conventions](../17-consolidated-guidelines/18-database-conventions.md) , Consolidated summary

---

*App DB , created 2026-04-16, slug renamed `23-app-database` → `23-app-db` on 2026-04-26*

---

## Verification

_Auto-generated section , see `spec/23-app-db/97-acceptance-criteria.md` for the full criteria index._

### AC-ADB-000: App DB conformance: Overview

**Given** Validate app database migrations against the schema-design rules.  
**When** Run the verification command shown below.  
**Then** Migrations are forward-only; PascalCase naming is preserved; new columns are nullable with no DEFAULT (Rule 12).

**Verification command:**

```bash
python3 linter-scripts/check-forbidden-strings.py
```

**Expected:** exit 0. Any non-zero exit is a hard fail and blocks merge.

_Verification section last updated: 2026-04-21_
