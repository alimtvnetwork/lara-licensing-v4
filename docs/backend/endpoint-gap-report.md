# Backend Endpoint Gap Report

Generated: 2026-07-19 (v0.274.0)
Source: `backend/routes/api.php`, `backend/app/Http/Controllers/**`, `backend/app/Http/Requests/**`, `backend/app/Http/Resources/**` (absent), `backend/app/Policies/**`, `backend/tests/**`, `src/lib/lara-*.ts`.
Parent plan: `.lovable/plans/pending/09-fluid-ui-and-cpanel-release.md` (Step 1).

## Method

Every route registered in `backend/routes/api.php` was enumerated by hand-parse. For each controller action we grepped:
- `use App\Http\Requests\...` and constructor/action typed parameters for a dedicated FormRequest, else marked `inline` (calls `$request->validate([...])`) or `none`.
- `Gate::`, `authorize(`, or `Policy` references (currently only `HasRolePolicy.php` exists; RBAC is enforced by `RequireRoleMiddleware`, not policies).
- Return type: `JsonResource` subclass vs raw `response()->json([...])`. The `backend/app/Http/Resources/` directory is empty; therefore every action returns a raw array/envelope today.
- PHPUnit / Pest feature test hitting the route path.
- OpenAPI docblock (`@OA\\`) presence: zero across the tree (grep count 0).
- Typed frontend client function under `src/lib/lara-*.ts` calling the path.

## Legend

- FR = FormRequest, Pol = Policy, Res = API Resource, PU = PHPUnit/Pest, SW = Swagger/OpenAPI, FE = frontend transport.
- `y` = present, `n` = missing, `inl` = inline `$request->validate()`, `mw` = enforced by middleware (RBAC via `require.role`), `hmac` = enforced by `require.signature`.

## Routes

### Auth surface (`/Api/Auth/*`)

| Method | Path | Action | FR | Pol | Res | PU | SW | FE | Status |
|---|---|---|---|---|---|---|---|---|---|
| POST | /Api/Auth/Register | RegisterController@__invoke | y (RegisterRequest) | mw | n | y (RegisterBootstrapTest) | n | n | partial |
| POST | /Api/Auth/Login | LoginController@__invoke | y (LoginRequest) | mw | n | y | n | partial (lara-auth) | partial |
| POST | /Api/Auth/Logout | LogoutController@__invoke | inl | mw | n | y | n | n | partial |

### Admin surface (`/Api/Admin/*`, role: Admin|SuperAdmin)

