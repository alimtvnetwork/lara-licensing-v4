# Plan 18 · Step 10 · Preview-fixture Coverage Plan

Status: draft (produced by Plan 18 Step 10).

Depends on: `docs/backend/plan-18/01-operations-inventory.md`,
`docs/backend/plan-18/03-parity-matrix.md`, `docs/backend/plan-18/06-seeder-coverage-plan.md`,
`docs/frontend/plan-18/09-demo-login-panel-plan.md`,
`src/lib/preview-fixtures/_shapes.ts` (registry),
`src/generated/api/operations.ts` (OperationId union).

## 1. Ground truth

`PREVIEW_RESPONSE_SHAPES` in `src/lib/preview-fixtures/_shapes.ts:185`
is `Record<OperationId, z.ZodTypeAny>`; adding a new OperationId to the
generated union without a shape row is a compile error. That registry
is the single source of truth for "every fixture handler must match a
Zod shape". This plan enumerates the handler files that back each
row.

## 2. OperationId -> fixture file -> Zod shape

Every OperationId listed in `_shapes.ts:186-224` MUST have exactly one
handler registered under `src/lib/preview-fixtures/` and MUST return a
payload that `assertPreviewShape` (see `_shapes.ts:252`) accepts.

| # | OperationId | Fixture file | Shape symbol (`_shapes.ts`) | Status | Notes |
|--:|---|---|---|---|---|
| 1 | `auth.login` | `auth.ts` | `AuthLoginResponse` | present | Extend in Step 67 to accept the three demo emails from `demo-identities.ts`. |
| 2 | `auth.refresh` | `auth.ts` | `AuthTokenPair` | present | No change. |
| 3 | `auth.logout` | `auth.ts` | `Empty` | present | No change. |
| 4 | `auth.me` | `me.ts` | `MeUser` | present | Branch on the active demo identity so roles match Step 8. |
| 5 | `password-reset.request` | `password-reset.ts` | `Empty` | present | No change. |
| 6 | `password-reset.confirm` | `password-reset.ts` | `Empty` | present | No change. |
| 7 | `admin.licenses.list` | `licenses.ts` | `paginated(License)` | present | Seed count widened in Step 71 to satisfy `admin.metrics.kpis`. |
| 8 | `admin.licenses.show` | `licenses.ts` | `License` | present | No change. |
| 9 | `admin.licenses.create` | `licenses.ts` | `License` | present | No change. |
| 10 | `admin.licenses.update` | `licenses.ts` | `License` | present | No change. |
| 11 | `admin.licenses.delete` | `licenses.ts` | `Empty` | present | No change. |
| 12 | `admin.features.list` | `features.ts` | `z.object({ Items: z.array(FeatureDefinition) })` | present | Aligns with new BE `FeatureController` in Step 33. |
| 13 | `portal.updates.manifest` | `updates.ts` | inline `{Latest, Available}` | present | No change. |
| 14 | `portal.serials.lookup` | `serials.ts` | `PortalSerialLookupResponse` | present | Add revoked branch in Step 88 for error profile. |
| 15 | `admin.quotas.list` | `quotas.ts` | `paginated(Quota)` | present | Row count raised to >=24 in Step 72 (matches seeder plan §6). |
| 16 | `admin.quotas.update` | `quota-requests.ts` | `Quota` | present | File rename to `admin-quotas.ts` deferred; keep current path. |
| 17 | `admin.impersonation.start` | `impersonation.ts` | `ImpersonationSession` | present | No change. |
| 18 | `admin.impersonation.stop` | `impersonation.ts` | `Empty` | present | No change. |
| 19 | `admin.audit.list` | `audit.ts` | `paginated(AuditEntry)` | present | 500-row corpus already in place (Plan 17 Step 41). No change. |
| 20 | `admin.metrics.kpis` | `metrics.ts` | `AdminMetricsKpisResponse` | present | Must return non-zero tiles under `default` seed. Fix in Step 73. |
| 21 | `admin.users.list` | `admin-users.ts` | `paginated(AdminUser)` | present | No change. |
| 22 | `admin.users.create` | `admin-users.ts` | `AdminUser` | present | No change. |
| 23 | `admin.users.update` | `admin-users.ts` | `AdminUser` | present | No change. |
| 24 | `admin.users.delete` | `admin-users.ts` | `Empty` | present | No change. |
| 25 | `admin.runtime-config.show` | `runtime-config.ts` | `RuntimeConfigDoc` | present | No change. |
| 26 | `admin.runtime-config.update` | `runtime-config.ts` | `RuntimeConfigDoc` | present | No change. |

