# SS-05: Frontend e2e workflow

Parent: 10-e2e-tests-and-cicd
Slug: frontend-e2e-workflow
Status: pending
Created: 2026-07-19

## Goal

`.github/workflows/frontend-e2e.yml` that stands up a real backend + frontend and runs Playwright across chromium, firefox, webkit on every PR and push to main.

## Jobs

### Job: `e2e`

Runner: `ubuntu-latest`. Strategy matrix: `browser in [chromium, firefox, webkit]`.

Steps:
1. Checkout.
2. Setup PHP 8.3 (`shivammathur/setup-php@v2`) with mbstring, intl, xml, curl, zip, sqlite, pdo_sqlite.
3. Setup Node 20 + Bun.
4. Cache Composer + Bun.
5. `composer install --no-interaction --prefer-dist` in `backend/`.
6. Copy `backend/.env.ci` -> `backend/.env`; `php artisan key:generate --force`.
7. `php artisan migrate:fresh --seed --force` (uses `E2EFixturesSeeder`, sqlite path `storage/testing/e2e.sqlite`).
8. Start backend: `php -S 127.0.0.1:8000 -t backend/public &> backend.log &`, wait for `curl -sf http://127.0.0.1:8000/Api/Public/Health`.
9. `bun install --frozen-lockfile` + `bun run build`.
10. Start frontend: `bunx serve dist -l 4173 &> frontend.log &` (with SPA fallback), wait for `curl -sf http://127.0.0.1:4173/`.
11. `bunx playwright install --with-deps ${{ matrix.browser }}`.
12. `bunx playwright test --project=${{ matrix.browser }}` with env:
    - `E2E_BASE_URL=http://127.0.0.1:4173`
    - `E2E_API_URL=http://127.0.0.1:8000`
    - `LARA_E2E_TEST_ENDPOINTS=1`
    - `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD`, `E2E_RESELLER_EMAIL`, `E2E_RESELLER_PASSWORD`, `E2E_ENDUSER_EMAIL`, `E2E_ENDUSER_PASSWORD` from secrets (with test-only defaults injected via `E2EFixturesSeeder` env fallback when secrets absent on PRs from forks).
13. Always upload `playwright-report/` and `test-results/` as artifacts.
14. On failure, upload `backend.log` and `frontend.log`.

### Job: `summary`

Aggregates matrix results and posts a single check with pass/fail per browser. Fails if any browser fails.

## Concurrency

`concurrency: { group: e2e-${{ github.ref }}, cancel-in-progress: true }` so a new push cancels the previous run.

## Branch protection

Add `e2e (chromium)`, `e2e (firefox)`, `e2e (webkit)`, `summary` as required checks. Documented in `docs/deploy/branch-protection.md` (step 47).

## Verification

- Green run on a fresh PR.
- HTML report artifact opens locally and shows the trace for any failed step.
- Cancelling a run mid-flight does not leave orphan `php -S` processes (uses `nohup` + PID capture with a trap on `EXIT`).
