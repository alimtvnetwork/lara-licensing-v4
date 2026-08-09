# Gap Groups + Step Budget (Plan 18, Step 4)

Consumes `03-parity-matrix.md`. Groups every parity finding by domain, hardens the per-domain step budget for Phase A' (Steps 21-40), and resolves the two open questions Step 3 flagged (row-15 semantics, row-20 A/B decision precondition).

## Ambiguity resolutions

### Row 15 - `admin.quotas.list` vs `admin.quotas.update`

Read `src/generated/api/operations.ts` context: adjacent to `admin.impersonation.*`, `admin.audit.list`, `admin.users.*`. There is no separate `quotaRequests` OperationId. Backend surface only exposes quota-**request** workflow (`/Api/Admin/QuotaRequests`, `/Approve`, `/Deny`), not quota **usage counters**. Therefore FE `admin.quotas.*` semantically maps to BE `QuotaRequests.*`. This is a **resource-name rename**, not two different resources. Reclassify rows 15 and 16 as MATCH-RENAME + MISMATCH (verb split remains a real problem on row 16).

Decision: keep single domain group **Admin.Quotas** covering rows 15+16. Fix strategy in Step 5 will either (a) split FE `admin.quotas.update` into `admin.quotas.approve` + `admin.quotas.deny` (matches BE), or (b) add a single BE `PATCH /QuotaRequests/{RequestId}` that dispatches on `Decision` field. Preference: option (a), because BE two-verb split is already the audit-trail-friendly shape.

### Row 20 - `admin.metrics.kpis` A/B fix decision precondition

Step 5 requires this decision documented before controller-skeleton work. Preference recorded here for Step 5 to ratify: **Option A (BE add `MetricsController@kpis`)**. Rationale:

- FE `admin.metrics.kpis` is already wired into `<AdminOverview />` and consumed by preview fixtures; changing the FE contract propagates through generated types, Zod shapes, preview fixtures, and 4+ test files.
- BE `MetricsController` already exists (`backend/app/Http/Controllers/Admin/MetricsController.php`) with `index` + `shardStatus` actions. Adding a third `kpis` action is a localized change with no schema impact.
- Preserves the KPI-tile contract shape which is the exact thing the red overview is trying to render.

Step 5 records the decision formally; this file records the recommendation with reasoning so Step 5 does not re-litigate.

## Domain groups

Each group lists: matrix rows, gap type breakdown, step-range assignment (within Steps 21-40), and backend-only routes attributed to the group (candidates for FE promotion, decision deferred to Step 5).

### G1. Auth (Steps 21-24, budget 4)

- Matrix rows: 1 (login), 2 (refresh, MISSING), 3 (logout), 4 (me, MISSING), 5 (password-reset.request), 6 (password-reset.confirm).
- Gap: 2 MISSING (refresh, me), 4 MATCH-RENAME (path-case + segment rename).
- Backend-only routes attributed: `POST /Api/Auth/Register`, `GET /Api/Auth/Captcha`. Neither has FE demand today; park as BE-internal (register is bootstrap-only, captcha is server-rendered).
- Step 21: implement `POST /Api/Auth/Refresh` in `LoginController` or new `RefreshController` returning refreshed session envelope.
- Step 22: implement `GET /Api/Auth/Me` returning current session claim (user, roles, tenant scope, capabilities).
- Step 23: add request DTOs (`AuthRefreshRequest`, `AuthMeRequest is trivial`) and response resources matching FE Zod shape.
- Step 24: wire routes in `backend/routes/api.php`; add rate limits (`rate.auth:refresh`, `rate.auth:me`).

### G2. Admin.Metrics (Steps 25-26, budget 2)

- Matrix rows: 20 (kpis, MISSING - RED OVERVIEW ROOT CAUSE).
- Backend-only routes attributed: `GET /Api/Admin/Metrics` (index), `GET /Api/Admin/Metrics/ShardStatus`. Both remain BE endpoints, no FE promotion needed (ShardStatus is a diagnostic surface consumed by admin ping).
- Step 25: implement `MetricsController@kpis` returning `{ Resellers: {Total, Active}, Sessions: {Active, LastHour}, Licenses: {Issued, Active, ExpiringSoon}, Quota: {Pending, ApprovedThisWeek} }` (shape confirmed from FE Zod in Step 5).
- Step 26: wire `GET /Api/Admin/Metrics/Kpis`, add DTO/resource, backfill unit test placeholder for Step 13's Pest plan.

