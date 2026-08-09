# Plan 18 · Step 13 · Pest Test Plan (Steps 121-150)

Status: draft (produced by Plan 18 Step 13).

Depends on: `docs/backend/plan-18/05-controller-skeleton-plan.md`
(new BE actions/routes), `docs/backend/plan-18/06-seeder-coverage-plan.md`
(row targets), `docs/backend/plan-18/07-seed-profiles-plan.md`
(`SEED_PROFILE` env dispatch), `docs/backend/plan-18/08-demo-login-plan.md`
(demo identities), `docs/backend/plan-18/11-error-manage-plan.md`
(subclass hierarchy, envelope additions).

## 1. Ground truth: what already exists

Pest tests live under `backend/tests/Feature/` and
`backend/tests/Unit/`. Directly relevant to Plan 18:

- `Feature/EnvelopeShapeTest.php` — asserts the canonical
  `Status/Attributes/Results` envelope. Extended in Step 121 for
  new `Attributes.Category` and `Attributes.OperationId`.
- `Feature/Endpoints/` — per-endpoint smoke coverage.
- `Feature/Admin/{AuditListTest,MetricsTest,LicenseCrudTest,UsersTest,
  RuntimeConfigTest,SessionsTest,QuotaRequestsTest,PrefixCrudTest,
  ResellerCrudTest,BrImportEndpointTest,AppUpdatesPublishTest,
  AppUpdatesYankTest}.php` — one file per admin resource. Plan 18 adds
  three new files (see §3) and extends four.
- `Feature/Errors/` — error-taxonomy coverage. Extended in Step 145.
- `Feature/Auth/` — auth surface. Extended in Steps 123-125 for demo
  login.
- `Feature/Architecture/` — Pest architecture rules (namespace/type
  guards). Extended in Step 149 for the new subclass hierarchy.

## 2. Test file layout

New/extended files under `backend/tests/Feature/`:

```
Auth/
  DemoLoginTest.php               (NEW, Step 123)
  MeEndpointTest.php              (NEW, Step 124)
  RefreshEndpointTest.php         (NEW, Step 125)
Admin/
  MetricsKpisTest.php             (NEW, Step 126)
  FeaturesIndexTest.php           (NEW, Step 127)
  SerialShowTest.php              (NEW, Step 128)
  UserDestroyTest.php             (NEW, Step 129)
  LicensesIndexTest.php           (NEW, Step 130)
Errors/
  ErrorEnvelopeCategoryTest.php   (NEW, Step 141)
  ErrorIdHeaderTest.php           (NEW, Step 142)
  OperationIdEchoTest.php         (NEW, Step 143)
  LaraAuditErrorsSinkTest.php     (NEW, Step 144)
  ExceptionHierarchyTest.php      (NEW, Step 145)
Seed/
  SeedProfileDefaultTest.php      (NEW, Step 131)
  SeedProfileEmptyTest.php        (NEW, Step 132)
  SeedProfileErrorTest.php        (NEW, Step 133)
  DemoIdentitiesPresentTest.php   (NEW, Step 134)
Architecture/
  Plan18SubclassRulesTest.php     (NEW, Step 149)
EnvelopeShapeTest.php             (EXTEND, Step 121)
```

Every new file MUST live under `Tests\Feature\...` namespace and use
Pest's `test('...', function () { ... })` syntax to match the
existing repo convention.

## 3. Row-per-endpoint assertion checklist

Format: `Test file :: OperationId :: seed profile :: assertions`.

### 3.1 Auth surface

- `Auth/DemoLoginTest.php` :: `auth.login` :: `default`
  1. POST `/Api/Auth/Login` with the three canonical demo emails
     from `DemoIdentities::all()` returns 200.
  2. Response envelope carries `AccessToken`, `RefreshToken`, and a
     `Me` block with the expected roles per Step 8.
  3. Same call in `SEED_PROFILE=empty` still succeeds (demo
     identities seeded in every profile per Step 7).
  4. Bad password returns 401 `AuthInvalidCredentials` with the
     canonical error envelope.

- `Auth/MeEndpointTest.php` :: `auth.me` :: `default`
  1. Authenticated GET `/Api/Auth/Me` returns 200 with `Me` matching
     the login payload.
  2. Unauthenticated call returns 401 `AuthUnauthorized`.
  3. Impersonation active: `Me.Impersonation` present with
     `TargetUserId`.

