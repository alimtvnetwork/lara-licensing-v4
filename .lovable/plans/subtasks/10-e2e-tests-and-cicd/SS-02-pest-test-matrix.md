# SS-02: Pest test matrix

Parent: 10-e2e-tests-and-cicd
Slug: pest-test-matrix
Status: pending
Created: 2026-07-19

## Goal

Enumerate the Pest Feature tests required for full backend coverage, so no controller ships without matching coverage. This is the source-of-truth checklist for step 15 of the parent plan.

## Matrix (one row per test file, all under `backend/tests/Feature/`)

| Group | Test file | Endpoints covered | Key assertions |
|-|-|-|-|
| Auth | `Auth/RegisterTest.php` | `POST /Auth/Register` | bootstrap only when no SuperAdmin; envelope; audit row |
| Auth | `Auth/LoginTest.php` | `POST /Auth/Login` | 200 happy path, 401 bad creds, 428 captcha, 429 rate limit |
| Auth | `Auth/LogoutTest.php` | `POST /Auth/Logout` | token invalidated; audit row |
| Auth | `Auth/RefreshTest.php` | `POST /Auth/Refresh` | rotates token; old token 401 |
| Auth | `Auth/PasswordResetTest.php` | `POST /Auth/Password/Forgot`, `POST /Auth/Password/Reset` | single-use token; throttling; audit |
| Auth | `Auth/CaptchaTest.php` | `GET /Auth/Captcha`, integrated with login | HMAC signed; expires |
| Auth | `Auth/MeTest.php` | `GET /Users/Me` | returns `EndUser` shape included |
| Admin | `Admin/UsersIndexTest.php` | `GET /Admin/Users` | RBAC, filter, pagination |
| Admin | `Admin/UsersUpdateTest.php` | `PUT /Admin/Users/{id}` | last-admin guard, role assignment via `user_roles` |
| Admin | `Admin/SessionsTest.php` | `GET/DELETE /Admin/Users/{id}/Sessions` | revoke |
| Admin | `Admin/ResellersTest.php` | full CRUD | slug uniqueness |
| Admin | `Admin/LicenseIssueTest.php` | `POST /Admin/Licenses` | quota preflight, FeatureCatalog assertion |
| Admin | `Admin/LicenseShowTest.php` | `GET /Admin/Licenses/{id}` | ETag on Version |
| Admin | `Admin/LicenseUpdateTest.php` | `PUT /Admin/Licenses/{id}` | If-Match required; 412 path |
| Admin | `Admin/LicenseRevokeTest.php` | `DELETE /Admin/Licenses/{id}` | quota restored; audit |
| Admin | `Admin/MetricsTest.php` | `GET /Admin/Metrics`, `GET /Admin/Metrics/ShardStatus` | RBAC + envelope |
| Admin | `Admin/AuditTest.php` | `GET /Api/Auth/AuditLogs` | filter by actor/action/entity |
| Admin | `Admin/AppUpdatesTest.php` | full upload + publish + yank | see SS-03 |
| Admin | `Admin/QuotaRequestsTest.php` | full lifecycle | idempotency key |
| Reseller | `Reseller/LicensesTest.php` | scoped list + detail | tenant isolation |
| Reseller | `Reseller/QuotaRequestsTest.php` | submit + cancel | |
| Portal | `Portal/SerialVerifyTest.php` | `POST /Verify/Serial` | active / revoked / not-found envelopes |
| Portal | `Portal/UpdateManifestTest.php` | `GET /App/UpdateManifest` | platform matrix |
| Public | `Public/HealthTest.php` | `GET /Api/Public/Health` | 200 up, 503 maintenance |
| System | `Endpoints/EndpointInventoryTest.php` | all | see SS-01 |
| System | `MigrationsAreIdempotentTest.php` | seeders | fresh + seed twice |

## Conventions

- One test file per controller action group; group siblings only when they share fixtures.
- Every test seeds via `E2EFixturesSeeder` and asserts audit rows through the `AuditLogService`.
- Error paths use the shared `assertLaraException($code, $status)` helper (created if not present).

## Verification

- `vendor/bin/pest --group=feature` green.
- `EndpointInventoryTest` passes with no `missing-test` rows.
