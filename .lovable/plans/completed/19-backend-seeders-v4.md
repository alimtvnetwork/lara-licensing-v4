# Backend Seeder & Seed-Mode Foundation

Slug: backend-seeders-v4
Steps: 20
Status: completed
Created: 2026-08-06

## Context

Implementation of Phase B of Plan 18 (Steps 21-40) to provide a deterministic data surface and demo login capabilities. This plan also includes the visual text update for the admin topbar as requested in the v4.1 protocol prompt. Links to spec: `.lovable/spec/tasks/19-backend-seeders-v4.md`.

Release policy: NO version bump, NO changelog entry, NO release-notes update until Plan 18 is entirely complete (Step 200). This is a sub-plan.

## Steps

1. Update `src/components/admin/admin-shell.tsx` visual text to the v4.1 aggressive prompt provided by the user (implements Spec-19 AC-05).
2. Record existing seeder baseline in `docs/testing/seed-mode-baseline.json` under the `plan18` key (implements Spec-19 Intent).
3. Populate `E2EFixturesSeeder.php` with missing inventory rows for Resellers, Licenses, and Serials (implements Spec-19 Scope).
4. Split `E2EFixturesSeeder` into deterministic profiles: `default`, `empty`, and `error` (implements Spec-19 AC-02).
5. Wire the `SEED_PROFILE` environment variable into `DatabaseSeeder.php` for profile branching (implements Spec-19 AC-02).
6. Author `DemoLoginSeeder.php` producing stable admin, reseller, and portal identities (implements Spec-19 AC-03).
7. Document demo credentials in `docs/testing/test-data.md` and ensure they are NOT in `.env` (implements Spec-19 AC-03).
8. Optimize demo login speed by using bcrypt cost 4 in `DemoLoginSeeder.php` (implements Spec-19 Scope).
9. Provision demo admin capabilities (impersonation, audit, abuse, updates) in `DemoLoginSeeder.php` (implements Spec-19 Scope).
10. Ensure the demo reseller identity has active licenses and serials for UI verification (implements Spec-19 Scope).
11. Implement `AdminMetricsSeeder` for deterministic dashboard KPI counts to resolve red error tiles (implements Spec-19 AC-01).
12. Add `AuditWriterSeeder` producing >= 15 recent-activity rows for dashboard visibility (implements Spec-19 Scope).
13. Add `AppUpdatesSeeder` producing at least one published installed update row for banners (implements Spec-19 Scope).
14. Add `QuotaRequestsSeeder` producing pending, approved, and rejected rows for badge tone verification (implements Spec-19 Scope).
15. Add `AbuseEventsSeeder` producing `AbuseBlocked` and `RateLimited` rows for the abuse dashboard (implements Spec-19 Scope).
16. Extend `FeatureCatalogSeeder` to cover the full requirements of Spec 21 (implements Spec-19 Scope).
17. Add closed-set completeness assertions in `ClosedSetsSeeder.php` for enum round-tripping (implements Spec-19 Scope).
18. Integrate `DemoLoginSeeder` into the `backend-e2e.yml` CI workflow bootstrap (implements Spec-19 Scope).
19. Implement `MigrationsAreIdempotentTest` Pest test to verify schema stability after seeding (implements Spec-19 AC-04).
20. Implement `SeederFixtureShapeTest` Pest test asserting Eloquent cast and policy compliance for seeded rows (implements Spec-19 Scope).

## Verification

- Step 1: Visual confirmation in preview.
- Step 2: Verify JSON update in `docs/testing/seed-mode-baseline.json`.
- Step 3-17: Run `php artisan db:seed --profile=default` and inspect database rows.
- Step 18: Trigger CI or inspect `.github/workflows/backend-e2e.yml`.
- Step 19-20: Run `backend/vendor/bin/pest`.

## Appended from prior pending tasks

- Plan 18 (Steps 21-40) absorbed into this implementation plan.
- Plans 05, 06, 07, 09, 12, 13, 14 remain pending.
