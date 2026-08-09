# SS-01: Endpoint parity audit

Parent: 10-e2e-tests-and-cicd
Slug: endpoint-parity-audit
Status: pending
Created: 2026-07-19

## Goal

Produce a machine-readable inventory of every route in `backend/routes/api.php` mapped to its controller action, FormRequest, Policy, JsonResource, and whether a Pest Feature test file exists. Save the report to `backend/tests/Reports/endpoint-parity.json` and a human-readable summary to `.lovable/plans/subtasks/10-e2e-tests-and-cicd/reports/endpoint-parity.md`.

## Method

1. Write an artisan command `php artisan lara:audit:endpoints` under `backend/app/Console/Commands/AuditEndpoints.php` that:
   - Iterates `Route::getRoutes()`.
   - Resolves controller class + method via `getActionName()`.
   - Uses reflection to detect the FormRequest parameter type on the method.
   - Reads `AuthServiceProvider::$policies` to detect Policy registration for the model returned.
   - Detects JsonResource by scanning `use App\Http\Resources\...` in the controller class.
   - Detects a test file by convention `tests/Feature/<Group>/<Controller><Action>Test.php`.
2. Emit JSON envelope: `{Route, Method, Controller, Action, FormRequest, Policy, Resource, TestFile, Status}` where `Status in {ok, missing-request, missing-policy, missing-resource, missing-test}`.
3. Add `tests/Feature/Endpoints/EndpointInventoryTest.php` that runs the command and fails if any row is not `ok` (allowlist supported via `config/lara.php` `endpoint_audit_allowlist` for public endpoints that legitimately skip FormRequest).

## Deliverables

- `backend/app/Console/Commands/AuditEndpoints.php`
- `backend/tests/Feature/Endpoints/EndpointInventoryTest.php`
- `backend/tests/Reports/endpoint-parity.json` (gitignored; generated in CI)
- `.lovable/plans/subtasks/10-e2e-tests-and-cicd/reports/endpoint-parity.md` (first-run snapshot; hand-curated summary)

## Verification

- `php artisan lara:audit:endpoints --json` prints JSON.
- `vendor/bin/pest tests/Feature/Endpoints/EndpointInventoryTest.php` green after steps 2-4 of the parent plan land the backfills.
