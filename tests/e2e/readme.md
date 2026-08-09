# End-to-end tests

Playwright browser suite for the Licensing Portal frontend.
Config: `playwright.config.ts` at repo root.

## Layout

```
tests/e2e/
├── fixtures/     Shared Playwright test fixtures (auth, seeded data)
├── helpers/      Non-Page-Object utilities (env, storage state)
├── pages/        Page Object Models (one class per route)
└── specs/        Test specs (`*.spec.ts`)
```

## Required env

- `E2E_BASE_URL` (default `http://localhost:8080`): frontend URL under test.
- `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD`: credentials for the demo
  Admin seeded by `backend/database/seeders/E2EFixturesSeeder.php`.

Fail-loud: `helpers/env.ts::requireEnv()` throws when a required var is
missing rather than falling through to a silent default.

## Run

```
bunx playwright install --with-deps    # first run only
bunx playwright test                   # all browsers
bunx playwright test --project=chromium
bunx playwright test --ui              # interactive
```

## Artifacts

- `playwright-report/`: HTML report (uploaded from CI on failure).
- `test-results/`: traces, screenshots, videos (retained on failure).
- `.auth/`: persisted storage state per role. All three are gitignored.

## Plan 18 Spec-to-Seed Matrix

Specs in `tests/e2e/specs/plan-18/` are bound to specific backend seed profiles via `PLAYWRIGHT_SEED_PROFILE`.

| Spec File | Target Seed | Concern |
|-----------|-------------|---------|
| `demo-login-panel.spec.ts` | `default` | Panel render |
| `demo-login-hotkey.spec.ts` | `default` | Hotkey access |
| `demo-login-prod-gated.spec.ts` | `default` | Prod build gate |
| `admin-overview-green.spec.ts` | `default` | KPI green state |
| `admin-overview-empty.spec.ts` | `empty` | Empty states |
| `admin-overview-error.spec.ts` | `error` | Error rows |
| `admin-metrics-kpis.spec.ts` | `default` | Metrics op fired |
| `admin-features-list.spec.ts` | `default` | Features list |
| `admin-user-destroy-last-admin.spec.ts` | `default` | Last-admin guard |
| `notification-center-bell.spec.ts` | `default` | Bell badge |
| `notification-center-drawer.spec.ts` | `default` | Drawer FIFO |
| `notification-center-hotkey.spec.ts` | `default` | Alt+N hotkey |
| `notification-center-copy-ids.spec.ts` | `error` | Copy correlation |
| `error-envelope-x-error-id.spec.ts` | `error` | Header parity |
| `error-category-toast.spec.ts` | `error` | Category routing |

### Plan 18 Helpers & Fixtures

- `helpers/demo-login.ts`: Provides `loginAsDemo(page, role)`
- `helpers/notification-center.ts`: Provides `openBell()`, `readEntries()`, `copyCorrelationIds()`
- `fixtures/plan-18-seed-guards.ts`: Fails fast on `PLAYWRIGHT_SEED_PROFILE` mismatch with `test.info().annotations`