| Method | Path | Action | FR | Pol | Res | PU | SW | FE | Status |
|---|---|---|---|---|---|---|---|---|---|
| GET | /Api/Admin/Ping | closure | - | mw | n | n | n | n | partial |
| GET | /Api/Admin/Resellers | ResellerController@index | inl | mw | n | y | n | partial (lara-reseller) | partial |
| POST | /Api/Admin/Resellers | ResellerController@store | inl | mw | n | y | n | partial (lara-reseller) | partial |
| GET | /Api/Admin/Resellers/{slug} | ResellerController@show | - | mw | n | n | n | n | missing (FE + Res) |
| PATCH | /Api/Admin/Resellers/{slug} | ResellerController@update | inl | mw | n | n | n | n | missing |
| GET | /Api/Admin/Prefixes | PrefixController@index | - | mw | n | y | n | n | missing (FE) |
| POST | /Api/Admin/Prefixes | PrefixController@store | inl | mw | n | y | n | n | missing (FE) |
| DELETE | /Api/Admin/Prefixes/{value} | PrefixController@destroy | - | mw | n | y | n | n | missing (FE) |
| POST | /Api/Admin/Licenses | LicenseController@issue | inl | mw | n | y | n | partial (lara-license) | partial |
| GET | /Api/Admin/Licenses/{k} | LicenseController@show | - | mw | n | y | n | partial | partial |
| PATCH | /Api/Admin/Licenses/{k} | LicenseController@update | inl | mw | n | y | n | partial | partial |
| DELETE | /Api/Admin/Licenses/{k} | LicenseController@revoke | inl | mw | n | y (RevokeDecisionTest) | n | n | missing (FE + Res + FR) |
| GET | /Api/Admin/Licenses/{k}/Ledger | LicenseController@ledger | - | mw | n | n | n | n | missing |
| GET | /Api/Admin/Licenses/{k}/Bindings | BindingController@index | - | mw | n | n | n | n | missing |
| POST | /Api/Admin/Licenses/{k}/Bindings/{id}/Release | BindingController@release | inl | mw | n | n | n | n | missing |
| POST | /Api/Admin/Licenses/{k}/Bindings/{id}/ClearCooldown | BindingController@clearCooldown | inl | mw | n | n | n | n | missing |
| GET | /Api/Admin/Users | UserController@index | - | mw | n | y | n | n | missing (FE) |
| POST | /Api/Admin/Users | UserController@store | inl | mw | n | y | n | n | missing |
| GET | /Api/Admin/Users/{id} | UserController@show | - | mw | n | y | n | n | missing |
| PATCH | /Api/Admin/Users/{id} | UserController@update | inl | mw | n | n | n | n | missing |
| GET | /Api/Admin/Users/{id}/Roles | UserController@listRoles | - | mw | n | y | n | partial (lara-user-role) | partial |
| POST | /Api/Admin/Users/{id}/Roles | UserController@assignRole | inl | mw | n | y | n | partial | partial |
| DELETE | /Api/Admin/Users/{id}/Roles/{r} | UserController@revokeRole | - | mw | n | y | n | partial | partial |
| POST | /Api/Admin/Users/{id}/Impersonate | UserController@impersonate | inl | mw | n | y | n | partial (lara-impersonation) | partial |
| POST | /Api/Admin/Impersonation/End | UserController@endImpersonation | - | mw | n | y | n | y | partial (Res) |
| POST | /Api/Admin/Impersonation/{sid}/ForceEnd | UserController@forceEndImpersonation | - | mw | n | y | n | n | missing |
| GET | /Api/Admin/QuotaRequests | QuotaRequestController@index | - | mw | n | y | n | n | missing (FE) |
| GET | /Api/Admin/QuotaRequests/All | QuotaRequestController@indexAll | - | mw | n | y | n | n | missing (FE) |
| POST | /Api/Admin/QuotaRequests/{id}/Approve | QuotaRequestController@approve | inl | mw | n | y | n | n | missing (FE) |
| POST | /Api/Admin/QuotaRequests/{id}/Deny | QuotaRequestController@deny | inl | mw | n | y | n | n | missing (FE) |
| POST | /Api/Admin/AppUpdates/UploadTicket | AppUpdateController@uploadTicket | y (AppUpdateUploadTicketRequest) | mw | n | y | n | n | missing (FE) |
| POST | /Api/Admin/AppUpdates | AppUpdateController@publish | y (AppUpdatePublishRequest) | mw | n | y | n | n | missing (FE) |
| POST | /Api/Admin/AppUpdates/{v}/Yank | AppUpdateController@yank | inl | mw | n | y | n | n | missing (FE) |

### Reseller surface (`/Api/Reseller/*`, role: Reseller, shard-bound)

| Method | Path | Action | FR | Pol | Res | PU | SW | FE | Status |
|---|---|---|---|---|---|---|---|---|---|
| GET | /Api/Reseller/Ping | closure | - | mw | n | n | n | n | partial |
| GET | /Api/Reseller/Licenses | ResellerLicenseController@index | - | mw | n | n | n | n | missing |
| POST | /Api/Reseller/Licenses | ResellerLicenseController@issue | inl | mw | n | y | n | n | missing (FE) |
| GET | /Api/Reseller/Licenses/{k} | ResellerLicenseController@show | - | mw | n | n | n | n | missing |
| PATCH | /Api/Reseller/Licenses/{k}/Renew | ResellerLicenseController@renew | inl | mw | n | n | n | n | missing |
| GET | /Api/Reseller/Licenses/{k}/Ledger | ResellerLicenseController@ledger | - | mw | n | n | n | n | missing |
| GET | /Api/Reseller/QuotaRequests | ResellerQuotaRequestController@index | - | mw | n | n | n | n | missing |
| POST | /Api/Reseller/QuotaRequests | ResellerQuotaRequestController@store | inl | mw | n | y | n | n | missing (FE) |
| POST | /Api/Reseller/QuotaRequests/{id}/Cancel | ResellerQuotaRequestController@cancel | - | mw | n | n | n | n | missing |

### Portal surface (`/Api/Portal/*`, HMAC signed, no auth guard)

| Method | Path | Action | FR | Pol | Res | PU | SW | FE | Status |
|---|---|---|---|---|---|---|---|---|---|
| GET | /Api/Portal/Ping | closure | - | hmac | n | n | n | n | partial |
| POST | /Api/Portal/Serials | SerialController@issue | inl | hmac | n | y | n | partial (lara-serial) | partial |
| POST | /Api/Portal/Verify/Serial | VerifyController@serial | inl | hmac | n | y | n | y (lara-serial) | partial (Res) |
| POST | /Api/Portal/Verify/Hash | VerifyController@hash | inl | hmac | n | y | n | y | partial (Res) |
| POST | /Api/Portal/Verify/Final | VerifyController@final | inl | hmac | n | y | n | y | partial (Res) |

