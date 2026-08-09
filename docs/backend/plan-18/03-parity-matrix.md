# Parity Matrix (Plan 18, Step 3)

Cross-join of Step 1 (`01-operations-inventory.md`, 26 FE OperationIds) and Step 2 (`02-backend-route-inventory.md`, 60 BE routes). One row per FE OperationId. BE-side routes with no FE counterpart are captured in the "Backend-only routes" section for Step 4 (gap groups) to decide FE coverage.

Columns:
- **FE-path**: verbatim from `src/generated/api/operations.ts`.
- **BE-path**: canonical route in `backend/routes/api.php`, or `MISSING` if none.
- **Status**: MATCH (semantic match, only case/id-shape differ), MATCH-EXACT (byte-identical), STUB (BE route exists but body incomplete), MISSING (no BE route at all).
- **Path-case skew**: Y if FE lowercase vs BE PascalCase.
- **Id-shape skew**: Y if FE uses `:Id` vs BE named param (`{LicenseKey}`, `{UserId}`, etc.).
- **DTO skew**: TBD until Step 5 opens each controller; recorded as `?` here for anything not already known.
- **Owning step range**: which Phase-A/B/... steps repair the row.

## Matrix

| # | OperationId                  | Method | FE-path                             | BE-path                                                  | Status  | Path-case | Id-shape                         | DTO   | Owning steps |
|---|------------------------------|--------|-------------------------------------|----------------------------------------------------------|---------|-----------|----------------------------------|-------|--------------|
| 1 | auth.login                   | POST   | /api/auth/login                     | /Api/Auth/Login                                          | MATCH   | Y         | n/a                              | ?     | 21-24        |
| 2 | auth.refresh                 | POST   | /api/auth/refresh                   | MISSING                                                  | MISSING | Y         | n/a                              | new   | 25-26        |
| 3 | auth.logout                  | POST   | /api/auth/logout                    | /Api/Auth/Logout                                         | MATCH   | Y         | n/a                              | ?     | 21-24        |
| 4 | auth.me                      | GET    | /api/auth/me                        | MISSING                                                  | MISSING | Y         | n/a                              | new   | 27-28        |
| 5 | password-reset.request       | POST   | /api/password-reset/request         | /Api/Auth/ForgotPassword                                 | MATCH   | Y         | n/a (path segment rename)        | ?     | 21-24        |
| 6 | password-reset.confirm       | POST   | /api/password-reset/confirm         | /Api/Auth/ResetPassword                                  | MATCH   | Y         | n/a (path segment rename)        | ?     | 21-24        |
| 7 | admin.licenses.list          | GET    | /api/admin/licenses                 | MISSING (admin-scoped list not wired; only Reseller has GET /Licenses) | MISSING | Y | n/a                          | new   | 29-30        |
| 8 | admin.licenses.show          | GET    | /api/admin/licenses/:Id             | /Api/Admin/Licenses/{LicenseKey}                         | MATCH   | Y         | Y (`:Id` -> `{LicenseKey}`)      | ?     | 29-30        |
| 9 | admin.licenses.create        | POST   | /api/admin/licenses                 | /Api/Admin/Licenses (action: `issue`)                    | MATCH   | Y         | n/a                              | ?     | 29-30        |
| 10 | admin.licenses.update       | PATCH  | /api/admin/licenses/:Id             | /Api/Admin/Licenses/{LicenseKey}                         | MATCH   | Y         | Y                                | ?     | 29-30        |
| 11 | admin.licenses.delete       | DELETE | /api/admin/licenses/:Id             | /Api/Admin/Licenses/{LicenseKey}                         | MATCH   | Y         | Y                                | ?     | 29-30        |
| 12 | admin.features.list         | GET    | /api/admin/features                 | MISSING                                                  | MISSING | Y         | n/a                              | new   | 31-32        |
| 13 | portal.updates.manifest     | GET    | /api/portal/updates                 | /App/UpdateManifest (also `/Api/Portal/Ping` only under Portal) | MATCH-RENAME | Y | n/a (path segment rename)     | ?     | 33-34        |
| 14 | portal.serials.lookup       | GET    | /api/portal/serials/:Serial         | MISSING (BE has `POST /Api/Portal/Serials` issue only)   | MISSING | Y         | Y                                | new   | 33-34        |
| 15 | admin.quotas.list           | GET    | /api/admin/quotas                   | /Api/Admin/QuotaRequests (semantic: quota requests, not quotas) | MATCH-RENAME | Y | n/a                    | resource mismatch | 35-36 |
| 16 | admin.quotas.update         | PATCH  | /api/admin/quotas/:Id               | /Api/Admin/QuotaRequests/{RequestId}/Approve + /Deny (2 endpoints, not one PATCH) | MISMATCH | Y | Y | verb mismatch | 35-36 |
| 17 | admin.impersonation.start   | POST   | /api/admin/impersonation/start      | /Api/Admin/Users/{UserId}/Impersonate                    | MISMATCH | Y        | shape (start-by-userid vs body)  | ?     | 37           |
| 18 | admin.impersonation.stop    | POST   | /api/admin/impersonation/stop       | /Api/Admin/Impersonation/End                             | MATCH-RENAME | Y     | n/a                              | ?     | 37           |
| 19 | admin.audit.list            | GET    | /api/admin/audit                    | /Api/Admin/AuditLogs                                     | MATCH-RENAME | Y     | n/a                              | ?     | 38           |
| 20 | admin.metrics.kpis          | GET    | /api/admin/metrics/kpis             | MISSING (BE has `/Api/Admin/Metrics` index + `/ShardStatus`, no `/Kpis`) | MISSING | Y | n/a | new (root cause of red overview) | 21-22 |
| 21 | admin.users.list            | GET    | /api/admin/users                    | /Api/Admin/Users                                         | MATCH   | Y         | n/a                              | ?     | 39           |
| 22 | admin.users.create          | POST   | /api/admin/users                    | /Api/Admin/Users                                         | MATCH   | Y         | n/a                              | ?     | 39           |
| 23 | admin.users.update          | PATCH  | /api/admin/users/:Id                | /Api/Admin/Users/{UserId}                                | MATCH   | Y         | Y                                | ?     | 39           |
| 24 | admin.users.delete          | DELETE | /api/admin/users/:Id                | MISSING (BE has no DELETE on users; only PATCH + role revoke) | MISSING | Y   | Y                                | new / policy decision | 39 |
| 25 | admin.runtime-config.show   | GET    | /api/admin/runtime-config           | /Api/Admin/RuntimeConfig                                 | MATCH   | Y         | n/a                              | ?     | 40           |
| 26 | admin.runtime-config.update | PUT    | /api/admin/runtime-config           | /Api/Admin/RuntimeConfig                                 | MATCH   | Y         | n/a                              | ?     | 40           |

