# Testing Runbook

Plan 10 step 48. Canonical entry point for running the Licensing Portal test
suite locally and interpreting CI failures. Companion doc:
[`docs/testing/test-data.md`](./test-data.md) (env vars, seeders, per-spec
data matrix) and [`docs/ci/branch-protection.md`](../ci/branch-protection.md)
(which checks gate `main`).

## Test tiers

| Tier | Script | Runs | Scope |
| --- | --- | --- | --- |
| Unit | `bun run test` | Vitest | Frontend units + component tests. |
| Static | `bun run verify` | UI linters, ESLint strict, `tsgo`, Vitest | Full pre-commit gate. |
| Backend feature | `cd backend && vendor/bin/pest` | Pest Feature + Unit | Laravel API contracts, policies, seeders. |
| E2E full | `bun run test:e2e` | Playwright (all projects) | Every spec under `tests/e2e/specs/`. |
| E2E chromium | `bun run test:e2e:chromium` | Playwright chromium | PR-time subset (matches `frontend-e2e.yml`). |
| E2E smoke | `bun run test:e2e:smoke` | Playwright chromium | Release critical path (matches `release-smoke.yml`). |
| E2E interactive | `bun run test:e2e:ui` | Playwright UI mode | Local debugging with trace viewer. |
| E2E report | `bun run test:e2e:report` | `playwright show-report` | Open the last HTML report. |

The smoke tier is the exact 5-spec subset gating every `v*` tag:
`health`, `auth-login`, `admin-dashboard`, `admin-license-crud`,
`portal-serial-lookup`. Keep this list in sync with
`.github/workflows/release-smoke.yml` (step 46).

## Local bootstrap

Prereqs: PHP 8.3, Composer, Bun, Playwright browsers.

```bash
# 1. Backend: install, migrate, seed the E2E fixtures.
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed --seeder=Database\\Seeders\\E2EFixturesSeeder

# 2. Backend server on :8000 (leave running).
php artisan serve --host=127.0.0.1 --port=8000

# 3. Frontend build + preview on :8080 (new shell).
cd ..
bun install
bun run build
bun run preview --host 127.0.0.1 --port 8080

# 4. Playwright (new shell).
bunx playwright install --with-deps chromium
bun run test:e2e:smoke     # fast sanity
bun run test:e2e:chromium  # full PR-time suite
```

Environment variables consumed by the specs live in
[`test-data.md`](./test-data.md). Copy them into `.env.playwright` or export
per shell before running.

## Password reset e2e (deterministic, no SMTP)

`tests/e2e/specs/auth-password-reset.spec.ts` covers the full forgot -> mint
-> redeem -> login loop plus the single-use invariant. It relies on the
`e2e:mint-reset-token` artisan command (see
`backend/app/Console/Commands/E2EMintPasswordResetTokenCommand.php`) and the
`mintPasswordResetToken` helper in `tests/e2e/helpers/backend-token.ts`, so
no SMTP, mailbox, or log scraping is needed.

Exact command sequence from a clean checkout:

```bash
# Shell 1: backend (leave running for the whole session).
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed --seeder=Database\\Seeders\\E2EFixturesSeeder
# Sanity: the artisan helper must resolve before the spec runs.
php artisan e2e:mint-reset-token --email="$E2E_ADMIN_EMAIL" --json
php artisan serve --host=127.0.0.1 --port=8000

# Shell 2: frontend preview on :8080 (leave running).
bun install
bun run build
bun run preview --host 127.0.0.1 --port 8080

# Shell 3: Playwright. Export the fixture creds first.
export E2E_BASE_URL="http://127.0.0.1:8080"
export E2E_API_BASE_URL="http://127.0.0.1:8000"
export E2E_ADMIN_EMAIL="admin+e2e@licensing.test"
export E2E_ADMIN_PASSWORD="Password!234"
bunx playwright install --with-deps chromium

# Run just the password-reset spec (chromium).
bun run test:e2e:chromium -- tests/e2e/specs/auth-password-reset.spec.ts

# Run all three auth specs together (bootstrap + login + reset).
bun run test:e2e:chromium -- \
  tests/e2e/specs/auth-login.spec.ts \
  tests/e2e/specs/auth-register-bootstrap.spec.ts \
  tests/e2e/specs/auth-password-reset.spec.ts

# Interactive debugging with the trace viewer.
bun run test:e2e:ui -- tests/e2e/specs/auth-password-reset.spec.ts

# Cross-browser (matches nightly-e2e.yml).
bunx playwright test --project=firefox tests/e2e/specs/auth-password-reset.spec.ts
bunx playwright test --project=webkit  tests/e2e/specs/auth-password-reset.spec.ts

# Open the HTML report from the last run.
bun run test:e2e:report
```

If the spec aborts mid-run it leaves the admin password rotated. Re-seed
before retrying:

```bash
cd backend && php artisan migrate:fresh --seed --seeder=Database\\Seeders\\E2EFixturesSeeder
```



## Diagnosing failures

1. Open the HTML report: `bun run test:e2e:report`. Each failed test links
   to its trace (`trace.zip`), video, and screenshot.
2. Inspect the server logs. Local runs stream to the terminal; CI uploads
   `e2e-server-logs*` artifacts alongside the Playwright report.
3. Confirm the fixture is present. Every spec's data dependency is listed
   in the [test-data matrix](./test-data.md#spec-to-data-matrix); if a
   seeder is missing, re-run the `migrate:fresh --seed` step above.
4. Reset drift. If a spec mutates seeded state (for example
   `auth-password-reset.spec.ts` rotates the admin password and restores
   it) and aborts mid-run, re-seed before re-running.

## Contributing a new spec

1. Add the spec under `tests/e2e/specs/<area>-<flow>.spec.ts`.
2. Reuse `tests/e2e/fixtures/lara-auth.ts` and existing Page Objects; add
   a new Page Object if the surface is not covered.
3. Document the data dependency in `test-data.md` under Spec-to-data
   matrix.
4. If the flow is release-critical, add the spec path to
   `test:e2e:smoke` in `package.json` AND to
   `.github/workflows/release-smoke.yml`. Both must list the same specs.
5. Never rely on SMTP, real time, or clipboard. Use artisan e2e helpers
   (`e2e:mint-reset-token`, `e2e:first-reseller-id`) for deterministic
   backend state.

## CI cross-reference

| Workflow | Local equivalent |
| --- | --- |
| `backend-e2e / pest` | `cd backend && vendor/bin/pest` |
| `frontend-e2e / Playwright (chromium)` | `bun run test:e2e:chromium` |
| `nightly-e2e / firefox`, `nightly-e2e / webkit` | `bunx playwright test --project=firefox` / `--project=webkit` |
| `release-smoke / smoke (chromium)` | `bun run test:e2e:smoke` |

Keep this table aligned with `.github/workflows/*.yml` job names so a red
Check on a PR is one grep away from a local reproduction.
