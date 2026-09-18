# Plan 20: Seeder E2E Fixtures & Matrix (v3.3)

Slug: seeder-e2e-fixtures
Steps: 20
Status: completed
Created: 2026-08-06

## Context
Fulfills Phase B of Plan 18 (Steps 21-40) as redefined by the v3.3 aggressive protocol. Prerequisite: Step 21 baseline recorded in `docs/testing/plan-18/step-21-seeder-baseline.json`.

## Steps

1. Read `backend/database/seeders/E2EFixturesSeeder.php` and `backend/config/lara.php` to identify missing inventory rows for Resellers, Users, and Licenses.
2. Populate `E2EFixturesSeeder.php` with deterministic inventory rows required for happy-path responses across all admin tables.
3. Split `E2EFixturesSeeder.php` logic into three profile branches: `default`, `empty`, and `error`.
4. Modify `backend/database/seeders/DatabaseSeeder.php` to read `SEED_PROFILE` environment variable and dispatch to the correct branch.
5. Implement `DemoLoginSeeder.php` producing stable Admin, Reseller, and Portal users with cost 4 bcrypt hashes.
6. Map RBAC roles in `DemoLoginSeeder.php` to ensure full capability coverage for Admin/Support/Reseller views.
7. Implement `AdminMetricsSeeder.php` with deterministic KPI counts (Resellers, Licenses, Sessions, Quota).
8. Implement `AuditLogSeeder.php` producing >= 15 activity rows for the dashboard feed.
9. Implement `AppUpdatesSeeder.php` with at least one `update.installed` row for the update banner.
10. Implement `QuotaRequestsSeeder.php` with pending/approved/rejected rows for tone testing.
11. Implement `AbuseEventsSeeder.php` with `AbuseBlocked` and `RateLimited` rows.
12. Implement `LicenseSeeder.php` with edge-case license states (expired, suspended, active).
13. Implement `UserSeeder.php` covering account management states (active, disabled).
14. Implement `ResellerSeeder.php` producing organizational hierarchy fixtures.
15. Update CI workflow `backend-e2e.yml` to include the new seeder profile matrix.
16. Author Pest test `tests/Feature/Seed/MigrationsAreIdempotentTest.php` for double-seed safety.
17. Author Pest test `tests/Feature/Seed/SeederFixtureShapeTest.php` for Eloquent cast validation.
18. Verify Playwright spec stability against the new deterministic seed profiles.
19. Perform a cross-browser verification of seeder-driven UI states (Audit, Metrics, Quotas).
20. Execute Phase B Release Ceremony: bump version to v0.680.0, update changelog, and README.