- `Auth/RefreshEndpointTest.php` :: `auth.refresh` :: `default`
  1. Valid refresh rotates the pair, response 200.
  2. Reused refresh yields 401 `AuthRefreshReused`; family
     invalidated (asserted via subsequent 401 on original session).
  3. Concurrent refresh: one wins, loser gets 409
     `AuthRefreshRaceLost`, session survives.

### 3.2 Admin surface (parity gaps from Step 5)

- `Admin/MetricsKpisTest.php` :: `admin.metrics.kpis` :: `default`
  1. GET `/Api/Admin/Metrics/Kpis` returns 200.
  2. All KPI tiles non-zero under `default` (>=8 resellers,
     >=120 licences, >=24 quota requests per Step 6).
  3. Under `empty` seed, every tile returns 0 without erroring
     (proves the red-overview root cause is fixed).
  4. RBAC: reseller role gets 403 `AuthzRoleDenied`.

- `Admin/FeaturesIndexTest.php` :: `admin.features.list` :: `default`
  1. GET `/Api/Admin/Features` returns `{ Items: [...] }` matching
     the Zod shape used by FE.
  2. Ordering deterministic (by `Slug` asc).
  3. RBAC: only SuperAdmin gets full list; scoped admins get
     filtered set (assertion delegated to Plan 05, marker only here).

- `Admin/SerialShowTest.php` :: `admin.serials.show` :: `default`
  1. GET `/Api/Admin/Serials/{id}` returns 200 with serial payload.
  2. Missing id -> 404 `SerialNotFound`.
  3. Revoked serial -> 200 with `Status = revoked` (not an error).

- `Admin/UserDestroyTest.php` :: `admin.users.destroy` :: `default`
  1. DELETE `/Api/Admin/Users/{id}` returns 200 empty envelope.
  2. Last SuperAdmin -> 409 `AuthzLastAdminProtected`.
  3. Self-delete -> 409 `SelfDestructionForbidden`.

- `Admin/LicensesIndexTest.php` :: `admin.licenses.list` :: `default`
  1. Paginated shape `{Items, Page, PageSize, Total}` verified.
  2. Filter by `ResellerId` respected.
  3. `empty` seed returns `Total = 0`.

### 3.3 Seed profiles

- `Seed/SeedProfileDefaultTest.php` (Step 131)
  1. `php artisan db:seed` with `SEED_PROFILE=default` populates row
     counts matching `06-seeder-coverage-plan.md`.
  2. Idempotent: second run does not double rows (upserts).

- `Seed/SeedProfileEmptyTest.php` (Step 132)
  1. `SEED_PROFILE=empty`: catalog + demo identity tables have rows;
     transactional tables (Licenses, Sessions, AuditEntries) empty.

- `Seed/SeedProfileErrorTest.php` (Step 133)
  1. `SEED_PROFILE=error`: default rows plus at least one row per
     error-trigger class (expired license, revoked serial, stalled
     backup, orphaned binding).

- `Seed/DemoIdentitiesPresentTest.php` (Step 134)
  1. Under all three profiles, `Users::whereIn('Email', [...])`
     returns exactly 3 rows.
  2. Passwords verify with the canonical demo password.

### 3.4 Error-manage surface (Step 11 additions)

- `Errors/ErrorEnvelopeCategoryTest.php` (Step 141)
  Table-driven: for each subclass factory, throw via a test route
  and assert `Attributes.Category` matches the expected enum
  (`Auth`, `Validation`, `RateLimit`, `DomainConflict`, `NotFound`,
  `Internal`).

- `Errors/ErrorIdHeaderTest.php` (Step 142)
  1. Every 4xx/5xx response carries `X-Error-Id` header equal to
     `Attributes.ErrorId`.
  2. Header format is UUID v4.
  3. 2xx responses do NOT carry `X-Error-Id`.

- `Errors/OperationIdEchoTest.php` (Step 143)
  1. Request with `X-Lara-Operation: admin.licenses.show` failing
     yields `Attributes.OperationId = "admin.licenses.show"`.
  2. Missing header -> attribute absent (not `null`, not empty
     string).

