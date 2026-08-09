# Controller Skeleton Plan (Plan 18, Step 5)

Every MISSING/STUB row from `03-parity-matrix.md` plus every BE-only route promoted in `04-gap-groups.md` gets a concrete target below: file, action, request DTO, response resource, owning step. Sourced from actual directory listings of `backend/app/Http/Controllers/{Admin,Auth,Portal}/`, `backend/app/Http/Requests/{Admin,Auth}/`, and `backend/app/Http/Resources/`. New symbols are marked **NEW**; existing symbols reused where possible.

## Row-20 decision ratified

`admin.metrics.kpis` uses **Option A**: add `MetricsController@kpis` action. `MetricsController.php` exists (has `index` + `shardStatus`). FE contract unchanged.

## G1. Auth (Steps 21-24)

| Step | Route | Controller@action | Request DTO | Response Resource |
|------|-------|-------------------|-------------|-------------------|
| 21 | `POST /Api/Auth/Refresh` | `Auth\LoginController@refresh` (NEW action on existing file) | `Auth\RefreshRequest` (NEW) | `AuthSessionResource` (reuse) |
| 22 | `GET /Api/Auth/Me` | `Auth\LoginController@me` (NEW action) | none (uses auth guard) | `AuthMeResource` (NEW; wraps `UserResource` + roles + capabilities) |
| 23 | (DTO/Resource authoring) | - | finalize `RefreshRequest`, `AuthMeResource` | - |
| 24 | Route wiring in `backend/routes/api.php` under `auth` group with rate limits `rate.auth:refresh`, `rate.auth:me`. | - | - | - |

## G2. Admin.Metrics (Steps 25-26)

| Step | Route | Controller@action | Request DTO | Response Resource |
|------|-------|-------------------|-------------|-------------------|
| 25 | `GET /Api/Admin/Metrics/Kpis` | `Admin\MetricsController@kpis` (NEW action) | none | `KpisResource` (NEW; shape locked from FE Zod `AdminMetricsKpisResponse`) |
| 26 | Wire route + register `KpisResource` in `MetricsResource` sibling. | - | - | - |

Response shape (from FE Zod, to be confirmed in Step 25 against `src/generated/api/schemas.ts`):
```
{ Resellers: {Total, Active}, Sessions: {Active, LastHour},
  Licenses: {Issued, Active, ExpiringSoon}, Quota: {Pending, ApprovedThisWeek} }
```

## G3. Admin.Licenses (Steps 27-28)

| Step | Route | Controller@action | Request DTO | Response Resource |
|------|-------|-------------------|-------------|-------------------|
| 27 | `GET /Api/Admin/Licenses` | `Admin\LicenseController@index` (NEW action) | `LicenseIndexRequest` (NEW) with `Page`, `PerPage`, `Status`, `ResellerId`, `Search` | `LicenseResource::collection` (reuse) |
| 28 | FE codegen id-shape normalize `:Id` -> `{LicenseKey}` for show/update/delete. | (no BE) | - | - |

## G4. Admin.Features (Steps 29-30)

| Step | Route | Controller@action | Request DTO | Response Resource |
|------|-------|-------------------|-------------|-------------------|
| 29 | `GET /Api/Admin/Features` | `Admin\FeatureController@index` (NEW file `FeatureController.php`) | `FeatureIndexRequest` (NEW; empty body, just pagination) | `FeatureResource` (NEW; wraps FeatureCatalog rows per spec/21-app/12-feature-catalog.md) |
| 30 | Wire route, register resource, unit-test placeholder. | - | - | - |

## G5. Portal (Steps 31-32)

| Step | Route | Controller@action | Request DTO | Response Resource |
|------|-------|-------------------|-------------|-------------------|
| 31 | `GET /Api/Portal/Serials/{Serial}` | `Portal\SerialController@show` (NEW action on existing file) | `Portal\SerialLookupRequest` (NEW) - validates `Serial` path param and HMAC | `SerialResource` (reuse) |
| 32 | Add BE alias `GET /Api/Portal/UpdateManifest` -> existing `AppUpdateController@manifest`. Route-only change; no new controller. | - | - | - |

## G6. Admin.Quotas (Steps 33-34)

| Step | Route | Controller@action | Request DTO | Response Resource |
|------|-------|-------------------|-------------|-------------------|
| 33 | FE codegen split: `admin.quotas.update` -> `admin.quotas.approve` (`POST /Api/Admin/QuotaRequests/{RequestId}/Approve`) + `admin.quotas.deny` (`POST /.../Deny`). BE routes already exist on `QuotaRequestController`. | (reuse) `QuotaRequestApproveRequest`, `QuotaRequestDenyRequest` | `QuotaRequestResource` (reuse) |
| 34 | Add BE alias `GET /Api/Admin/Quotas` -> `QuotaRequestController@index` (keep FE OperationId stable). Also promote `admin.quotas.list-all` -> `GET /Api/Admin/QuotaRequests/All` (reuse `QuotaRequestController@indexAll`). | - | - |

