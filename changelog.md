# Changelog

## [0.691.0] - 2026-08-10

### Added
- Completed Plan 22: Refactoring and Logging Wrapper.
- Added query wrappers for PHP/TS with automatic failure logging.
- Replaced TS string union types with explicit Enums ending in `Type`.
- Implemented explicit boolean checks (e.g. `isFail`) and eliminated `!isSuccess` patterns.
- Removed magic strings and numbers across the codebase.

## [0.690.0] - 2026-08-08

### Added
- Completed Plan 09: Fluid UI + cPanel Release.
- Added 5-step stepper wizard for license creation with quota preflight.
- Integrated `darkaonline/l5-swagger` and added OpenAPI annotations for controllers.
- Added `/api/documentation` iframe in frontend gated by Admin RBAC.
- Added `linter-scripts/check-swagger-parity.py` to enforce Swagger annotation parity.
- Added `docs/deploy/environment-matrix.md` mapping `.env` keys for cPanel setup.

## [0.682.0] - 2026-08-06

### Added
- Phase D of Plan 18 complete: Backend parity for admin abuse events.
- Implemented `admin.abuse.list` preview handler with `Query` filter support (EventType, Target, IpAddress, ResellerSlug).
- Expanded `seedAbuse()` in `src/lib/preview-seeds/default.ts` to 12 rows with deterministic metadata.
- Created `tests/preview-fixtures-abuse.test.ts` with 5 coverage cases.

### Fixed
- Updated `AdminAbuseListRequest` schema to include `Query` field.
- Fixed `tests/lara-reseller-license-preview-bridge.test.ts` to account for expanded license seeds.

## [0.680.0] - 2026-08-06


### Added
- Phase B of Plan 18 complete: Seeder & Seed-Mode Foundation.
- `E2EFixturesSeeder.php` with profile-based branching (`default`, `empty`, `error`).
- `lara.e2e_fixtures.profile` configuration in `backend/config/lara.php`.
- Comprehensive domain seeder suite: `DemoLoginSeeder`, `ResellersSeeder`, `MetricsAuditSeeder`, `QuotasLicensesSeeder`, `ServiceUsageSeeder`, `NotificationsSeeder`, `ResellerStaffSeeder`, `SettingsSeeder`, `ProductCatalogSeeder`, `PlatformStatsSeeder`, `WebhooksSeeder`, `HealthCheckSeeder`, `ApiKeysSeeder`, and `UserProfilesSeeder`.
- Deterministic inventory population for Resellers, Users, and Licenses.

### Fixed
- Renamed conflicting `isEnabled()` methods to `isE2EEnabled()` in `backend/app/Console/Commands/` to fix inheritance issues with parent `Command` class.
- Updated `ClosedSetsSeeder.php` to use `assertStringMap` for correct ordinal validation of enum codes.
