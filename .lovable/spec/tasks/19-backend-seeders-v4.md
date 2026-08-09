# Spec 19: Backend Seeder & Seed-Mode Foundation

Slug: backend-seeders-v4
Status: pending
Created: 2026-08-06

## Intent

Implement Phase B of Plan 18 (Steps 21-40) to establish a deterministic data foundation for the preview environment and demo login functionality. This resolves the "red error tiles" on the admin dashboard by providing a consistent set of metrics and identities.

## Scope

- Recording seeder baseline.
- Deterministic seeder profiles (`default`, `empty`, `error`).
- Stable demo login identities (admin, reseller, portal).
- Dashboard KPI seeder parity.
- Activity, quota, and abuse seeders.
- CI seeder integration and idempotency tests.

## Inputs

- `.lovable/plans/pending/18-backend-seed-login-e2e-error-manage.md` (Steps 21-40)
- `backend/database/seeders/`
- `docs/testing/seed-mode-baseline.json`
- `src/components/admin/admin-shell.tsx` (Visual text update request)

## Acceptance Criteria

- AC-01: Admin dashboard renders green KPI tiles under `default` seed.
- AC-02: `SEED_PROFILE` environment variable correctly branches seeder logic.
- AC-03: Demo identities authenticate successfully via seeder-populated rows.
- AC-04: Seeder idempotency test passes.
- AC-05: Visual text in `admin-shell.tsx` matches the requested v4.1 aggressive prompt.

## Affected Files

- `backend/database/seeders/DatabaseSeeder.php`
- `backend/database/seeders/E2EFixturesSeeder.php`
- `backend/database/seeders/DemoLoginSeeder.php` (new)
- `backend/database/seeders/AdminMetricsSeeder.php` (new)
- `backend/database/seeders/AuditWriterSeeder.php` (new)
- `backend/database/seeders/AppUpdatesSeeder.php` (new)
- `backend/database/seeders/QuotaRequestsSeeder.php` (new)
- `backend/database/seeders/AbuseEventsSeeder.php` (new)
- `backend/database/seeders/ClosedSetsSeeder.php`
- `src/components/admin/admin-shell.tsx`
- `docs/testing/seed-mode-baseline.json`
- `docs/testing/test-data.md`
- `.github/workflows/backend-e2e.yml`

## Attachments

- None.

## Ambiguities

- None.