- `Errors/LaraAuditErrorsSinkTest.php` (Step 144)
  1. A thrown `LaraException` writes exactly one NDJSON line to the
     `lara-audit-errors` channel with the redacted payload.
  2. Line parses as JSON and contains `RequestId`, `ErrorId`,
     `ErrorCode`, `HttpStatus`, `OperationId?`.
  3. `PII` field names never appear in the line (test via
     `DetailsRedactor` allowlist).

- `Errors/ExceptionHierarchyTest.php` (Step 145)
  1. Each new subclass constructs via its factory and reports the
     expected `httpStatus` and `errorCode`.
  2. Bare `new LaraException(...)` still works (base kept for BC).

### 3.5 Envelope shape extension

- `EnvelopeShapeTest.php` (extend, Step 121)
  1. Add cases proving `Attributes.Category` and
     `Attributes.OperationId` are echoed when set and absent
     otherwise; no change to `Status` or `Results` layout.

### 3.6 Architecture guard

- `Architecture/Plan18SubclassRulesTest.php` (Step 149)
  1. Every class extending `LaraException` in
     `App\Exceptions\*` is `final`.
  2. `LaraException` itself is NOT `final` (subclassing enabled).
  3. No controller under `App\Http\Controllers\*` calls
     `new LaraException(...)` directly; must go through a subclass
     factory.

## 4. Step-to-file map (for Steps 121-150)

| Step | File | Op / Concern |
|--:|---|---|
| 121 | `EnvelopeShapeTest.php` (extend) | Envelope Category+OperationId |
| 122 | shared fixture `tests/Feature/Support/SeedHelpers.php` | seed helpers |
| 123 | `Auth/DemoLoginTest.php` | `auth.login` demo |
| 124 | `Auth/MeEndpointTest.php` | `auth.me` |
| 125 | `Auth/RefreshEndpointTest.php` | `auth.refresh` |
| 126 | `Admin/MetricsKpisTest.php` | `admin.metrics.kpis` |
| 127 | `Admin/FeaturesIndexTest.php` | `admin.features.list` |
| 128 | `Admin/SerialShowTest.php` | `admin.serials.show` |
| 129 | `Admin/UserDestroyTest.php` | `admin.users.destroy` |
| 130 | `Admin/LicensesIndexTest.php` | `admin.licenses.list` |
| 131 | `Seed/SeedProfileDefaultTest.php` | default profile |
| 132 | `Seed/SeedProfileEmptyTest.php` | empty profile |
| 133 | `Seed/SeedProfileErrorTest.php` | error profile |
| 134 | `Seed/DemoIdentitiesPresentTest.php` | demo identities everywhere |
| 135-140 | extend existing files: `MetricsTest`, `AuditListTest`, `LicenseCrudTest`, `UsersTest`, `QuotaRequestsTest`, `PrefixCrudTest` (envelope + operation-id header) | parity coverage |
| 141 | `Errors/ErrorEnvelopeCategoryTest.php` | Category attr |
| 142 | `Errors/ErrorIdHeaderTest.php` | X-Error-Id header |
| 143 | `Errors/OperationIdEchoTest.php` | OperationId echo |
| 144 | `Errors/LaraAuditErrorsSinkTest.php` | NDJSON sink |
| 145 | `Errors/ExceptionHierarchyTest.php` | subclass factories |
| 146 | run `php artisan test --coverage-html` gate | coverage baseline |
| 147 | `tests/Pest.php` update | shared datasets for `SEED_PROFILE` |
| 148 | `phpunit.xml` env matrix | run all three profiles in CI |
| 149 | `Architecture/Plan18SubclassRulesTest.php` | subclass rules |
| 150 | drift snapshot `tests/__snapshots__/plan-18-endpoints.json` | endpoint list lock |

## 5. Determinism rules

- Every seed-dependent test resets DB via `RefreshDatabase` and sets
  `SEED_PROFILE` on the test-case class, not globally, so parallel
  workers do not step on each other.
- Time-dependent assertions use `Carbon::setTestNow(...)` in `setUp`.
- No test hits the network; the `lara-diag` and `lara-audit-errors`
  channels are stubbed with the existing in-memory monolog handler
  used by `EnvelopeShapeTest.php`.

## 6. Out of scope

- Playwright/E2E (Step 14 owns it).
- Coverage threshold policy (Step 146 gates the baseline; the % is
  set in Step 176's CI plan).
- Snapshot format for `plan-18-endpoints.json` beyond "sorted list of
  method + path"; the linter (Step 15) enforces the exact format.
