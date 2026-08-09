# Laravel Backend Migration Roadmap

**Status:** Draft v0.1.0
**Owner:** Lara Engineering
**Related:** `spec/23-app-db/01-schema.md`, `spec/23-app-db/02-migration-order.md`

## 1. Executive Summary

This roadmap maps the normative database specification for LaraLicensingV1 to the Laravel implementation path. It ensures that all tables, foreign keys, RLS policies, and triggers defined in the spec are correctly implemented in the `backend/` Laravel application, maintaining parity with the preview-fixture environment.

## 2. Parity Matrix: Spec to Laravel

| Spec Table | Layer | Laravel Migration | Status | Notes |
| :--- | :--- | :--- | :--- | :--- |
| `Roles` | L1 | `create_roles_table` | Pending | Seed `Admin`, `Reseller`, `AppBuilder`, `EndUser`. |
| `Permissions` | L1 | `create_permissions_table` | Pending | Seed 13 keys from `spec/21-app/40-permissions.md`. |
| `LicenseCategories` | L1 | `create_license_categories_table` | Pending | |
| `LicenseTiers` | L1 | `create_license_tiers_table` | Pending | `Tier1`, `Tier2`, `Tier3`, `Unlimited`. |
| `Environments` | L1 | `create_environments_table` | Pending | `Production`, `Staging`, `Development`. |
| `Features` | L1 | `create_features_table` | Pending | |
| `PiiHashSalts` | L1 | `create_pii_hash_salts_table` | Pending | |
| `Resellers` | L2 | `create_resellers_table` | Partially Done | `backend/database/migrations/root/` |
| `Users` | L2 | `create_users_table` | Partially Done | `backend/database/migrations/root/` |
| `UserRoles` | L3 | `create_user_roles_table` | Pending | |
| `RolePermissions` | L3 | `create_role_permissions_table` | Pending | |
| `UserPermissions` | L3 | `create_user_permissions_table` | Pending | |
| `AuthSessions` | L3 | `create_auth_sessions_table` | Partially Done | `backend/database/migrations/root/` |
| `Prefixes` | L4 | `create_prefixes_table` | Partially Done | `backend/database/migrations/root/` |
| `LicensePackages` | L4 | `create_license_packages_table` | Pending | |
| `AppBuilders` | L4 | `create_app_builders_table` | Pending | |
| `ResellerQuotas` | L4 | `create_reseller_quotas_table` | Pending | `LicensesConsumed <= LicensesGranted`. |
| `QuotaRequests` | L4 | `create_quota_requests_table` | Done | `backend/database/migrations/shard/` |
| `Licenses` | L5 | `create_licenses_table` | Partially Done | `backend/database/migrations/shard/` |
| `LicenseVariations` | L5 | `create_license_variations_table` | Pending | |
| `TierFeatures` | L5 | `create_tier_features_table` | Pending | |
| `Serials` | L5 | `create_serials_table` | Partially Done | `backend/database/migrations/shard/` |
| `LicenseFeatures` | L5 | `create_license_features_table` | Partially Done | `backend/database/migrations/shard/` |
| `MachineBindings` | L6 | `create_machine_bindings_table` | Partially Done | `backend/database/migrations/shard/` |
| `UserBindings` | L6 | `create_user_bindings_table` | Partially Done | `backend/database/migrations/shard/` |
| `VerifyKeys` | L6 | `create_verify_keys_table` | Partially Done | `backend/database/migrations/shard/` |
| `AuditLogs` | L7 | `create_audit_logs_table` | Pending | |
| `SecurityEvents` | L7 | `create_security_events_table` | Pending | |
| `ResellerQuotaLedger` | L7 | `create_reseller_quota_ledger_table` | Partially Done | `backend/database/migrations/shard/` |

## 3. Shard vs Root Mapping

Lara uses a Reseller-shard split database architecture.

### Root Database (Global)
- `Resellers`, `Users`, `Roles`, `Permissions`, `AuthSessions` (Operator), `AppUpdates`, `AuditLogs` (Global).

### Shard Database (Per Reseller)
- `Licenses`, `Serials`, `QuotaRequests`, `ResellerQuotas`, `ResellerQuotaLedger`, `MachineBindings`, `AuditLogs` (Tenant-scoped).

## 4. Constraint & Trigger Audit

Every migration must explicitly implement the following spec-mandated invariants:

1. **`TrgLicensesTierMatchesQuota`**: `BEFORE INSERT` on `Licenses` asserts tier matches the quota row.
2. **`TrgLicensesEnvironmentImmutable`**: `BEFORE UPDATE` on `Licenses` forbids environment switching.
3. **`TrgResellerQuotasAllowanceGuard`**: Enforces the RLS split between Allowance and Consumed updates.
4. **`TrgTierFeaturesValueShape` / `TrgLicenseFeaturesValueShape`**: Asserts JSON value matches `Feature.ValueType`.

## 5. Migration Execution Strategy

1. **Phase 1: Foundation (L1-L2).** Establish the identity and lookup tables in the Root DB.
2. **Phase 2: Authorization (L3).** Implement the fine-grained RBAC and Permission overrides.
3. **Phase 3: Domain Scaffolding (L4).** Prepare the Quota and Prefix infrastructure.
4. **Phase 4: Core Shard (L5-L6).** Migration of the licensing core to the Shard-capable schema.
5. **Phase 5: Ledgers (L7).** Audit and Security event dual-writing.

## 6. Seed Parity Roadmap

1. **`S1-S3`**: Roles and Permissions must match `spec/21-app/40-permissions.md`.
2. **`S4-S5`**: Environments and Tiers must match `spec/21-app/43-license-tiers.md` and `spec/21-app/44-environments.md`.
3. **`S6`**: `PiiHashSalts` initial version.