Tally: 26/26 OperationIds have a live handler and a Zod row. Zero
NEW fixture files created by Plan 18; every gap is a payload edit, not
a missing file. `license-features.ts` and `tier-features.ts` remain
sub-handlers of the features surface and are exercised transitively by
`admin.features.list`.

## 3. New OperationIds queued for later phases

Steps 21-40 will add BE actions that DO NOT yet exist in
`src/generated/api/operations.ts`. When codegen introduces the new
IDs, `PREVIEW_RESPONSE_SHAPES` becomes non-exhaustive and TypeScript
fails. Each new ID gets a fixture row in the SAME step that adds it to
the OpenAPI source:

| Future OperationId | Adding step | Fixture file | New Zod symbol |
|---|--:|---|---|
| `auth.session.refresh` (rename of `auth.refresh`, if adopted in Step 24) | 24 | `auth.ts` | reuse `AuthTokenPair` |
| `admin.licenses.index` (BE-side alias) | 30 | `licenses.ts` | reuse `paginated(License)` |
| `admin.serials.show` | 31 | `serials.ts` | `SerialShow` (new) |
| `admin.users.destroy` (verb rename) | 32 | `admin-users.ts` | reuse `Empty` |
| `admin.features.index` | 33 | `features.ts` | reuse `admin.features.list` shape |

If a step adds a new BE action without producing a matching fixture
row in the same commit, the frontend build fails at
`_shapes.ts:185` (`Property '<id>' is missing in type ...`), which is
the intended tripwire. No code changes ship this step.

## 4. Seed-profile behaviour per fixture

The dispatcher lives in `src/lib/preview-seed-dispatcher.ts` and reads
`getRuntimeMode().Seed` (see `runtime-mode.ts:281`). Rules:

- `default`: every list handler MUST return >= the row counts from
  `06-seeder-coverage-plan.md §Row targets`. Handlers currently hard-code
  6-12 rows; the widening happens in Steps 71-75, not this step.
- `empty`: every list handler returns `paginated([])` with `Total = 0`,
  `Page = 1`. Show/get handlers return `LaraApiError` with
  `errorCode = "…NotFound"` matching the resource's canonical code
  from `spec/03-error-manage/03-error-code-registry/error-codes-master.json`.
- `error`: handlers flip a subset of rows to failure states (expired
  licenses, revoked serials, stalled backups). Table lives in
  Step 90's artifact `docs/testing/plan-18/90-error-seed-mapping.md`.

## 5. Validation loop

The existing `assertPreviewShape` call in `src/lib/preview-transport.ts`
runs on every fixture response and throws `PreviewFixtureShapeError`
(also a `LaraApiError`, see `_shapes.ts:231`) on mismatch. Step 176's
linter (`check-preview-fixture-shapes.py`) will:

1. Import `PREVIEW_RESPONSE_SHAPES` via a small Node stub and assert
   `Object.keys(PREVIEW_RESPONSE_SHAPES).length === OperationId union
   size` (extracted from `operations.ts`).
2. For each key, dynamically require the handler and run the
   dispatcher with each seed profile, feeding the payload through the
   Zod shape. Any failure blocks CI.

## 6. Out of scope

- Introducing new fixture files (none needed; the 15 existing files
  cover all 26 IDs).
- Changing the transport contract in `preview-transport.ts`
  (Plan 17 Step 42 finalised it).
- Rewriting Zod shapes for shared types (`License`, `Quota`,
  `AdminUser`); their canonical definitions live in
  `src/generated/api/schemas/` and are BE-driven.
