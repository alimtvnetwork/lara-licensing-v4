# Standard: No Magic Literals

**Source:** User instruction 2026-07-18. Canonical rule lives in `.lovable/coding-guidelines/coding-guidelines.md` Hard Rule 8.

## Rule

Every literal that carries domain meaning MUST be defined once in a dedicated enum, const, or catalog module and imported by every consumer.

## Applies to

Status, Kind, Role, PermissionKey, FeatureKey, LedgerAction, AuditAction, ErrorCode, HTTP status, environment name, tier name, category id, header name, endpoint path, table name, column name, regex, timeout ms, retry count, cache TTL, cron expression, currency, unit.

## Allowed raw literals

`0`, `1`, `-1`, `''`, booleans, loop/array indexes, obvious math identities, test fixture values inside `*.test.*` / `*.spec.*`.

## Cross-tier parity

For LaraLicensingV1, the same catalog values MUST appear in:

1. `spec/21-app/*` (normative source of truth).
2. TypeScript enums/consts under `src/lib/lara-*.ts`.
3. PHP config in `backend/config/lara.php` and PHP enum classes.
4. Migration CHECK constraint literals: comment each CHECK with the source spec file.

Any drift is a build-fail bug, not a warning.

## Waiver

`// lint-allow: magic-literal reason="..."` on the line, used sparingly, reviewed at PR.

## Linter

`linter-scripts/check-magic-literals.py` scans `src/`, `backend/app/`, `backend/database/migrations/` for suspicious quoted strings and numeric literals that do not resolve to a shared catalog.