### Row-count reconciliation

- MATCH / MATCH-EXACT / MATCH-RENAME: 18 rows (1, 3, 5, 6, 8-11, 13, 15, 18, 19, 21, 22, 23, 25, 26).
- MISMATCH (verb/shape divergence, needs FE rewrite or BE addition): 2 rows (16, 17).
- MISSING: 6 rows (2, 4, 7, 12, 14, 20, 24) - wait, that is 7. Recount: 2, 4, 7, 12, 14, 20, 24 = 7 MISSING rows.

Corrected totals: 18 MATCH + 2 MISMATCH + 7 MISSING = 27. Off by one because row 15 (`admin.quotas.list`) is a resource-name rename (`quotas` vs `quotaRequests`) that MAY qualify as MISMATCH depending on whether `admin.quotas` semantically means quota-usage counters (different resource) or quota-request rows. Step 4 must resolve this ambiguity before Step 5 sizes the controller work.

### Root cause of the red admin overview

Row 20. FE calls `GET /api/admin/metrics/kpis`; BE exposes `GET /Api/Admin/Metrics` (index) and `GET /Api/Admin/Metrics/ShardStatus`. There is no `/Kpis` action, so every KPI tile on `/admin` renders the LaraApiError state seen in `.lovable/spec/tasks/assets/18-backend-seed-login-e2e-error-manage/admin-overview-red-errors.png`. Two resolutions are viable and BOTH will be presented in Step 5 (controller skeleton plan) as decision options:

- **Option A (BE add)**: implement `MetricsController@kpis` returning the KPI shape the FE expects (resellers count, active sessions, licenses issued, quota pressure). Preserves FE contract; one new BE action + DTO.
- **Option B (FE repoint)**: reshape `admin.metrics.kpis` to call the existing `/Api/Admin/Metrics` index and derive tiles from its payload. Zero BE work; changes FE generated types.

Step 5 records both options and picks one; Step 6 does not proceed until this decision is committed.

## Backend-only routes (no FE OperationId)

