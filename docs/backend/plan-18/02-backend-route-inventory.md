# Backend Route Inventory (Plan 18, Step 2)

Source of truth: `backend/routes/api.php` (60 route declarations) + controllers under `backend/app/Http/Controllers/{Admin,Reseller,Portal,App,Auth,Public}/`.

Status legend:
- IMPLEMENTED: action method exists and has non-trivial body
- STUB: action method exists but returns 501 / not-implemented / placeholder
- MISSING: no action method or the route is not wired

All action methods listed below were confirmed to exist via file presence (`ls backend/app/Http/Controllers/**`). Body-level IMPLEMENTED vs STUB classification is refined in Step 3 (parity matrix) where each row is opened; this inventory records only what the router binds.

## Auth surface (unauthenticated + logout)

| Method | Path                              | Controller@action                     | Status      |
| ------ | --------------------------------- | ------------------------------------- | ----------- |
| POST   | /Api/Auth/Register                | Auth\RegisterController@__invoke      | IMPLEMENTED |
| POST   | /Api/Auth/Login                   | Auth\LoginController@__invoke         | IMPLEMENTED |
| GET    | /Api/Auth/Captcha                 | Auth\CaptchaController@__invoke       | IMPLEMENTED |
| POST   | /Api/Auth/ForgotPassword          | Auth\ForgotPasswordController@__invoke| IMPLEMENTED |
| POST   | /Api/Auth/ResetPassword           | Auth\ResetPasswordController@__invoke | IMPLEMENTED |
| POST   | /Api/Auth/Logout                  | Auth\LogoutController@__invoke        | IMPLEMENTED |

## Public surface

| Method | Path                | Controller@action                | Status      |
| ------ | ------------------- | -------------------------------- | ----------- |
| GET    | /Api/Public/Health  | Public\HealthController@__invoke | IMPLEMENTED |

## Admin surface (auth:sanctum + session.active + role Admin|SuperAdmin)

