# 05 - API contract duality audit (Plan 16 step 64)

Status: DISCOVERY, blocks further route migrations to `useApi` / `useApiMutation`.

## Root cause (one sentence)

The generated typed transport in `src/generated/api/{schema.d.ts,operations.ts}` and the runtime Zod contracts in `src/lib/lara-*.ts` describe two different backends: the generated layer models the aspirational Ulid / EventType / OccurredAt shape used by the preview transport, while `requestLaraApi` validates the real Laravel responses (int `AuditLogId`, `Action`, `CreatedAt`, integer `LicenseId`, integer `ActorId`), so a route that switches from `requestLaraApi` to `apiClient.call` for a non-matching operation will fail Zod validation in preview mode or type-mismatch in production mode.

## Concrete divergences

| Concept | Generated (schema.d.ts) | Runtime (lara-\*.ts / real BE) |
| --- | --- | --- |
| Identifier | `Ulid` string | positive integer |
| Audit row id | `AuditEntry.Id: Ulid` | `AuditLog.AuditLogId: int` |
| Audit action | `EventType: string` | `Action: string` |
| Audit actor | `ActorUserId: Ulid` | `ActorType: enum + ActorId: int` |
| Audit timestamp | `OccurredAt: IsoDateTime` | `CreatedAt: string` |
| License id | `License.Id: Ulid` | `License.LicenseId: int` |
| User id | `AdminUser.Id: Ulid` | `MeUser.UserId: int` (see `lara-me.ts`) |
| Quota row | `Quota { Id: Ulid, ResellerName, FeatureCode, Allocated, Used, Restored, Version }` | `ResellerQuota { ResellerId, LicenseCategoryId, LicenseTierId, LicensesGranted, LicensesConsumed, LicensesRemaining, PeriodStart, PeriodEnd }` (see `lara-quota.ts`) |

## Step 65 correction: `admin.quotas.*` is preview-only, not real-BE-aligned

The v0.569.0 / v0.570.0 changelog entries claimed `admin.quotas.list` / `admin.quotas.update` matched the real BE end to end. That was incorrect. `src/lib/lara-quota.ts::resellerQuotaSchema` proves the real Laravel BE returns `LicensesGranted / LicensesConsumed / LicensesRemaining` keyed by `LicenseCategoryId + LicenseTierId`, not the generated `{Allocated, Used, Restored}` shape keyed by `Ulid Id`. Only `admin.runtime-config.show` / `admin.runtime-config.update` are truly real-BE-aligned today.

Consequences applied in step 65:

- `tests/api-client-boundary.test.ts` splits the allowlist into `REAL_BE_ROUTES` (only `admin.runtime.tsx`) and `PREVIEW_ONLY_SHAPE_ROUTES` (`admin.quotas.tsx`). Any preview-only-shape route MUST carry an inline `preview-only-shape:` comment marker or CI fails.
- `src/routes/_authenticated/admin.quotas.tsx` now carries that marker at the top of the file explaining the divergence.
- `tests/schema-vs-runtime-parity.test.ts` was added to lock the drift: it asserts the runtime Zod schema still accepts the real BE quota/audit/license samples, and pins the current key overlap between generated `Quota` and `ResellerQuota` at exactly `["ResellerId"]`. If the generated schema is regenerated to match the real BE, this test flips and the preview-only quarantine can be removed.

## Migration boundary decision

Two operation families in `src/generated/api/operations.ts` currently line up with the real BE end to end:

- `admin.runtime-config.show` / `admin.runtime-config.update` (migrated, step 62)
- `admin.quotas.list` / `admin.quotas.update` (migrated, step 63)

Every other operation in the generated file is preview-only until either:

1. `src/generated/api/schema.d.ts` is regenerated from the Laravel OpenAPI export so the shapes match the Zod contracts, OR
2. The real BE is versioned up to the Ulid / EventType / OccurredAt shape.

Until that regeneration lands, route components MUST NOT import `apiClient.call`, `useApi`, or `useApiMutation` for any operation outside the two families above. The arch test `tests/api-client-boundary.test.ts` enforces this at CI time and surfaces violations as a hard test failure (no silent drift).

## Superseded plan items

Plan 16 step 64 originally proposed migrating `admin.serials.tsx` to `useApi("admin.serials.list")`. That operation does not exist, and the route is a single-serial lookup form, not a list. The step is redefined here (see plan file) to install the boundary guard and schedule the schema regeneration as its own step (proposed step 65).

Steps 65-70 in Plan 16 (originally serial/audit/app-updates/reseller/license/quota-request migrations) are on hold until the schema regeneration completes; the plan file will be updated to reflect that gating in the next step.