### G3. Admin.Licenses (Steps 27-28, budget 2)

- Matrix rows: 7 (list, MISSING), 8-11 (show/create/update/delete, all MATCH with id-shape skew).
- Backend-only routes attributed: `GET /Licenses/{LicenseKey}/Ledger`, `GET /Licenses/{LicenseKey}/Bindings`, `POST /Bindings/{MachineBindingId}/Release`, `POST /ClearCooldown`. Promote to FE OperationIds in Phase D (preview-fixtures Step 61-80) only if admin UI surfaces them; Step 10 (preview-fixture plan) decides.
- Step 27: implement `Admin\LicenseController@index` (cross-tenant list; `LicenseController` currently has `issue/show/update/destroy/ledger` but no `index` per Step 2 inventory). Add pagination + filter DTO.
- Step 28: normalize id-shape: FE codegen emits `{LicenseKey}` slot for rows 8/10/11. Confirm no runtime breakage on existing consumers (`admin.licenses.show/update/delete`).

### G4. Admin.Features (Steps 29-30, budget 2)

- Matrix rows: 12 (list, MISSING).
- Backend-only routes attributed: none in current inventory. `FeatureCatalog` seed exists but no HTTP surface.
- Step 29: implement `Admin\FeatureController@index` returning the FeatureCatalog rows (spec/21-app/12-feature-catalog.md).
- Step 30: wire `GET /Api/Admin/Features`, DTO, resource.

### G5. Portal (Steps 31-32, budget 2)

- Matrix rows: 13 (updates.manifest, MATCH-RENAME), 14 (serials.lookup, MISSING).
- Backend-only routes attributed: `POST /Api/Portal/Serials` (issue), `POST /Verify/Serial`, `POST /Verify/Hash`, `POST /Verify/Final`, `GET /App/UpdateManifest`, `GET/HEAD /App/UpdateAsset/*`, `PUT /App/UpdateAssetReceiver/*`, `GET/HEAD /App/UpdateAsset/*.sig`. These are consumed by the desktop client, not the FE web admin; keep BE-internal.
- Step 31: implement `GET /Api/Portal/Serials/{Serial}` lookup (idempotent read). Portal HMAC middleware already covers.
- Step 32: normalize path segment: FE `portal.updates.manifest` currently points at `/api/portal/updates`; BE canonical is `/App/UpdateManifest` (not `/Api/Portal/*`). Step 5 decides: alias BE route at `/Api/Portal/UpdateManifest` OR repoint FE. Preference: alias (BE), because desktop client already relies on `/App/UpdateManifest`.

### G6. Admin.Quotas (Steps 33-34, budget 2)

- Matrix rows: 15 (list, MATCH-RENAME to `QuotaRequests`), 16 (update, MISMATCH - 2 BE verbs vs 1 FE PATCH).
- Backend-only routes attributed: `GET /QuotaRequests/All` (cross-tenant fanout inbox). Promote to FE `admin.quotas.list-all` in Step 5 (needed for spec-18 AC-05 dashboard).
- Step 33: split FE `admin.quotas.update` into `admin.quotas.approve` + `admin.quotas.deny` in `src/generated/api/operations.ts` scaffolding (codegen input change).
- Step 34: no BE work needed on this pair beyond documenting rename in `03-parity-matrix.md`. Add BE alias endpoint `GET /Api/Admin/Quotas` -> `QuotaRequests@index` if Step 5 chooses to preserve FE naming; otherwise FE-side rename only.

### G7. Admin.Impersonation (Step 35, budget 1)

- Matrix rows: 17 (start, MISMATCH), 18 (stop, MATCH-RENAME).
- Backend-only routes attributed: `POST /Api/Admin/Impersonation/{SessionId}/ForceEnd`. Promote to FE `admin.impersonation.force-end` in Step 5 (admin needs to terminate stuck sessions).
- Step 35: reshape FE `admin.impersonation.start` from `POST /impersonation/start` (body: `{UserId}`) to `POST /Api/Admin/Users/{UserId}/Impersonate` (path param, no body). Rename `admin.impersonation.stop` FE path to `/impersonation/end` (match BE). Add `admin.impersonation.force-end`.

