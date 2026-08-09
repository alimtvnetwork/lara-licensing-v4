# Migration Order and FK Topology

**Version:** 1.7.0
**Status:** Normative for LaraLicensingV1.
**Related:** [`01-schema.md`](./01-schema.md), [`../21-app/04-roles.md`](../21-app/04-roles.md), [`../21-app/19-user-management.md`](../21-app/19-user-management.md), [`../21-app/31-auth-session-family.md`](../21-app/31-auth-session-family.md), [`../21-app/32-auth-session-retention.md`](../21-app/32-auth-session-retention.md), [`../21-app/35-security-events.md`](../21-app/35-security-events.md).

`01-schema.md` lists tables by domain grouping, not creation order. This file fixes the exact order in which migrations MUST run so foreign keys resolve, seeded lookup data lands before its referrers, and every downstream migration boots against a valid graph. Deviation from this order is a spec violation.

---

## 1. Layers

Tables sit in seven layers. Every table in layer N MAY reference any table in layers 1..N and MUST NOT reference any table in layers N+1..7.

| Layer | Purpose | Tables |
|-------|---------|--------|
| L1 | Independent lookups and salts | `Roles`, `Permissions`, `LicenseCategories`, `LicenseTiers`, `Environments`, `Features`, `PiiHashSalts` |
| L2 | Tenants and identity | `Resellers`, `Users` |
| L3 | Identity bindings and sessions | `UserRoles`, `RolePermissions`, `UserPermissions`, `AuthSessions` |
| L4 | Domain aggregates | `Prefixes`, `LicensePackages`, `AppBuilders`, `ResellerQuotas`, `QuotaRequests` |
| L5 | Licensing core | `Licenses`, `LicenseVariations`, `Serials` |
| L6 | Runtime bindings and verification | `MachineBindings`, `UserBindings`, `VerifyKeys` |
| L7 | Cross-cutting ledgers | `AuditLogs`, `SecurityEvents`, `RateLimitBuckets`, `IdempotencyRecords`, `AppUpdates`, `AppUpdateAssets`, `ResellerQuotaLedger` |


Rationale:

- `Users.TenantId` -> `Resellers` forces `Resellers` before `Users`, even though `Users` is the more familiar root: an Admin `User` has NULL `TenantId`, but the FK still needs the parent table to exist.
- `AuthSessions` self-references (`ParentSessionId`, `ReplacedBySessionId`); place it after `Users` but the self-FK is added in the same `CREATE TABLE`.
- `PiiHashSalts` is L1 because `AuthSessions` and `SecurityEvents` both hash IP/UA against the active row and cite `SaltVersion`; the salt must exist before any hasher runs, including test seeds.
- `SecurityEvents` sits at L7 with `AuditLogs` because it dual-writes with `AuditLogs` per `35-security-events.md` §5.

---

## 2. Migration file order

File names are ULID-prefixed timestamps in the Laravel migrations directory (`YYYY_MM_DD_HHMMSS_action.php`). The frozen logical order is:

```
01  create_roles_table                     (L1)
02  create_permissions_table               (L1)
03  create_license_categories_table        (L1)
04  create_license_tiers_table             (L1)
05  create_environments_table              (L1; CHECK EnvironmentName IN ('Production','Staging','Development') per AC-LENV-001)
05a create_features_table                  (L1; CHECK CkFeaturesKeyPattern per ../21-app/45-license-features.md §2; ENUM ValueType per §3)
06  create_pii_hash_salts_table            (L1)
07  create_resellers_table                 (L2)
08  create_users_table                     (L2, FK -> Resellers nullable)
09  create_user_roles_table                (L3, FK -> Users, Roles)
10  create_role_permissions_table          (L3, FK -> Roles, Permissions)
11  create_user_permissions_table          (L3, FK -> Users, Permissions, Users [CreatedBy])
12  create_auth_sessions_table             (L3, FK -> Users, self-FK)
13  create_prefixes_table                  (L4, FK -> Resellers)
14  create_license_packages_table          (L4, FK -> Resellers nullable)
15  create_app_builders_table              (L4)
16  create_reseller_quotas_table          (L4, FK -> Resellers, LicenseCategories, LicenseTiers; CHECK Consumed<=Granted)
17  create_quota_requests_table           (L4, FK -> Resellers, LicenseCategories, LicenseTiers, Users [SubmittedBy, DecidedBy]; enum-backed Status per SA-031)
18  create_licenses_table                  (L5, FK -> LicenseCategories, LicenseTiers NOT NULL, Environments NOT NULL, LicensePackages nullable, Resellers nullable, Users; trigger TrgLicensesTierMatchesQuota per AC-ADB-013; trigger TrgLicensesEnvironmentImmutable per AC-ADB-015)
19  create_license_variations_table        (L5, FK -> Licenses UNIQUE)
19a create_tier_features_table             (L5, FK -> LicenseTiers, Features, Users [CreatedBy]; UNIQUE (LicenseTierId, FeatureId); trigger TrgTierFeaturesValueShape per AC-FEAT-002)
20  create_serials_table                   (L5, FK -> Licenses, Prefixes nullable)
20a create_license_features_table          (L5, FK -> Licenses ON DELETE CASCADE, Features, Users [CreatedBy]; UNIQUE (LicenseId, FeatureId); trigger TrgLicenseFeaturesValueShape per AC-FEAT-002)
21  create_machine_bindings_table          (L6, FK -> Licenses)
22  create_user_bindings_table             (L6, FK -> Licenses)
23  create_verify_keys_table               (L6, FK -> Licenses, Serials, AppBuilders)
24  create_audit_logs_table                (L7)
25  create_security_events_table           (L7, CHECK Severity, cites PiiHashSalts.SaltVersion)
26  create_rate_limit_buckets_table        (L7)
27  create_idempotency_records_table       (L7)
28  create_app_updates_table               (L7, FK -> Users)
29  create_app_update_assets_table         (L7, FK -> AppUpdates)
30  create_reseller_quota_ledger_table     (L7, FK -> Resellers, LicenseCategories, LicenseTiers, Licenses nullable, QuotaRequests nullable, Users; append-only)
```

After the 25 structural migrations, seed migrations run (§3). Feature migrations that ALTER these tables land after seeds with monotonically increasing timestamps.


---

## 3. Seed order

Seeds are DATA migrations, not schema migrations; they run after all structural migrations commit. Order within the seed phase:

```
S1  seed_roles                    -> insert app_role enum values into Roles
S2  seed_permissions              -> insert PermissionKey catalog from ../21-app/40-permissions.md §2; MUST include `Quotas.Request`, `Quotas.Approve`, `Quotas.Adjust` verbatim (AC-ADB-009)
S3  seed_role_permissions         -> insert default Role -> Permission grants from ../21-app/40-permissions.md §3; MUST grant `Quotas.Request` to Reseller and Reseller Admin, and `Quotas.Approve`/`Quotas.Adjust` to Admin only (AC-ADB-009)
S4  seed_license_categories       -> insert V1 category catalog
S4a seed_environments             -> insert `Production` (Ordinal=1), `Staging` (Ordinal=2), `Development` (Ordinal=3) per ../21-app/44-environments.md §2. Idempotent by natural key `EnvironmentName`. MUST run before any Licenses INSERT.
S5  seed_license_tiers             -> insert `Tier1`, `Tier2`, `Tier3`, `Unlimited` (canonical set per ../21-app/43-license-tiers.md; stub table until Step 21)
S5a seed_features                  -> insert v1 FeatureKey catalog from ../21-app/45-license-features.md §2 with matching `ValueType`. Idempotent by natural key `FeatureKey`. MUST run before S5b.
S5b seed_tier_features             -> insert tier-default `TierFeatures` rows per ../21-app/45-license-features.md §4. Idempotent by natural key `(LicenseTierId, FeatureId)`.
S6  seed_pii_hash_salts_initial   -> insert SaltVersion=1 active row
S7  seed_admin_bootstrap_user     -> only if env var LARA_BOOTSTRAP_ADMIN_EMAIL is set
S8  seed_admin_bootstrap_role     -> assign Admin role to S7 user; enforced NOT NULL by 19-user-management.md
```


