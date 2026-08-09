# Command 11: Backend audit, seed-mode demo login, e2e testing, error manage with notifications

Command (paraphrased verbatim from user, 2026-07-21):
"Check the backend code, list what is pending. Figure out end-to-end testing. Plan 200 tasks so that tests and seeding data let me log in for testing using the seed data. When I go to the backend login section I should have a demo password, and every section should be visible when I'm on the seeding section. No need to block me. There are several errors visible (see screenshot); make sure those errors are actually fixed by the seeding data. There should be an error logger and error manage model with notifications; read the error-manage spec properly. Plan 200 steps so everything is done properly."

## Scope

- Backend (`backend/`, Laravel 11): finish endpoints referenced by `src/generated/api/operations.ts`, wire seeders (`E2EFixturesSeeder`, `RolesSeeder`, `FeatureCatalogSeeder`, `ClosedSetsSeeder`, `RootSeeder`, `ShardSeeder`) to cover every route surfaced in the admin UI, and expose deterministic demo credentials for seed-mode login.
- Frontend (`src/`): backfill preview-fixture handlers for every failing operation shown in the screenshot (KPIs, quota requests, users, resellers, licenses, features, audit, abuse, app updates), add a demo-login shortcut to `/admin/login` when running under seed mode, and route every `LaraApiError` through a central error store with toast + notification surface.
- Error management: implement per `spec/03-error-manage/` (LaraException / ApiErrorCodeType parity, envelope `{Status, Attributes, Results}`, ErrorId + RequestId correlation, notifications on 5xx).
- CI/CD (`.github/workflows/`): backend Pest feature tests + Playwright e2e (default / empty / error seeds) green on every merge.

## When it applies

- Every new backend controller: parity audit (route -> operation -> preview handler -> Playwright spec).
- Every seed mutation: matching `E2EFixturesSeeder` update.
- Every user-visible flow: Playwright spec + preview fixture.
- Every error path: closed-set `ApiErrorCodeType`, logged via error store, surfaced through the unified notification component.

## Non-negotiables

- Seed-mode admin login must succeed with a documented demo password without hitting the backend.
- Zero red KPI tiles on `/admin` under the `default` seed.
- No magic literals; PascalCase DB/JSON keys; 15-line function cap.
- Every 5xx carries ErrorId; every FE surface reads it from `LaraApiError.errorId`.

## Deliverable this turn

- Spec task `.lovable/spec/tasks/18-backend-seed-login-e2e-error-manage.md`.
- Plan `.lovable/plans/pending/18-backend-seed-login-e2e-error-manage.md` with exactly 200 steps.
- Subtask stubs under `.lovable/plans/subtasks/18-backend-seed-login-e2e-error-manage/`.
