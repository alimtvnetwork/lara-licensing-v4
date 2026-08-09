# Seeder Coverage Plan (Plan 18, Step 6)

Per-OperationId map of the DB rows required for a **non-empty happy-path response**, assigned to a specific seeder file and to a specific step in the Phase B window (41-60). Sourced from actual `backend/database/seeders/` (`ClosedSetsSeeder`, `DatabaseSeeder`, `E2EFixturesSeeder`, `FeatureCatalogSeeder`, `RolesSeeder`, `RootSeeder`, `ShardSeeder`) and `backend/app/Models/` (17 models listed in Step 5 recon).

## Missing factories

`backend/database/factories/` is empty. Phase B first needs factories for every model listed above **before** row-generation seeders can be written. That budget is folded into Steps 41-45 (default profile bootstrap).

## Seeder file layout (target after Phase B)

New seeder files (created in Steps 41-60):

- `DemoIdentitiesSeeder.php` - the three demo accounts (admin/reseller/portal) with bcrypt cost 4.
- `DemoResellersSeeder.php` - 8 resellers (mixed Active/Suspended).
- `DemoUsersSeeder.php` - 40 users spread across resellers, mixed roles.
- `DemoLicensesSeeder.php` - 120 licenses (Issued/Active/Expiring-soon mix; drives KPI tiles).
- `DemoBindingsSeeder.php` - 200 MachineBinding rows tied to licenses.
- `DemoSerialsSeeder.php` - 80 serials for portal lookup happy-path.
- `DemoQuotaRequestsSeeder.php` - 24 requests (Pending/Approved/Denied mix).
- `DemoAuditSeeder.php` - 500 audit rows across last 30 days (drives audit list + KPI "ApprovedThisWeek").
- `DemoSessionsSeeder.php` - 30 AuthSession + 3 ImpersonationIndex rows (drives Sessions.Active).
- `DemoAppUpdatesSeeder.php` - 6 AppUpdate rows + 12 assets (drives update-manifest + admin app-updates list).
- `DemoPrefixesSeeder.php` - 12 Prefix rows.
- `ErrorProfileSeeder.php` - injects rows that trigger deterministic error paths (expired licenses in the past, orphaned bindings, quota over-limit).

## Per-OperationId row requirements

Column names below reference the models listed above; exact column list is confirmed when each factory is authored in Steps 41-45.

### G1. Auth

| OperationId | Required rows | Seeder | Step |
|-------------|---------------|--------|------|
| `auth.login` | 3 `User` (demo identities) + `UserRole` mapping | `DemoIdentitiesSeeder` + `RolesSeeder` (existing) | 41 |
| `auth.refresh` | 1 `AuthSession` per demo user | `DemoSessionsSeeder` | 44 |
| `auth.logout` | (uses row created by login) | - | - |
| `auth.me` | (reads the caller row + `UserRole`) | (covered by 41) | - |
| `auth.password-reset.request` | 1 `PasswordResetToken` fixture on demo admin | `DemoIdentitiesSeeder` | 41 |
| `auth.password-reset.confirm` | (consumes token from 41) | - | - |

### G2. Admin.Metrics.Kpis

Non-empty tile targets (values chosen to be obviously non-zero in preview):

| Tile | Source table | Required rows |
|------|--------------|---------------|
| `Resellers.Total` | `Reseller` | >= 8 |
| `Resellers.Active` | `Reseller` where `Status=Active` | >= 5 |
| `Sessions.Active` | `AuthSession` where `RevokedAt IS NULL AND ExpiresAt > now` | >= 12 |
| `Sessions.LastHour` | `AuthSession` where `CreatedAt > now-1h` | >= 3 |
| `Licenses.Issued` | `License` all-time | >= 120 |
| `Licenses.Active` | `License` where `Status=Active AND ExpiresAt > now` | >= 90 |
| `Licenses.ExpiringSoon` | `License` where `ExpiresAt BETWEEN now AND now+30d` | >= 8 |
| `Quota.Pending` | `QuotaRequest` where `Status=Pending` | >= 6 |
| `Quota.ApprovedThisWeek` | `QuotaRequest` where `Status=Approved AND DecidedAt > now-7d` | >= 4 |

Seeders + steps: `DemoResellersSeeder` (42), `DemoSessionsSeeder` (44), `DemoLicensesSeeder` (43), `DemoQuotaRequestsSeeder` (45).

### G3. Admin.Licenses

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `admin.licenses.list` | >= 120 `License` (mix Status) | `DemoLicensesSeeder` | 43 |
| `admin.licenses.show` | at least 1 licence with `Ledger` entries + bindings | `DemoLicensesSeeder` + `DemoBindingsSeeder` | 43-44 |
| `admin.licenses.create/update/delete` | (target rows exist in list) | - | - |
| `admin.licenses.ledger` | >= 20 ledger rows per demo license (Issue/Revoke/Bind/Release) | `DemoLicensesSeeder` (ledger sub-inserts) | 43 |
| `admin.licenses.bindings` | >= 200 `MachineBinding` | `DemoBindingsSeeder` | 44 |

