---
Slug: handwritten-schema-baseline
Status: pending
Created: 2026-07-20
Parent: 16-preview-production-runtime-typed-api
---

# SS-03: Hand-written schema baseline

Until Scramble/OpenAPI is exporting cleanly, hand-write `src/generated/api/schema.d.ts` so FE work does not block on BE tooling.

## Deliverables

- `src/generated/api/schema.d.ts` — types for every operation currently called from `src/lib/lara-*.ts`.
- `src/generated/api/operations.ts` — `Operations` map keyed by `operationId`.
- `src/generated/api/envelope.ts` — canonical `SuccessEnvelope<T>` and `ErrorEnvelope` shapes.
- Header comment stating "AUTO-GENERATED WHEN SS-02 LANDS; DO NOT EDIT AFTER THAT POINT".

## Coverage checklist

Operations to shape (from grepping `src/lib/lara-*.ts`):

- Auth: `Auth.Login`, `Auth.Logout`, `Auth.Refresh`, `Auth.Me`, `Auth.PasswordResetRequest`, `Auth.PasswordResetConfirm`.
- Admin.Licenses: `Licenses.List`, `Licenses.Show`, `Licenses.Create`, `Licenses.Update` (If-Match), `Licenses.Delete`, `Licenses.Revoke`.
- Admin.Features: `Features.List`, `Features.Show`, `Features.CatalogSeeded`.
- Admin.Quotas: `Quotas.List`, `Quotas.Restore`.
- Admin.Users: `Users.List`, `Users.Show`, `Users.Create`, `Users.Update`, `Users.Delete`.
- Admin.Audit: `Audit.List`, `Audit.Show`.
- Admin.Metrics: `Metrics.Overview`.
- Admin.Impersonation: `Impersonation.Start`, `Impersonation.Stop`.
- Admin.RuntimeConfig: `RuntimeConfig.Get`, `RuntimeConfig.Update` (If-Match).
- Portal.Updates: `Updates.Manifest`.
- Portal.Serials: `Serials.Lookup`.
- Portal.Profile: `Profile.Show`, `Profile.Update`.

## Rules

- Keys are PascalCase in both request and response bodies (project convention).
- No `any`; use `unknown` only for genuinely-open extension fields and narrow at call site.
- Every error union includes the closed-set `ErrorCode` string literal type imported from `spec/03-error-manage/03-error-code-registry`.
- Every mutation that supports If-Match declares a `Version: number` field in both request and response.