Rules:

- Seed migrations are idempotent: repeated runs are no-ops. Use `INSERT ... ON DUPLICATE KEY UPDATE` or `firstOrCreate` with a natural key.
- `S3` MUST run before any code path that hashes IP/UA (test setup, integration tests, or the salt rotation job). The rotation job in `32-auth-session-retention.md` refuses to create `SaltVersion=1` if any row already exists, so bootstrap-vs-rotate confusion is impossible.
- `S4`/`S5` are gated by env var; production without the env var boots with zero users, and the first admin is provisioned out-of-band. This prevents accidental default-admin backdoors.
- Every seed emits one `SchemaMigrationApplied` audit row per `13-audit-logging.md` §"Renaming closed-set values" step 4, with `PayloadJson.Strategy = "seed"`.

---

## 4. FK topology invariants

Enforced by a CI check against the live migration graph:

- No cycle in the FK graph except the intentional `AuthSessions` self-cycle (`ParentSessionId`, `ReplacedBySessionId`), which is documented in `31-auth-session-family.md` §Family Lifecycle.
- Every FK is `ON DELETE RESTRICT` unless the referenced row's disappearance MUST cascade:
  - `UserRoles(UserId) REFERENCES Users(UserId) ON DELETE CASCADE` (a deleted user cannot retain a role).
  - `AuthSessions(UserId) REFERENCES Users(UserId) ON DELETE CASCADE` (session dies with the user).
  - `AppUpdateAssets(AppUpdateId) REFERENCES AppUpdates(AppUpdateId) ON DELETE CASCADE` (asset rows are meaningless without a manifest).
  - Every other FK is `ON DELETE RESTRICT`; deleting a `Reseller` with live `Licenses` MUST return the DB error, which the API surfaces as `ResellerInUse` per `12-error-taxonomy.md`.
- No FK is `ON DELETE SET NULL` in V1. Nullable FKs (`Licenses.LicensePackageId`, `Serials.PrefixId`, `Users.TenantId`) start nullable and stay nullable through explicit updates, not through delete cascades.

---

## 5. Rollback (`down()`) order

Reverse of §2 exactly. Every migration's `down()` drops only what its `up()` created. A rollback that leaves a dangling FK on another migration's table is a spec violation.

- Structural rollback: L7 -> L6 -> L5 -> L4 -> L3 -> L2 -> L1.
- Seed rollback: DELETE rows by natural key, never `TRUNCATE`. `PiiHashSalts` never rolls back seed row `SaltVersion=1` if any `SecurityEvents` or `AuthSessions` row references it, per `32-auth-session-retention.md` §Retention guard; rollback surfaces the guard error, not a silent skip.

---

## 6. Acceptance

- AC-MIG-001: Running the migration set from an empty database applies every file in §2 order without an FK error. Verified by a boot test.
- AC-MIG-002: Every FK column named in §2 appears in `01-schema.md` with the same referenced table.
- AC-MIG-003: `SchemaMigrationApplied` audit rows exist for every seed in §3 after a fresh boot.
- AC-MIG-004: Attempting to run seed `S1` before structural migration `01_create_roles_table` fails with a clear error, not a silent no-op.
- AC-MIG-005: The FK graph is acyclic modulo the documented `AuthSessions` self-cycle. Verified by a static graph check in CI.
- AC-MIG-006: Every FK's `ON DELETE` clause matches §4; `RESTRICT` is the default, deviations are the closed list in §4.
- AC-MIG-007: `down()` order is the exact reverse of `up()`; a full rollback returns the schema to empty with no dangling objects.