### G4. Admin.Features

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `admin.features.list` | (reuses existing) `FeatureCatalogSeeder` | `FeatureCatalogSeeder` (existing) | 46 (empty-profile: still runs; feature catalog is universal) |

### G5. Portal

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `portal.updates.manifest` | 6 `AppUpdate` + 12 `AppUpdateAsset` | `DemoAppUpdatesSeeder` | 47 |
| `portal.serials.lookup` | >= 80 `Serial` tied to `License` | `DemoSerialsSeeder` | 47 |

### G6. Admin.Quotas

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `admin.quotas.list` | >= 24 `QuotaRequest` mixed status | `DemoQuotaRequestsSeeder` | 45 |
| `admin.quotas.list-all` | (same fan-out set) | (covered) | - |
| `admin.quotas.approve/deny` | at least 6 Pending targets for approve, 6 for deny | `DemoQuotaRequestsSeeder` | 45 |

### G7. Admin.Impersonation

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `admin.impersonation.start` | (target `User` rows exist) | - | - |
| `admin.impersonation.stop` | 3 `ImpersonationIndex` rows (one active, one expired, one revoked) | `DemoSessionsSeeder` | 44 |
| `admin.impersonation.force-end` | (uses same 3 rows) | - | - |

### G8. Admin.Audit

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `admin.audit.list` | 500 audit rows spanning 30d (multi-page assertion depends on this per Step 41 of Plan 17) | `DemoAuditSeeder` | 48 |

### G9. Admin.Users

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `admin.users.list` | >= 40 `User` | `DemoUsersSeeder` | 42 |
| `admin.users.create/update/delete` | target user rows | - | - |
| `admin.users.roles.list/assign/revoke` | `UserRole` rows per user | `DemoUsersSeeder` + `RolesSeeder` | 42 |
| `admin.users.sessions.list` | AuthSession rows per user | `DemoSessionsSeeder` | 44 |

### G10. Admin.RuntimeConfig

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `admin.runtime-config.show` | 1 config row (existing `RootSeeder` seeds defaults) | `RootSeeder` (existing) | - |
| `admin.runtime-config.update` | (mutation target) | - | - |

### G11. BE-only promotions (from Step 5)

| OperationId | Rows | Seeder | Step |
|-------------|------|--------|------|
| `admin.resellers.*` | 8 `Reseller` | `DemoResellersSeeder` | 42 |
| `admin.prefixes.*` | 12 `Prefix` | `DemoPrefixesSeeder` | 49 |
| `admin.app-updates.*` | 6 `AppUpdate` + 12 assets | `DemoAppUpdatesSeeder` | 47 |
| `admin.backup.exports.list/create` | 3 backup export rows (spec/22-backup-restore) | (new) `DemoBackupSeeder` | 49 |
| `admin.backup.imports.create` | (uses existing rows) | - | - |
| `admin.sessions.terminate` | (uses AuthSession from 44) | - | - |

## Phase B step assignments (rolled up)

- 41 `DemoIdentitiesSeeder` (3 users + roles + password-reset token fixture).
- 42 `DemoResellersSeeder` + `DemoUsersSeeder` (8 resellers, 40 users, role mappings).
- 43 `DemoLicensesSeeder` (120 licences + ledger inserts).
- 44 `DemoBindingsSeeder` + `DemoSessionsSeeder` (200 bindings, 30 sessions, 3 impersonation rows).
- 45 `DemoQuotaRequestsSeeder` (24 requests, mixed status).
- 46 `empty` profile stub (runs only `RolesSeeder` + `FeatureCatalogSeeder` + `RootSeeder`; asserts empty-state renders).
- 47 `DemoAppUpdatesSeeder` + `DemoSerialsSeeder`.
- 48 `DemoAuditSeeder` (500 audit rows across 30d).
- 49 `DemoPrefixesSeeder` + `DemoBackupSeeder`.
- 50 wire `DatabaseSeeder` profile selector on `SEED_PROFILE` env (default/empty/error).
- 51-55 `ErrorProfileSeeder` variants (expired licences, orphaned bindings, quota over-limit, revoked sessions, stalled backup).
- 56-60 profile toggles, env wiring, `php artisan db:seed --class=...` shortcuts, docs.

## Feeds forward

- Step 7 (seed profiles) picks which subset of the 41-60 seeders each profile invokes.
- Step 8 (demo login) consumes Step 41 identity rows.
- Step 10 (preview-fixture parity) mirrors the row counts above into `src/lib/preview-fixtures/*` so the FE preview mode matches the BE seed profile row-for-row.
- Step 13 (Pest test plan) uses the row counts as feature-test expectations.
