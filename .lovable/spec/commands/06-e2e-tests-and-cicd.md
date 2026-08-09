# Command 06: End-to-end tests and CI/CD wiring

Slug: e2e-tests-and-cicd
Status: active
Created: 2026-07-19

## Command (verbatim excerpt)

> Confirm all the endpoints are created properly, database seeding and everything is done properly in the backend side, and end-to-end tests have been written that can be tested in CI/CD. Also end-to-end tests for the frontend. Connect the CI/CD with it.

## Scope

Applies to `backend/` (Laravel 11), `src/` (TanStack Start frontend), and `.github/workflows/`. The command is standing: from now on, every feature merge must land with (a) an endpoint definition, (b) a Pest feature test, (c) a Playwright e2e spec if it touches a user-visible flow, and (d) a CI job that exercises both.

## When it applies

- Every new controller or route: parity audit + Pest test required.
- Every new user-visible flow (auth, license, quota, portal, admin): Playwright spec required.
- Every schema change: seeder update + migration/seed idempotency test required.
- Every merge to main: backend + frontend e2e workflows must be green.

## Deliverable this turn

`.lovable/plans/pending/10-e2e-tests-and-cicd.md` with exactly 50 steps and subtasks under `.lovable/plans/subtasks/10-e2e-tests-and-cicd/`.