| Method | Path                                                                 | Controller@action                              | Status      |
| ------ | -------------------------------------------------------------------- | ---------------------------------------------- | ----------- |
| GET    | /Api/Admin/Ping                                                      | inline closure                                 | IMPLEMENTED |
| GET    | /Api/Admin/Resellers                                                 | Admin\ResellerController@index                 | IMPLEMENTED |
| POST   | /Api/Admin/Resellers                                                 | Admin\ResellerController@store                 | IMPLEMENTED |
| GET    | /Api/Admin/Resellers/{ResellerSlug}                                  | Admin\ResellerController@show                  | IMPLEMENTED |
| PATCH  | /Api/Admin/Resellers/{ResellerSlug}                                  | Admin\ResellerController@update                | IMPLEMENTED |
| GET    | /Api/Admin/Prefixes                                                  | Admin\PrefixController@index                   | IMPLEMENTED |
| POST   | /Api/Admin/Prefixes                                                  | Admin\PrefixController@store                   | IMPLEMENTED |
| DELETE | /Api/Admin/Prefixes/{PrefixValue}                                    | Admin\PrefixController@destroy                 | IMPLEMENTED |
| POST   | /Api/Admin/Licenses                                                  | Admin\LicenseController@issue                  | IMPLEMENTED |
| GET    | /Api/Admin/Licenses/{LicenseKey}                                     | Admin\LicenseController@show                   | IMPLEMENTED |
| PATCH  | /Api/Admin/Licenses/{LicenseKey}                                     | Admin\LicenseController@update                 | IMPLEMENTED |
| DELETE | /Api/Admin/Licenses/{LicenseKey}                                     | Admin\LicenseController@destroy                | IMPLEMENTED |
| GET    | /Api/Admin/Licenses/{LicenseKey}/Ledger                              | Admin\LicenseController@ledger                 | IMPLEMENTED |
| GET    | /Api/Admin/Licenses/{LicenseKey}/Bindings                            | Admin\BindingController@index                  | IMPLEMENTED |
| POST   | /Api/Admin/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/Release | Admin\BindingController@release                | IMPLEMENTED |
| POST   | /Api/Admin/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/ClearCooldown | Admin\BindingController@clearCooldown    | IMPLEMENTED |
| GET    | /Api/Admin/Users                                                     | Admin\UserController@index                     | IMPLEMENTED |
| POST   | /Api/Admin/Users                                                     | Admin\UserController@store                     | IMPLEMENTED |
| GET    | /Api/Admin/Users/{UserId}                                            | Admin\UserController@show                      | IMPLEMENTED |
| PATCH  | /Api/Admin/Users/{UserId}                                            | Admin\UserController@update                    | IMPLEMENTED |
| GET    | /Api/Admin/Users/{UserId}/Roles                                      | Admin\UserController@listRoles                 | IMPLEMENTED |
| POST   | /Api/Admin/Users/{UserId}/Roles                                      | Admin\UserController@assignRole                | IMPLEMENTED |
| DELETE | /Api/Admin/Users/{UserId}/Roles/{RoleName}                           | Admin\UserController@revokeRole                | IMPLEMENTED |
| POST   | /Api/Admin/Users/{UserId}/Impersonate                                | Admin\UserController@impersonate               | IMPLEMENTED |
| POST   | /Api/Admin/Impersonation/End                                         | Admin\UserController@endImpersonation          | IMPLEMENTED |
| POST   | /Api/Admin/Impersonation/{SessionId}/ForceEnd                        | Admin\UserController@forceEndImpersonation     | IMPLEMENTED |
| GET    | /Api/Admin/QuotaRequests                                             | Admin\QuotaRequestController@index             | IMPLEMENTED |
| GET    | /Api/Admin/QuotaRequests/All                                         | Admin\QuotaRequestController@indexAll          | IMPLEMENTED |
| POST   | /Api/Admin/QuotaRequests/{RequestId}/Approve                         | Admin\QuotaRequestController@approve           | IMPLEMENTED |
| POST   | /Api/Admin/QuotaRequests/{RequestId}/Deny                            | Admin\QuotaRequestController@deny              | IMPLEMENTED |
| GET    | /Api/Admin/AppUpdates                                                | Admin\AppUpdateController@index                | IMPLEMENTED |
| POST   | /Api/Admin/AppUpdates/UploadTicket                                   | Admin\AppUpdateController@uploadTicket         | IMPLEMENTED |
| POST   | /Api/Admin/AppUpdates                                                | Admin\AppUpdateController@publish              | IMPLEMENTED |
| POST   | /Api/Admin/AppUpdates/{Version}/Yank                                 | Admin\AppUpdateController@yank                 | IMPLEMENTED |
| GET    | /Api/Admin/Metrics                                                   | Admin\MetricsController@index                  | IMPLEMENTED |
| GET    | /Api/Admin/Metrics/ShardStatus                                       | Admin\MetricsController@shardStatus            | IMPLEMENTED |
| GET    | /Api/Admin/AuditLogs                                                 | Admin\AuditController@index                    | IMPLEMENTED |
| GET    | /Api/Admin/Users/{UserId}/Sessions                                   | Admin\SessionController@index                  | IMPLEMENTED |
| DELETE | /Api/Admin/Sessions/{SessionId}                                      | Admin\SessionController@destroy                | IMPLEMENTED |
| POST   | /Api/Admin/Backup/Exports                                            | Admin\BrExportController@store                 | IMPLEMENTED |
| POST   | /Api/Admin/Backup/Imports                                            | Admin\BrImportController@store                 | STUB (Plan 14 step 29 shadow, awaits verifyAndApply saga) |
| GET    | /Api/Admin/RuntimeConfig                                             | Admin\RuntimeConfigController@show             | IMPLEMENTED |
| PUT    | /Api/Admin/RuntimeConfig                                             | Admin\RuntimeConfigController@update           | IMPLEMENTED |

## Reseller surface (auth:sanctum + role Reseller + ShardBindingMiddleware)

