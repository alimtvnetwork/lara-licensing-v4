# Task 18: Backend parity + seed-mode demo login + e2e testing + error manage with notifications

Slug: backend-seed-login-e2e-error-manage
Status: pending
Created: 2026-07-21
Command: `.lovable/spec/commands/11-backend-seed-login-e2e-error-manage.md`
Issue: `.lovable/issues/06-admin-overview-kpis-red-error.md`

## Intent

Bring the backend, seed-mode preview surface, error-management model, and end-to-end test coverage to a coherent, demoable state. The single visible signal of success: an untrained reviewer can flip the runtime toggle to seed mode, log in with a documented demo password, and click through every admin/reseller/portal surface with zero red error tiles, while every 5xx that does occur surfaces through a unified notification path with a copyable ErrorId.

## Scope

1. Backend endpoint parity vs `src/generated/api/operations.ts`.
2. Seeder coverage for every operation (default / empty / error seeds).
3. Seed-mode demo login: documented credentials + `/admin/login` shortcut when `runtime-mode === "preview"`.
4. Preview-fixture handlers for every failing KPI/list operation (see attached screenshot).
5. Error-manage model: LaraException + ApiErrorCodeType + envelope + ErrorId/RequestId correlation + notification center.
6. Error logger: server-side channel per `backend/config/logging.php`, client-side error store retention.
7. Notification component: bell + drawer that reads from error store and server-emitted events.
8. E2E: Pest feature tests (backend) + Playwright specs (frontend) covering all three seeds.
9. CI/CD wiring: backend + frontend workflows must be green on merge.

## Inputs

- Screenshot attached (four red KPI tiles under seed mode).
- Existing plans still pending: 05, 06, 07, 09, 12, 13, 14 (their scope must not be duplicated - reference, do not re-plan).
- Spec sources of truth: `spec/03-error-manage/`, `spec/21-app/`, `spec/23-app-db/`, `spec/28-runtime-modes/`, `spec/02-coding-guidelines/`.
- Existing seeders in `backend/database/seeders/`.
- Preview transport: `src/lib/preview-transport.ts`, `src/lib/preview-fixtures/`, `src/lib/preview-fixtures/_shapes.ts`.

## Acceptance Criteria

- AC-01: `bunx vitest run` green (>= current 824 baseline).
- AC-02: `backend/vendor/bin/pest` green on `backend/tests/Feature/**`.
- AC-03: `bunx playwright test` green for all specs under `default`, `empty`, `error` seeds.
- AC-04: `/admin/login` under seed mode shows a "Use demo credentials" button that pre-fills `admin@demo.local` / a documented password and authenticates without a backend round-trip.
- AC-05: `/admin` under `default` seed renders four green KPI tiles (no "Something failed" copy anywhere on the screen).
- AC-06: Every operation in `src/generated/api/operations.ts` has (a) a backend route, (b) a preview fixture handler, (c) a Zod shape entry in `_shapes.ts`, (d) at least one automated test - enforced by `linter-scripts/check-dead-operations.py` + a new `check-preview-handler-coverage.py` extension.
- AC-07: Every `LaraApiError` with `httpStatus >= 500` triggers a toast AND a notification-center entry containing `ErrorId`, `RequestId`, `OperationId`, timestamp.
- AC-08: Backend logs every LaraException to the `errors` channel with structured JSON (ErrorId, RequestId, user id, route, code).
- AC-09: `check-error-code-parity.mjs` green (closed-set BE <-> FE parity).
- AC-10: Release ceremony (`spec/11-release.md`) fires ONLY as the very last step, once every subtask has moved to `.lovable/plans/completed/`.

## Affected files (non-exhaustive)

- `backend/app/Http/Controllers/Admin/*`
- `backend/app/Exceptions/*`, `backend/bootstrap/app.php`
- `backend/database/seeders/E2EFixturesSeeder.php` (+ new `DemoLoginSeeder.php`)
- `backend/routes/api.php`
- `src/routes/_authenticated/admin.index.tsx`
- `src/routes/admin.login.tsx` (or equivalent)
- `src/lib/preview-fixtures/*.ts`
- `src/lib/preview-fixtures/_shapes.ts`
- `src/lib/lara-api-error.ts`, `src/lib/error-store.ts`
- `src/components/notifications/*` (new)
- `.github/workflows/backend-e2e.yml`, `.github/workflows/*`
- `linter-scripts/check-preview-handler-coverage.py`
- `docs/testing/coverage-matrix.md`

## Attachments

- `./assets/18-backend-seed-login-e2e-error-manage/admin-overview-red-errors.png` - baseline: `/admin` under seed mode with four red KPI tiles. Every KPI listed must render green under the `default` seed by the end of the plan; this file is the before shot for the after-comparison in Step 200.

## Non-goals

- No changes to Plans 05/06/07/09/12/13/14 scope; they remain independent.
- No new runtime mode; work is within `preview` + `production` as defined in `spec/28-runtime-modes/`.
- No premature version bump: release ceremony runs only in the FINAL step.