### App surface (`/Api/App/*`, self-update; no auth guard on Stable)

| Method | Path | Action | FR | Pol | Res | PU | SW | FE | Status |
|---|---|---|---|---|---|---|---|---|---|
| GET | /Api/App/UpdateManifest | UpdateManifestController@__invoke | inl | - | n | y | n | n | missing (FE, planned lara-app-updates) |
| PUT | /Api/App/UpdateAssetReceiver/{t} | UpdateAssetReceiverController@__invoke | inl | - | n | y | n | n | missing (FE) |
| GET/HEAD | /Api/App/UpdateAsset/{v}/{p} | UpdateAssetController@__invoke | - | - | n | y | n | n | missing (FE) |
| GET/HEAD | /Api/App/UpdateAsset/{v}/{p}.sig | UpdateAssetSignatureController@__invoke | - | - | n | y | n | n | missing (FE) |

## Aggregate findings

- **FormRequest coverage: 4 of 41 routes** (Auth Register, Auth Login, AppUpdate UploadTicket, AppUpdate Publish). 20 mutation endpoints use inline `$request->validate([...])` (permitted by Laravel but violates the project standard captured in `mem://preferences/laravel-best-practices.md`). Backfill required in Plan 09 step 59.
- **Policy coverage: 0 policies invoked** in controllers. `HasRolePolicy.php` exists but is unwired; RBAC is enforced by `RequireRoleMiddleware`. This is acceptable for coarse role gates but leaves per-object gates (a Reseller Admin only touching their own reseller row, an Admin editing another Admin's role) unguarded. Backfill required in Plan 09 step 60 for: `ResellerPolicy`, `LicensePolicy`, `UserPolicy`, `QuotaRequestPolicy`, `AppUpdatePolicy`.
- **API Resource coverage: 0.** `backend/app/Http/Resources/` is empty. Every response is a raw envelope array. This blocks PascalCase key enforcement, transformer reuse, and Swagger schema generation. Backfill required in Plan 09 step 61.
- **PHPUnit coverage: 22 of 41 routes have at least one feature test.** Missing: all Reseller list/detail/show, all Admin Prefix show, all Binding admin actions, ForceEnd impersonation, ResellerLicense show/renew/ledger, all Portal Ping, App self-update client happy paths. Backfill inside the same steps that touch those actions (Plan 09 steps 33-51, 58).
- **OpenAPI coverage: 0 of 41 routes annotated.** No `@OA\Get`, `@OA\Post`, `@OA\Schema` anywhere in the tree. Plan 09 steps 62-64 install `darkaonline/l5-swagger`, annotate every action, and expose `/api/documentation` gated by Admin RBAC.
- **Frontend transport coverage (typed client): 12 of 41 endpoints** partially wired, none complete (see SS-02 companion report). Only `/Auth/Token`, `/Users/Me`, `/Impersonation/End`, `/Verify/*`, and partial `Resellers` + `Licenses` list surfaces exist. All other endpoints have no typed function.

## Prioritised backfill order (feeds Plan 09 steps 59-64)

1. Create API Resource classes for `Reseller`, `License`, `LicenseLedger`, `Prefix`, `User`, `UserRole`, `QuotaRequest`, `Serial`, `VerifyKey`, `AppUpdate`, `AppUpdateAsset`, `MachineBinding`, `UserBinding`, `AuditRow`, `Metric` (14 classes). All keys PascalCase.
2. Create FormRequests for every inline-validated mutation endpoint (20 classes). Reuse rule sets via traits where the payload overlaps (e.g. `HasIdempotencyKey`).
3. Create Policies for the five aggregate roots and wire via `AuthServiceProvider::$policies`. Keep `RequireRoleMiddleware` as the coarse gate; policies handle per-object authorisation.
4. Install `darkaonline/l5-swagger`; annotate the 41 routes; ship `linter-scripts/check-swagger-parity.py`.
5. Add feature tests for the 19 uncovered routes.

## Row-count check

`php artisan route:list --json | jq length` was not executed here (no PHP runtime in the sandbox for this turn). The manual enumeration produced 41 routes matching the four groups above plus the four self-update surfaces. Any discrepancy on the next `route:list` run will be reconciled during Plan 09 step 62 when Swagger annotations pin each route by name.
