---
Slug: lara-lib-audit
Status: pending
Created: 2026-07-20
Parent: 16-preview-production-runtime-typed-api
---

# SS-07: Audit `src/lib/lara-*.ts` against generated schema

## Scope

Every file under `src/lib/` matching `lara-*.ts`. Current inventory (as of v0.516):

- lara-api-client.ts, lara-api-contract.ts, lara-api-error.ts, lara-api-response.ts, lara-api-session.ts
- lara-app-updates.ts, lara-audit.ts, lara-auth.ts, lara-envelope.ts, lara-environment.ts
- lara-features.ts, lara-fetch.ts, lara-impersonation.ts, lara-license.ts, lara-me.ts
- lara-metrics.ts, lara-password-reset.ts, lara-prefix.ts, lara-quota.ts, lara-reseller-license.ts
- lara-retry.ts, lara-self-update.ts

## For each file

1. Compare its exported request/response types to `src/generated/api/schema.d.ts`.
2. Log drift in `spec/25-app-audit/06-lara-lib-drift-report.md` (create if missing): file, operationId, drift kind (`missing-field` / `extra-field` / `wrong-casing` / `wrong-type` / `any-leak`).
3. Fix the drift by importing from the generated schema instead of re-declaring types locally.
4. Delete duplicated type declarations after callers migrate.
5. Where a file currently accepts `unknown` and casts, replace with a Zod parse against the generated schema and throw a `LaraApiError` on failure. Never silently coerce.

## Coding-guidelines compliance

- 15-line function bodies.
- No magic literals: endpoint paths come from `src/generated/api/operations.ts`.
- PascalCase JSON keys everywhere.
- Log parse failures with `RequestId` context (error-manage rules).