## G7. Admin.Impersonation (Step 35)

| Step | Change | Controller@action | Request DTO | Response Resource |
|------|--------|-------------------|-------------|-------------------|
| 35 | Reshape FE `admin.impersonation.start` -> `POST /Api/Admin/Users/{UserId}/Impersonate` (BE `SessionController@beginImpersonation` already exists per Step 2 inventory). Rename FE `stop` path to `/impersonation/end`. Promote `admin.impersonation.force-end` -> `POST /Api/Admin/Impersonation/{SessionId}/ForceEnd`. | (reuse) `ImpersonateBeginRequest`, `ImpersonateEndRequest`, `ImpersonateForceEndRequest` | (reuse existing session resources) |

## G8. Admin.Audit (Step 36)

| Step | Change | Controller@action | Request DTO | Response Resource |
|------|--------|-------------------|-------------|-------------------|
| 36 | Add BE alias `GET /Api/Admin/Audit` -> `Admin\AuditController@index`. No new symbols. | (reuse) - | (reuse) `AuditEntryResource` |

## G9. Admin.Users (Steps 37-38)

| Step | Route | Controller@action | Request DTO | Response Resource |
|------|-------|-------------------|-------------|-------------------|
| 37 | `DELETE /Api/Admin/Users/{UserId}` | `Admin\UserController@destroy` (NEW action; soft-delete) | `UserDestroyRequest` (NEW; confirms audit reason) | 204 No Content |
| 38 | FE codegen id-shape normalize `:Id` -> `{UserId}`. Promote BE-only roles endpoints: `admin.users.roles.list/assign/revoke` (reuse `UserAssignRoleRequest`, existing `UserController` role actions per Step 2 inventory). | - | `UserResource` (reuse) |

## G10. Admin.RuntimeConfig (Step 39)

| Step | Change | - |
|------|--------|---|
| 39 | No new BE symbols. Parity-linter dry-run using the new path-case normalizer (spec in Step 15). Confirms zero drift. | - |

## G11. BE-only promotions absorbed (Step 40)

FE codegen adds (all point to already-IMPLEMENTED BE routes):
- `admin.licenses.ledger` -> `GET /Api/Admin/Licenses/{LicenseKey}/Ledger`
- `admin.licenses.bindings` -> `GET /Api/Admin/Licenses/{LicenseKey}/Bindings`
- `admin.licenses.bindings.release` -> `POST /Api/Admin/Bindings/{MachineBindingId}/Release`
- `admin.licenses.bindings.clear-cooldown` -> `POST /Api/Admin/Bindings/ClearCooldown`
- `admin.users.sessions.list` -> `GET /Api/Admin/Users/{UserId}/Sessions`
- `admin.sessions.terminate` -> `DELETE /Api/Admin/Sessions/{SessionId}`
- `admin.resellers.*` (list/show/create/update/delete) - verify against `ResellerController` in Step 40 before promoting each.
- `admin.prefixes.*` - reuse `PrefixController` + `PrefixStoreRequest`.
- `admin.app-updates.*` - reuse `AppUpdateController` + Publish/UploadTicket/Yank requests.
- `admin.backup.exports.*` and `admin.backup.imports.*` - reuse `BrExportController`, `BrImportController`.

All promotions in Step 40 are codegen-only (rows added to `src/generated/api/operations.ts` scaffolding input, preview fixtures stubbed in Phase D). Zero new PHP files.

## Summary counts

- NEW controllers: 1 (`Admin\FeatureController`).
- NEW controller actions on existing files: 4 (`LoginController@refresh`, `LoginController@me`, `MetricsController@kpis`, `LicenseController@index`, `SerialController@show`, `UserController@destroy`) - 6 total.
- NEW request DTOs: 5 (`RefreshRequest`, `LicenseIndexRequest`, `FeatureIndexRequest`, `SerialLookupRequest`, `UserDestroyRequest`).
- NEW response resources: 3 (`AuthMeResource`, `KpisResource`, `FeatureResource`).
- NEW BE-alias routes only: 3 (`/Api/Admin/Audit`, `/Api/Admin/Quotas`, `/Api/Portal/UpdateManifest`).

## Feeds forward

- Step 6 (seeder-coverage) references each response resource above to compute the DB rows needed for a non-empty happy path.
- Step 9 (error-manage contract) references the new controllers as call sites needing consistent `operationId`/`requestId` correlation headers.
- Step 13 (Pest test plan) references each new action for feature-test scaffolds.
- Step 15 (parity linter) uses the normalized id-shape (`{LicenseKey}`, `{UserId}`, `{RequestId}`, `{SessionId}`, `{MachineBindingId}`, `{Serial}`) as the canonical id-slot form.