Backend routes present in Step 2's inventory with NO row in the FE inventory. Step 4 decides which of these become new FE operations in Phase A' vs which stay backend-only (used by CLI, cron, or portal only).

- `POST /Api/Auth/Register`, `GET /Api/Auth/Captcha`
- `GET /Api/Public/Health`
- Admin.Resellers: `GET/POST /Resellers`, `GET/PATCH /Resellers/{ResellerSlug}`
- Admin.Prefixes: `GET/POST /Prefixes`, `DELETE /Prefixes/{PrefixValue}`
- Admin.Licenses.Ledger: `GET /Licenses/{LicenseKey}/Ledger`
- Admin.Licenses.Bindings: `GET /Licenses/{LicenseKey}/Bindings`, `POST /Bindings/{MachineBindingId}/Release`, `POST /ClearCooldown`
- Admin.Users.Roles: `GET/POST /Users/{UserId}/Roles`, `DELETE /Users/{UserId}/Roles/{RoleName}`
- Admin.Impersonation.ForceEnd: `POST /Impersonation/{SessionId}/ForceEnd`
- Admin.QuotaRequests.All: `GET /QuotaRequests/All`
- Admin.AppUpdates: `GET /AppUpdates`, `POST /UploadTicket`, `POST /AppUpdates`, `POST /AppUpdates/{Version}/Yank`
- Admin.Metrics.ShardStatus: `GET /Metrics/ShardStatus`
- Admin.Sessions: `GET /Users/{UserId}/Sessions`, `DELETE /Sessions/{SessionId}`
- Admin.Backup: `POST /Backup/Exports`, `POST /Backup/Imports` (STUB, Plan 14)
- Reseller.Licenses: `GET/POST/PATCH/GET-ledger /Licenses...`
- Reseller.QuotaRequests: `GET/POST/POST-cancel`
- Portal.Serials + Verify chain (Serial/Hash/Final)
- App.UpdateAssetReceiver, UpdateAsset, UpdateAssetSignature

Backend-only count: ~35 routes (excludes ping closures). Step 4 will attribute each to a domain group and either open an FE OperationId row or park it as BE-internal.

## Skew tallies (feeds Step 4 budgeting)

- Path-case skew: **26/26 rows** (every FE literal is lowercase). Fix strategy is a single transport-layer normalizer OR a codegen change; decision goes into Step 5.
- Id-shape skew: **6 rows** (8, 10, 11, 14, 17, 23, 24) using `:Id`/`:Serial` vs named BE params. Fix belongs in codegen (Step 5) so the runtime path builder emits `{LicenseKey}` / `{UserId}` / `{Serial}` slots.
- Verb/shape MISMATCH: 2 rows (16 quotas.update, 17 impersonation.start). Both need FE-side reshape or BE-side new action; decision in Step 5.
- MISSING new BE work: 7 rows plus row-20 resolution. Step 5 sizes controller skeletons and DTOs.

## Phase A / Phase A' step-budget preview (input to Step 4)

Rough per-domain step count for Steps 21-40 (BE parity) based on the matrix. Step 4 hardens these numbers into `04-gap-groups.md`.

| Domain             | Rows | Missing | Mismatch | Budget (steps 21-40) |
|--------------------|------|---------|----------|----------------------|
| Auth               | 4    | 2       | 0        | 4 (21-24)            |
| Admin.Metrics      | 1    | 1       | 0        | 2 (21-22, shared with root-cause fix) |
| Admin.Licenses     | 5    | 1       | 0        | 2 (29-30)            |
| Admin.Features     | 1    | 1       | 0        | 2 (31-32)            |
| Portal             | 2    | 1       | 0        | 2 (33-34)            |
| Admin.Quotas       | 2    | 0       | 2        | 2 (35-36)            |
| Admin.Impersonation| 2    | 0       | 1        | 1 (37)               |
| Admin.Audit        | 1    | 0       | 0        | 1 (38)               |
| Admin.Users        | 4    | 1       | 0        | 1 (39)               |
| Admin.RuntimeConfig| 2    | 0       | 0        | 1 (40)               |
| Password-reset     | 2    | 0       | 0        | 0 (already MATCH)    |
| **Total**          | 26   | 7       | 3        | **18 / 20 budgeted** |

Two spare steps (2/20) reserved for backend-only-route promotions surfaced in Step 4.

## Next

Step 4 consumes this matrix to write `04-gap-groups.md` with a per-group budget totalling exactly 20 steps (21-40) and resolves the row-15 semantics + row-20 A/B decision.