### G8. Admin.Audit (Step 36, budget 1)

- Matrix rows: 19 (list, MATCH-RENAME to `/AuditLogs`).
- Backend-only routes attributed: none.
- Step 36: alias BE route or repoint FE to `/AuditLogs`. Preference: alias BE `GET /Api/Admin/Audit` -> `AuditController@index` so FE OperationId stays stable.

### G9. Admin.Users (Steps 37-38, budget 2)

- Matrix rows: 21 (list, MATCH), 22 (create, MATCH), 23 (update, MATCH id-shape skew), 24 (delete, MISSING).
- Backend-only routes attributed: `GET/POST /Users/{UserId}/Roles`, `DELETE /Users/{UserId}/Roles/{RoleName}`, `GET /Users/{UserId}/Sessions`, `DELETE /Sessions/{SessionId}`. Promote first three to FE in Step 5 (role management UI). Sessions promote later (Phase D preview-fixtures) if surfaced.
- Step 37: implement `Admin\UserController@destroy` (soft-delete, role-preserving audit trail). Policy question: does spec-18 require hard vs soft delete? Decision recorded here as **soft-delete** with `DeletedAt` column read via a scope; hard-delete would break audit correlation with impersonation history.
- Step 38: FE codegen id-shape normalize (`:Id` -> `{UserId}`).

### G10. Admin.RuntimeConfig (Step 39, budget 1)

- Matrix rows: 25 (show, MATCH), 26 (update, MATCH).
- Backend-only routes attributed: none.
- Step 39: no BE code changes needed. Reserve step for parity linter dry-run + confirmation that path-case skew is closed by codegen change from G3/G9 (single normalizer serves all admin domains).

### G11. Spare (Step 40, budget 1)

- Step 40: absorb any BE-only route promotions surfaced by Steps 21-39 (e.g., `admin.licenses.ledger`, `admin.licenses.bindings`, `admin.users.roles.*`, `admin.quotas.list-all`, `admin.impersonation.force-end`, `admin.sessions.*`, `admin.resellers.*`, `admin.prefixes.*`, `admin.app-updates.*`, `admin.backup.*`). Each promotion is a codegen row + preview-fixture stub only; the BE routes already exist and are IMPLEMENTED. Step 5 lists the exact rows added to `operations.ts`.

## Per-domain budget confirmation (totals to 20 steps 21-40)

| Group                | Steps       | Count |
|----------------------|-------------|-------|
| G1 Auth              | 21-24       | 4     |
| G2 Admin.Metrics     | 25-26       | 2     |
| G3 Admin.Licenses    | 27-28       | 2     |
| G4 Admin.Features    | 29-30       | 2     |
| G5 Portal            | 31-32       | 2     |
| G6 Admin.Quotas      | 33-34       | 2     |
| G7 Admin.Impersonation | 35        | 1     |
| G8 Admin.Audit       | 36          | 1     |
| G9 Admin.Users       | 37-38       | 2     |
| G10 Admin.RuntimeConfig | 39       | 1     |
| G11 Spare (BE-only promotions) | 40 | 1  |
| **Total**            | **21-40**   | **20**|

## Backend-only routes not promoted (BE-internal, stay off the FE surface)

- `POST /Api/Auth/Register` (bootstrap-only, closes after first SuperAdmin).
- `GET /Api/Auth/Captcha` (server-rendered image).
- `GET /Api/Public/Health` (load balancer probe).
- Portal HMAC surface: `POST /Api/Portal/Serials`, `POST /Verify/{Serial,Hash,Final}` (consumed only by desktop client).
- App self-update byte-serving: `GET/HEAD /App/UpdateAsset/*` and `.sig` (client-only).
- `PUT /App/UpdateAssetReceiver/{UploadToken}` (single-use bearer token upload; FE surfaces the ticket, not this endpoint).
- Reseller surface: entire `/Api/Reseller/*` group. Reseller role has its own portal, not the admin FE.

## Feeds forward

- Step 5 opens each promoted controller file, names request DTOs and response resources per group, and locks the row-20 decision (Option A).
- Step 6 consumes group G1-G11 to size the seeder-coverage plan.
- Step 15 (linter plan) records the path-case normalizer specification derived from G3/G9.
