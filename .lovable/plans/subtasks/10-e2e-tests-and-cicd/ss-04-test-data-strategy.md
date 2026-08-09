# SS-04: Test data + reset strategy

Parent: 10-e2e-tests-and-cicd
Slug: test-data-strategy
Status: pending
Created: 2026-07-19

## Goal

Every e2e spec must run against a known-good fixture and must not bleed state into the next spec. Establish one contract, use it everywhere.

## Backend contract

- CI job boots a fresh sqlite DB per suite run (`storage/testing/e2e.sqlite`, deleted at job start).
- `php artisan migrate:fresh --seed --env=ci --force` runs before Playwright starts.
- `E2EFixturesSeeder` (gated by `APP_ENV in {testing, ci}` OR `LARA_E2E_SEED=1`) creates:
  - SuperAdmin: env `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD`.
  - Reseller admin: env `E2E_RESELLER_EMAIL`, `E2E_RESELLER_PASSWORD`.
  - EndUser: env `E2E_ENDUSER_EMAIL`, `E2E_ENDUSER_PASSWORD`.
  - Reseller row `demo-reseller` with prefixes + one issued License with serial `E2E-DEMO-SERIAL-0001`.
- No credentials in source; workflow provides them via `secrets` (fall back to fixed defaults only in `APP_ENV=testing`).

## Test-only helper endpoints (gated by `LARA_E2E_TEST_ENDPOINTS=1`)

Live under `routes/api.php` inside an `if (config('lara.e2e_test_endpoints'))` block, and are 100% off in production:
- `GET /api/public/test/last-password-reset-token/{email}` returns the most recent unused reset token.
- `POST /api/public/test/reset` truncates dynamic tables and re-runs `E2EFixturesSeeder`.
- `POST /api/public/test/freeze-time` accepts a millis-epoch and configures the Carbon test-now for the next request only.

An `EndpointInventoryTest` allowlist entry documents that these are excluded from FormRequest requirements.

## Frontend contract

- `playwright.config.ts` sets `globalSetup: "./tests/e2e/global-setup.ts"` which calls `POST /api/public/test/reset` when `E2E_RESET=1`.
- Each spec starts by calling the login helper `helpers/auth.ts::loginAs("admin"|"reseller"|"enduser")` which uses `storageState` files under `tests/e2e/.auth/<role>.json` produced by the setup.
- Specs never share fixtures via file writes; they read env + storage-state only.

## Verification

- Running a spec twice in a row succeeds (idempotent reset).
- Skipping the reset endpoint (`LARA_E2E_TEST_ENDPOINTS=0`) makes the workflow fail with a clear message, not silently reuse stale state.