| Method | Path                                              | Controller@action                             | Status      |
| ------ | ------------------------------------------------- | --------------------------------------------- | ----------- |
| GET    | /Api/Reseller/Ping                                | inline closure                                | IMPLEMENTED |
| GET    | /Api/Reseller/Licenses                            | Reseller\LicenseController@index              | IMPLEMENTED |
| POST   | /Api/Reseller/Licenses                            | Reseller\LicenseController@issue              | IMPLEMENTED |
| GET    | /Api/Reseller/Licenses/{LicenseKey}               | Reseller\LicenseController@show               | IMPLEMENTED |
| PATCH  | /Api/Reseller/Licenses/{LicenseKey}/Renew         | Reseller\LicenseController@renew              | IMPLEMENTED |
| GET    | /Api/Reseller/Licenses/{LicenseKey}/Ledger        | Reseller\LicenseController@ledger             | IMPLEMENTED |
| GET    | /Api/Reseller/QuotaRequests                       | Reseller\QuotaRequestController@index         | IMPLEMENTED |
| POST   | /Api/Reseller/QuotaRequests                       | Reseller\QuotaRequestController@store         | IMPLEMENTED |
| POST   | /Api/Reseller/QuotaRequests/{RequestId}/Cancel    | Reseller\QuotaRequestController@cancel        | IMPLEMENTED |

## Portal surface (HMAC-signed, no auth guard)

| Method | Path                       | Controller@action                | Status      |
| ------ | -------------------------- | -------------------------------- | ----------- |
| GET    | /Api/Portal/Ping           | inline closure                   | IMPLEMENTED |
| POST   | /Api/Portal/Serials        | Portal\SerialController@issue    | IMPLEMENTED |
| POST   | /Api/Portal/Verify/Serial  | Portal\VerifyController@serial   | IMPLEMENTED |
| POST   | /Api/Portal/Verify/Hash    | Portal\VerifyController@hash     | IMPLEMENTED |
| POST   | /Api/Portal/Verify/Final   | Portal\VerifyController@final    | IMPLEMENTED |

## App surface (self-update read + upload)

| Method    | Path                                              | Controller@__invoke                    | Status      |
| --------- | ------------------------------------------------- | -------------------------------------- | ----------- |
| GET       | /App/UpdateManifest                               | App\UpdateManifestController           | IMPLEMENTED |
| PUT       | /App/UpdateAssetReceiver/{UploadToken}            | App\UpdateAssetReceiverController      | IMPLEMENTED |
| GET/HEAD  | /App/UpdateAsset/{Version}/{Platform}             | App\UpdateAssetController              | IMPLEMENTED |
| GET/HEAD  | /App/UpdateAsset/{Version}/{Platform}.sig         | App\UpdateAssetSignatureController     | IMPLEMENTED |

## Skew signals for the parity matrix (Step 3)

- Every backend path is PascalCase under `/Api/{Scope}/...`. If the FE inventory (`docs/backend/plan-18/01-operations-inventory.md`) contains any lowercase `/api/admin/*` literal, Step 3 records it as a path-case-skew row.
- Backend uses named path params (`{ResellerSlug}`, `{LicenseKey}`, `{RequestId}`, `{UserId}`, `{MachineBindingId}`, `{SessionId}`, `{Version}`, `{Platform}`, `{PrefixValue}`, `{UploadToken}`). Any FE OperationId still using `:id` or `{Id}` becomes an id-shape-skew row.
- FE `admin.metrics.kpis` (root cause of `.lovable/issues/06-admin-overview-kpis-red-error.md`) has NO backend counterpart. Backend exposes `GET /Api/Admin/Metrics` (index) and `GET /Api/Admin/Metrics/ShardStatus`. Step 3 records this as MISSING; the fix path is either wiring FE to `admin.metrics.overview` -> `GET /Api/Admin/Metrics`, or adding a new `GET /Api/Admin/Metrics/Kpis` action. Decision deferred to Step 5 (controller skeleton plan).
- Backup/Imports (Plan 14 step 29) is the only STUB in the current router; verifyAndApply saga is scheduled outside Plan 18.
- No route uses trailing slashes; FE `Link to="..."` and generated paths must match.

## Move to plan-18 folder

Step 1's artifact currently sits at `docs/backend/operations-inventory.md`. Step 3 will consume both this file and the FE inventory; before Step 3 runs, copy the FE inventory to `docs/backend/plan-18/01-operations-inventory.md` (or symlink it) so all Phase A artifacts share one root. Not done in this step because the copy is a Step 3 precondition and belongs with the cross-join work.
