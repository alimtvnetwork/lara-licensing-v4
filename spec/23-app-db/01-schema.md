# App DB Schema: LaraLicensingV1

**Version:** 0.19.0
**Updated:** 2026-07-20

Migration order, FK topology, and seed sequence are normative in [`02-migration-order.md`](./02-migration-order.md).

---

## Conventions

- PascalCase for tables, columns, indexes, JSON keys.
- PK: `PascalCaseTableName + Id`, auto-increment `BIGINT UNSIGNED`.
- FK columns named after the referenced table PK.
- `Type`, `Status`, `Category`, `Kind` are small integer FKs to lookup tables and modeled as Enums in code.
- Boolean columns prefixed `Is` or `Has`, `TINYINT(1) NOT NULL DEFAULT 0`.
- Timestamps: `CreatedAt`, `UpdatedAt`, optional `DeletedAt` for soft delete, all `DATETIME` UTC.
- New columns in later migrations MUST be NULLable with no DEFAULT (per core Rule 12).

---

## Users, Roles, Tenants

### `Users`

| Column | Type | Notes |
|--------|------|-------|
| `UserId` | BIGINT UNSIGNED PK | |
| `Email` | VARCHAR(255) UNIQUE | |
| `PasswordHash` | VARCHAR(255) | Argon2id. |
| `TenantId` | BIGINT UNSIGNED NULL FK `Resellers` | Null for Admin. |
| `IsActive` | TINYINT(1) | |
| `CreatedAt`, `UpdatedAt`, `DeletedAt` | DATETIME | |

### `Roles`

| Column | Type | Notes |
|--------|------|-------|
| `RoleId` | BIGINT UNSIGNED PK | |
| `RoleName` | VARCHAR(32) UNIQUE | `Admin`, `Reseller`, `AppBuilder`, `EndUser`. |

### `UserRoles`

Composite unique (`UserId`, `RoleId`).

| Column | Type |
|--------|------|
| `UserRoleId` | BIGINT UNSIGNED PK |
| `UserId` | FK `Users` |
| `RoleId` | FK `Roles` |
| `CreatedAt` | DATETIME |

### `Permissions`

Canonical `PermissionKey` catalog. Row set MUST equal [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §2 verbatim; drift fails CI at the parity test (`AC-PERM-006`, `AC-PERM-007`).

| Column | Type | Notes |
|--------|------|-------|
| `PermissionId` | BIGINT UNSIGNED PK | |
| `PermissionKey` | VARCHAR(64) UNIQUE NOT NULL | PascalCase `Domain.Action`. |
| `Description` | VARCHAR(255) NOT NULL | Matches §2 description. |
| `CreatedAt` | DATETIME NOT NULL | |

GRANTs: `GRANT SELECT ON Permissions TO authenticated; GRANT ALL ON Permissions TO service_role;`. `anon` has no access. RLS enabled with a single policy: `SELECT` allowed to any authenticated user (the catalog is not sensitive; grants are on `RolePermissions`/`UserPermissions`).

### `RolePermissions`

Default permission grants per role. Row set materializes [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §3. Composite unique (`RoleId`, `PermissionId`).

| Column | Type | Notes |
|--------|------|-------|
| `RolePermissionId` | BIGINT UNSIGNED PK | |
| `RoleId` | FK `Roles` ON DELETE RESTRICT | |
| `PermissionId` | FK `Permissions` ON DELETE RESTRICT | |
| `CreatedAt` | DATETIME NOT NULL | |

GRANTs: `GRANT SELECT ON RolePermissions TO authenticated; GRANT ALL ON RolePermissions TO service_role;`. RLS enabled; SELECT policy: any authenticated user MAY read (needed by `has_permission()`); INSERT/UPDATE/DELETE denied to `authenticated` and permitted only via `service_role` or a security-definer function invoked from an Admin-guarded endpoint carrying `Permissions.Assign`.

### `UserPermissions`

Per-user permission override layer. Composite unique (`UserId`, `PermissionId`). A row with `Grant=false` revokes a permission that a role grant would otherwise imply; a row with `Grant=true` grants a permission that no role grant supplies.

| Column | Type | Notes |
|--------|------|-------|
| `UserPermissionId` | BIGINT UNSIGNED PK | |
| `UserId` | FK `Users` ON DELETE CASCADE | Override dies with the user. |
| `PermissionId` | FK `Permissions` ON DELETE RESTRICT | Deleting a permission with live overrides is blocked. |
| `Grant` | BOOLEAN NOT NULL | `TRUE` = additive grant, `FALSE` = subtractive revoke. |
| `Reason` | VARCHAR(255) NOT NULL | Free-text justification recorded in the `PermissionOverrideChanged` audit row. |
| `CreatedAt` | DATETIME NOT NULL | |
| `CreatedByUserId` | FK `Users` ON DELETE RESTRICT | The Admin who wrote the override. |

GRANTs: `GRANT SELECT ON UserPermissions TO authenticated; GRANT ALL ON UserPermissions TO service_role;`. RLS enabled with two SELECT policies: (a) a user MAY read their own rows (`UserId = auth.user_id()`); (b) users holding `Permissions.Assign` MAY read all rows. INSERT/UPDATE/DELETE denied to `authenticated`; writes flow through a security-definer function that requires `Permissions.Assign` and writes a `PermissionOverrideChanged` audit row in the same transaction (`AC-PERM-010`).


### `AuthSessions`

Refresh-token family storage. Normative lifecycle: [`../21-app/31-auth-session-family.md`](../21-app/31-auth-session-family.md). Impersonation contract: [`../21-app/46-impersonation.md`](../21-app/46-impersonation.md).

| Column | Type | Notes |
|--------|------|-------|
| `SessionId` | BIGINT UNSIGNED PK | |
| `FamilyId` | CHAR(26) NOT NULL | ULID, shared across a rotation chain. Indexed. |
| `UserId` | FK `Users` NOT NULL | Indexed. For `Kind = Impersonation` rows this is the impersonated (target) user, so RLS scopes to the target. |
| `Kind` | TINYINT UNSIGNED NOT NULL DEFAULT 1 | Closed enum: 1=Normal, 2=Impersonation. Additive-only; new values require a migration and a `28-audit-action-enum.md` companion entry when they carry distinct audit semantics. |
| `ImpersonatorUserId` | BIGINT UNSIGNED NULL FK `Users` ON DELETE RESTRICT | NOT NULL when `Kind = 2 (Impersonation)`; NULL for all other kinds. Names the real Admin operator per [`../21-app/46-impersonation.md`](../21-app/46-impersonation.md) §3. Enforced by CHECK: `((Kind = 2 AND ImpersonatorUserId IS NOT NULL) OR (Kind <> 2 AND ImpersonatorUserId IS NULL))`. |
| `ParentSessionId` | BIGINT UNSIGNED NULL FK `AuthSessions` | NULL for family root. For `Kind = 2` rows this is the operator's active Normal session and MUST NOT be NULL (CHECK: `(Kind <> 2 OR ParentSessionId IS NOT NULL)`). |
| `ReplacedBySessionId` | BIGINT UNSIGNED NULL FK `AuthSessions` | Set when this row is rotated. |
| `RefreshTokenHash` | CHAR(64) UNIQUE NOT NULL | SHA-256 hex lower-case of the opaque refresh token. |
| `Jti` | CHAR(26) UNIQUE NOT NULL | Access-token `Jti` bound to this session. |
| `IssuedAt` | DATETIME NOT NULL | |
| `ExpiresAt` | DATETIME NOT NULL | Sliding: `IssuedAt + 30d` for `Kind = 1`. For `Kind = 2` capped at `IssuedAt + 30 minutes` and never extended (spec/21-app/46-impersonation.md §3). |
| `RevokedAt` | DATETIME NULL | Set on rotation, explicit revoke, reuse detection, family evict, or impersonation end. |
| `RevokeReason` | TINYINT UNSIGNED NULL | Enum: 1=Rotated, 2=ExplicitRevoke, 3=ReuseDetected, 4=FamilyRevoked, 5=EvictedByCap, 6=ImpersonationOperatorEnded, 7=ImpersonationTimeout, 8=ImpersonationAdminForced. Values 6..8 only valid when `Kind = 2`. |
| `IpHash` | CHAR(64) NOT NULL | SHA-256 hex of client IP concatenated with the salt from `PiiHashSalts` (purpose=1) active at `CreatedAt`. Raw IP MUST NOT be stored. See [`../21-app/32-auth-session-retention.md`](../21-app/32-auth-session-retention.md). |
| `UserAgentHash` | CHAR(64) NOT NULL | SHA-256 hex of UA concatenated with the `PiiHashSalts` (purpose=2) salt active at `CreatedAt`. Raw UA MUST NOT be stored. |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

Indexes: `(UserId, RevokedAt, ExpiresAt)` for session-cap queries, `(FamilyId)` for cascade revocation, `(ImpersonatorUserId, RevokedAt)` filtered on `Kind = 2` to enforce the one-active-impersonation-per-operator invariant (AC-IMP-004) and to power operator-history audit queries (spec/21-app/46-impersonation.md §5).

### `PiiHashSalts`

Rotating salts for `AuthSessions.IpHash` and `UserAgentHash`. Normative lifecycle: [`../21-app/32-auth-session-retention.md`](../21-app/32-auth-session-retention.md).

| Column | Type | Notes |
|--------|------|-------|
| `SaltId` | BIGINT UNSIGNED PK | |
| `SaltPurpose` | TINYINT UNSIGNED NOT NULL | Enum: 1=`AuthSessionIp`, 2=`AuthSessionUserAgent`. |
| `SaltBytes` | VARBINARY(32) NOT NULL | 32 random bytes from a CSPRNG. MUST NOT be logged. |
| `ActiveFrom` | DATETIME NOT NULL | |
| `ActiveUntil` | DATETIME NULL | Exactly one row per `SaltPurpose` has this NULL at any moment. |
| `CreatedAt` | DATETIME | |

Indexes: partial unique on `(SaltPurpose)` where `ActiveUntil IS NULL`, plus `(SaltPurpose, ActiveFrom)` for point-in-time lookup.





### `Resellers`

| Column | Type | Notes |
|--------|------|-------|
| `ResellerId` | BIGINT UNSIGNED PK | |
| `ResellerName` | VARCHAR(128) UNIQUE | |
| `ContactEmail` | VARCHAR(255) | |
| `IsActive` | TINYINT(1) | |
| `CreatedAt`, `UpdatedAt`, `DeletedAt` | DATETIME | |

### `Prefixes`

| Column | Type | Notes |
|--------|------|-------|
| `PrefixId` | BIGINT UNSIGNED PK | |
| `ResellerId` | FK `Resellers` | |
| `PrefixValue` | VARCHAR(12) UNIQUE | Uppercase alnum. |
| `IsActive` | TINYINT(1) | |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

### `LicenseTiers` (lookup, stub)

Normative contract: [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md) (created by Plan 05 Step 21). This row is a stub declared here so that `ResellerQuotas` and future `Licenses.LicenseTierId` FKs resolve; column semantics, canonical enum values, and forbidden synonyms are owned by the linked spec file.

| Column | Type | Notes |
|--------|------|-------|
| `LicenseTierId` | SMALLINT UNSIGNED PK | |
| `TierName` | VARCHAR(16) UNIQUE NOT NULL | Canonical set: `Tier1`, `Tier2`, `Tier3`, `Unlimited`. Enforced by CHECK. |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

### `Environments` (lookup)

Normative contract: [`../21-app/44-environments.md`](../21-app/44-environments.md) (created by Plan 05 Step 25). Owns the closed set of deployment stages a license MAY be issued for; every `Licenses` row carries a non-null `EnvironmentId` and the verify path in [`../21-app/11-api-contracts/03-verification-contracts.md`](../21-app/11-api-contracts/03-verification-contracts.md) uses it to reject cross-environment probes with `EnvironmentMismatch` (409).

| Column | Type | Notes |
|--------|------|-------|
| `EnvironmentId` | SMALLINT UNSIGNED PK | Ordinals 1..3 seeded; 4..7 reserved per [`../21-app/44-environments.md`](../21-app/44-environments.md) §2. |
| `EnvironmentName` | VARCHAR(16) UNIQUE NOT NULL | Canonical set: `Production`, `Staging`, `Development`. Enforced by CHECK constraint `CkEnvironmentsMemberSet` (AC-LENV-001). |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

GRANTs: `GRANT SELECT ON Environments TO authenticated; GRANT ALL ON Environments TO service_role;`. `anon` has no access. RLS enabled with a single policy: `SELECT` allowed to any authenticated user (the catalog is not sensitive).

Acceptance:

- AC-LENV-001 (owner: [`../21-app/44-environments.md`](../21-app/44-environments.md)) is enforced physically by `CkEnvironmentsMemberSet`; the schema test that exercises it lives in the CI harness declared by AC-LENV-001 itself.


### `Features` (lookup)

Normative contract: [`../21-app/45-license-features.md`](../21-app/45-license-features.md) §2. Closed catalog of `FeatureKey` values and their `ValueType`. Referenced by `TierFeatures` and `LicenseFeatures`.

| Column | Type | Notes |
|--------|------|-------|
| `FeatureId` | SMALLINT UNSIGNED PK | |
| `FeatureKey` | VARCHAR(64) UNIQUE NOT NULL | PascalCase, dot-segmented. Enforced by CHECK constraint `CkFeaturesKeyPattern` (regex per [`../21-app/24-vocabulary-normalization.md`](../21-app/24-vocabulary-normalization.md)). |
| `ValueType` | ENUM('Boolean','Number','String') NOT NULL | Closed set per [`../21-app/45-license-features.md`](../21-app/45-license-features.md) §3. |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

GRANTs: `GRANT SELECT ON Features TO authenticated; GRANT ALL ON Features TO service_role;`. `anon` has no access. RLS enabled with a single `SELECT` policy allowed to any authenticated user.

### `TierFeatures`

Normative contract: [`../21-app/45-license-features.md`](../21-app/45-license-features.md) §4. Tier-default layer of the precedence resolution.

| Column | Type | Notes |
|--------|------|-------|
| `TierFeatureId` | BIGINT UNSIGNED PK | |
| `LicenseTierId` | FK `LicenseTiers` NOT NULL | Composite UNIQUE `(LicenseTierId, FeatureId)`. |
| `FeatureId` | FK `Features` NOT NULL | |
| `Value` | JSON NOT NULL | Shape MUST match `Features.ValueType`; enforced by trigger `TrgTierFeaturesValueShape` (AC-FEAT-002). |
| `CreatedBy` | FK `Users` NOT NULL | |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

GRANTs: `GRANT SELECT, INSERT, UPDATE, DELETE ON TierFeatures TO authenticated; GRANT ALL ON TierFeatures TO service_role;`. RLS: `SELECT` allowed to any authenticated user; `INSERT`/`UPDATE`/`DELETE` gated by `has_permission(auth.user_id(), 'Licenses.Update') AND has_permission(auth.user_id(), 'Roles.Assign')` per [`../21-app/40-permissions.md`](../21-app/40-permissions.md) and [`../21-app/45-license-features.md`](../21-app/45-license-features.md) §5.

### `LicenseFeatures`

Normative contract: [`../21-app/45-license-features.md`](../21-app/45-license-features.md) §4. Per-license override layer; strictly higher precedence than `TierFeatures`.

| Column | Type | Notes |
|--------|------|-------|
| `LicenseFeatureId` | BIGINT UNSIGNED PK | |
| `LicenseId` | FK `Licenses` NOT NULL ON DELETE CASCADE | Composite UNIQUE `(LicenseId, FeatureId)`. Cascade because an override is meaningless without its license. |
| `FeatureId` | FK `Features` NOT NULL | |
| `Value` | JSON NOT NULL | Shape MUST match `Features.ValueType`; enforced by trigger `TrgLicenseFeaturesValueShape` (AC-FEAT-002). |
| `CreatedBy` | FK `Users` NOT NULL | |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

GRANTs: `GRANT SELECT, INSERT, UPDATE, DELETE ON LicenseFeatures TO authenticated; GRANT ALL ON LicenseFeatures TO service_role;`. RLS: `SELECT` scoped by license visibility (reseller sees own via `Licenses.ResellerId = auth.reseller_id()`, admin sees all); write paths gated by `has_permission(auth.user_id(), 'Licenses.Update')`.

Acceptance:

- AC-FEAT-001, AC-FEAT-002, AC-FEAT-004, AC-FEAT-005 (owner: [`../21-app/45-license-features.md`](../21-app/45-license-features.md)) are enforced physically by `CkFeaturesKeyPattern`, `TrgTierFeaturesValueShape`, `TrgLicenseFeaturesValueShape`, and the composite UNIQUE constraints above.



### `ResellerQuotas`

Normative contract: [`../21-app/41-reseller-quotas.md`](../21-app/41-reseller-quotas.md) §3. Every issue by a `Reseller` MUST decrement exactly one row here in the same transaction as the `Licenses` INSERT, and rollback on any failure. Admin-issued licenses do NOT touch this table.

| Column | Type | Notes |
|--------|------|-------|
| `ResellerQuotaId` | BIGINT UNSIGNED PK | |
| `ResellerId` | FK `Resellers` NOT NULL | RLS: `ResellerId = auth.reseller_id()` for `Reseller` role; unrestricted for `Admin`. |
| `LicenseCategoryId` | FK `LicenseCategories` NOT NULL | |
| `LicenseTierId` | FK `LicenseTiers` NOT NULL | |
| `LicensesGranted` | BIGINT UNSIGNED NOT NULL | Monotonically non-decreasing within a period. Only writable by `Admin` via `42-quota-requests.md` approval path. |
| `LicensesConsumed` | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Incremented only by the transactional decrement contract; restored only by ledger-reversing `QuotaRestored`. |
| `PeriodStart` | DATETIME NOT NULL | Inclusive UTC. |
| `PeriodEnd` | DATETIME NULL | Exclusive UTC. NULL = open-ended. |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

Unique index (`ResellerId`, `LicenseCategoryId`, `LicenseTierId`, `PeriodStart`). Index (`ResellerId`, `PeriodEnd`) for expiry sweeps.

CHECK constraints:

- `CkResellerQuotasConsumedNotOver`: `LicensesConsumed <= LicensesGranted`. Enforces `41-reseller-quotas.md` §3 invariant and is the last-line defense behind the `SELECT ... FOR UPDATE` decrement.
- `CkResellerQuotasPeriodOrder`: `PeriodEnd IS NULL OR PeriodEnd > PeriodStart`.

RLS policies (per [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §3). `UPDATE` is split so that no single wire path can move both `LicensesGranted` and `LicensesConsumed` in the same statement, keeping the two mutation surfaces auditable and testable independently:

- `SELECT`: `has_role(auth.uid(), 'Admin') OR has_permission(auth.uid(), 'Quotas.Approve') OR ResellerId = auth.reseller_id()`.
- `INSERT`: `has_role(auth.uid(), 'Admin') AND has_permission(auth.uid(), 'Quotas.Approve')`. New quota rows are provisioned only by the approval workflow or by an Admin adjust; a `Reseller` caller MUST NOT create quota rows.
- `UPDATE (Allowance)`: policy `ResellerQuotasUpdateAllowance` gates any statement that changes `LicensesGranted` on `has_permission(auth.uid(), 'Quotas.Approve') OR has_permission(auth.uid(), 'Quotas.Adjust')` AND requires that `LicensesConsumed` in the row is unchanged (enforced by trigger `TrgResellerQuotasAllowanceGuard`). This is the wire surface for [`../21-app/42-quota-requests.md`](../21-app/42-quota-requests.md) §Approval obligations step 3 and §Adjustment path.
- `UPDATE (Consumed)`: policy `ResellerQuotasUpdateConsumed` gates any statement that changes `LicensesConsumed` on `has_permission(auth.uid(), 'Licenses.Create')` AND `ResellerId = auth.reseller_id()` AND requires that `LicensesGranted` in the row is unchanged (same trigger). This is the wire surface for [`../21-app/41-reseller-quotas.md`](../21-app/41-reseller-quotas.md) §4 transactional decrement.
- `DELETE`: forbidden in v1; retention is period-based, not row-delete.

Grants (Rule: every `public` table in this schema declares its Data API grants inline):

```sql
GRANT SELECT, INSERT, UPDATE ON public.reseller_quotas TO authenticated;
GRANT ALL ON public.reseller_quotas TO service_role;
```

`anon` receives no grant on this table.

Acceptance:

- AC-ADB-011: The `UPDATE` split forbids any single statement from changing both `LicensesGranted` and `LicensesConsumed` (trigger `TrgResellerQuotasAllowanceGuard` raises `RQ_UPDATE_SPLIT_VIOLATION`), forbids a `Reseller` caller with `Licenses.Create` from touching `LicensesGranted` on any row, forbids an approver with `Quotas.Approve` but without `Licenses.Create` from touching `LicensesConsumed`, and forbids either update on a foreign `ResellerId` for the reseller-scoped path. Each denied case is verified in a CI policy test with the exact policy name in the PostgreSQL error and the exact trigger name for the split-violation case; success cases are also asserted so the split is not a silent deny-all.

### `QuotaRequests`

Approval workflow row owned by [`../21-app/42-quota-requests.md`](../21-app/42-quota-requests.md). Precedes `ResellerQuotaLedger` in migration order so that `ResellerQuotaLedger.QuotaRequestId` FK resolves.

| Column | Type | Notes |
|--------|------|-------|
| `QuotaRequestId` | BIGINT UNSIGNED PK | |
| `ResellerId` | FK `Resellers` NOT NULL | |
| `LicenseCategoryId` | FK `LicenseCategories` NOT NULL | |
| `LicenseTierId` | FK `LicenseTiers` NOT NULL | |
| `RequestedDelta` | INT NOT NULL | Signed. CHECK `RequestedDelta <> 0`. Positive for grow, negative for shrink. |
| `ApprovedDelta` | INT NULL | Set on `Approved`; NULL otherwise. CHECK `Status <> 2 OR ApprovedDelta IS NOT NULL`. |
| `Status` | SMALLINT UNSIGNED NOT NULL | Enum-backed per SA-031. CHECK IN (1,2,3,4) mapping to `Pending`,`Approved`,`Denied`,`Cancelled` per [`../21-app/42-quota-requests.md`](../21-app/42-quota-requests.md) §State machine. Ids 5+ reserved. |
| `Justification` | VARCHAR(1024) NOT NULL | Free text supplied by submitter. |
| `DenialReason` | VARCHAR(1024) NULL | Required when `Status = 3`; enforced by CHECK. |
| `SubmittedByUserId` | FK `Users` NOT NULL | |
| `DecidedByUserId` | FK `Users` NULL | Approver or denier. Required when `Status IN (2,3)`; enforced by CHECK. |
| `SubmittedAt` | DATETIME NOT NULL DEFAULT NOW() | |
| `DecidedAt` | DATETIME NULL | Required when `Status IN (2,3,4)`; enforced by CHECK. |
| `RequestId` | CHAR(26) NOT NULL | ULID from submit `X-Request-Id`; joins to `AuditLogs.RequestId`. |
| `IdempotencyKey` | CHAR(32) NOT NULL | Per [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md). Unique per `(ResellerId, SubmittedByUserId)`. |

CHECK constraints:

- `CkQuotaRequestsStatusRange`: `Status IN (1,2,3,4)`.
- `CkQuotaRequestsApprovedDelta`: `Status <> 2 OR ApprovedDelta IS NOT NULL`.
- `CkQuotaRequestsDenialReason`: `Status <> 3 OR DenialReason IS NOT NULL`.
- `CkQuotaRequestsDecidedBy`: `(Status IN (2,3) AND DecidedByUserId IS NOT NULL) OR Status NOT IN (2,3)`.
- `CkQuotaRequestsDecidedAt`: `(Status IN (2,3,4) AND DecidedAt IS NOT NULL) OR Status = 1`.
- `CkQuotaRequestsDeltaNonzero`: `RequestedDelta <> 0`.

Indexes: (`ResellerId`, `Status`, `SubmittedAt`), (`Status`, `SubmittedAt`) for approver queue scans, UNIQUE (`ResellerId`, `SubmittedByUserId`, `IdempotencyKey`), (`RequestId`).

Grants:

```sql
GRANT SELECT, INSERT, UPDATE ON public.quota_requests TO authenticated;
GRANT ALL ON public.quota_requests TO service_role;
```

`anon` receives no grant. No `DELETE` grant: terminal rows are historical and are only archived by service_role jobs.

RLS:

- `SELECT`: `has_role(auth.uid(), 'Admin') OR has_permission(auth.uid(), 'Quotas.Approve') OR ResellerId = auth.reseller_id()`.
- `INSERT`: `has_permission(auth.uid(), 'Quotas.Request') AND (has_role(auth.uid(), 'Admin') OR ResellerId = auth.reseller_id()) AND Status = 1 AND SubmittedByUserId = auth.uid()`.
- `UPDATE`: split into two policies. Approve/deny requires `has_permission(auth.uid(), 'Quotas.Approve')` AND row `Status = 1`. Cancel requires `ResellerId = auth.reseller_id()` AND `SubmittedByUserId = auth.uid()` AND row `Status = 1` AND new `Status = 4`.

Acceptance:

- AC-ADB-007: `CkQuotaRequestsStatusRange` and the four transition CHECKs together forbid any illegal combination of `Status` and its dependent columns; verified by a CI schema test that attempts each forbidden combination and expects a CHECK violation with the exact constraint name.
- AC-ADB-008: The UPDATE RLS split forbids a reseller from approving or denying, and forbids an approver from cancelling on behalf of a reseller; verified by a policy-level test in CI.
- AC-ADB-009: The `S2` seed inserts `Quotas.Request`, `Quotas.Approve`, and `Quotas.Adjust` into `Permissions` with the exact `PermissionKey` strings from [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §2; the `S3` seed grants `Quotas.Request` to `Reseller` and `ResellerAdmin`, and grants `Quotas.Approve`/`Quotas.Adjust` to `Admin` only, matching [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §3 verbatim. Verified by a boot test that queries `Permissions` and `RolePermissions` and by `linter-scripts/check-endpoint-permission-parity.py`.
- AC-ADB-010: A CI policy test exercises the four `QuotaRequests` RLS obligations: (a) a `Reseller` caller MAY `INSERT` a `Status=1` row for their own `ResellerId` and MUST NOT `INSERT` for a foreign `ResellerId`; (b) a `Reseller` caller MAY `UPDATE` their own `Pending` row to `Status=4` (Cancel) and MUST NOT `UPDATE` to `Status=2` or `Status=3`; (c) a caller holding `Quotas.Approve` MAY `UPDATE` `Status=1` rows to `Status=2` or `Status=3` and MUST NOT `UPDATE` to `Status=4`; (d) `SELECT` returns own-reseller rows to a `Reseller` caller and all rows to an `Admin` or `Quotas.Approve` holder. Each denied case expects a PostgreSQL RLS violation, not a silent zero-row result.



### `ResellerQuotaLedger`

Append-only journal owned by [`../21-app/41-reseller-quotas.md`](../21-app/41-reseller-quotas.md) §5. Invariant `SUM(Delta) = -LicensesConsumed` per `(ResellerId, LicenseCategoryId, LicenseTierId)` is enforced by Check 22 of [`../21-app/99-consistency-report.md`](../21-app/99-consistency-report.md).

| Column | Type | Notes |
|--------|------|-------|
| `ResellerQuotaLedgerId` | BIGINT UNSIGNED PK | |
| `ResellerId` | FK `Resellers` NOT NULL | |
| `LicenseCategoryId` | FK `LicenseCategories` NOT NULL | |
| `LicenseTierId` | FK `LicenseTiers` NOT NULL | |
| `LedgerAction` | ENUM(`QuotaConsumed`,`QuotaRestored`,`QuotaAdjusted`) NOT NULL | Values are canonical audit actions per [`../21-app/28-audit-action-enum.md`](../21-app/28-audit-action-enum.md) (row addition scheduled by Plan 05 Step 13). |
| `Delta` | SMALLINT NOT NULL | `-1` for consume; `+1` for restore; signed integer for adjust. CHECK `Delta <> 0`. |
| `LicenseId` | FK `Licenses` NULL | Required when `LedgerAction IN ('QuotaConsumed','QuotaRestored')`; enforced by CHECK. |
| `QuotaRequestId` | FK `QuotaRequests` NULL | Required when `LedgerAction = 'QuotaAdjusted'`. FK is declared but the referenced table is added by Plan 05 Step 15; until then, no `QuotaAdjusted` rows can be written. |
| `RequestId` | CHAR(26) NOT NULL | ULID from `X-Request-Id`; joins to `AuditLogs.RequestId`. |
| `ActorUserId` | FK `Users` NOT NULL | The user who caused the row (Admin approver for `QuotaAdjusted`, issuer for `QuotaConsumed`). |
| `CreatedAt` | DATETIME NOT NULL DEFAULT NOW() | Append-only; no `UpdatedAt`, no `DeletedAt`. |

CHECK constraints:

- `CkQuotaLedgerConsumeLicense`: `(LedgerAction NOT IN ('QuotaConsumed','QuotaRestored')) OR (LicenseId IS NOT NULL)`.
- `CkQuotaLedgerAdjustRequest`: `(LedgerAction <> 'QuotaAdjusted') OR (QuotaRequestId IS NOT NULL)`.
- `CkQuotaLedgerDeltaSign`: `(LedgerAction = 'QuotaConsumed' AND Delta = -1) OR (LedgerAction = 'QuotaRestored' AND Delta = 1) OR (LedgerAction = 'QuotaAdjusted' AND Delta <> 0)`.

Indexes: (`ResellerId`, `LicenseCategoryId`, `LicenseTierId`, `CreatedAt`), (`RequestId`), (`LicenseId`) partial where `LicenseId IS NOT NULL`.

Append-only enforcement: no `UPDATE` or `DELETE` grants; retention is age-based via a separate archival job that COPY-then-DELETE inside a transaction (not part of v1).

Grants:

```sql
GRANT SELECT, INSERT ON public.reseller_quota_ledger TO authenticated;
GRANT ALL ON public.reseller_quota_ledger TO service_role;
```

`anon` receives no grant. `UPDATE` and `DELETE` are intentionally not granted to `authenticated`; the append-only invariant is enforced by grant absence AND by RLS.

RLS:

- `SELECT`: `has_role(auth.uid(), 'Admin') OR ResellerId = auth.reseller_id()`.
- `INSERT`: `has_role(auth.uid(), 'Admin') OR (ResellerId = auth.reseller_id() AND LedgerAction = 'QuotaConsumed' AND ActorUserId = auth.uid())`.

Acceptance:

- AC-ADB-012: A CI schema test asserts append-only enforcement end-to-end: (a) `UPDATE public.reseller_quota_ledger` and `DELETE FROM public.reseller_quota_ledger` by any `authenticated` role fail with a PostgreSQL "permission denied" error, proving the grant absence in §Grants is not silently overridden by a later migration; (b) after every migration and after any transactional decrement or adjustment path exercised by other CI tests, the invariant `SUM(Delta) = -LicensesConsumed` holds per `(ResellerId, LicenseCategoryId, LicenseTierId)` for every row of `ResellerQuotas`, matching Check 22 of [`../21-app/99-consistency-report.md`](../21-app/99-consistency-report.md); a single row that violates the invariant fails the test with the exact `(ResellerId, LicenseCategoryId, LicenseTierId)` tuple and both sides of the equation in the failure message.

---

## License Model


### `LicenseCategories` (lookup)

| Column | Type | Notes |
|--------|------|-------|
| `LicenseCategoryId` | SMALLINT UNSIGNED PK | |
| `CategoryName` | VARCHAR(16) UNIQUE | `Daily`, `Weekly`, `Monthly`, `Yearly`, `Lifetime`, `Dev`, `Key`. |
| `CategoryCode` | CHAR(1) UNIQUE | `D`, `W`, `M`, `Y`, `L`, `X`, `K`. |
| `DurationSeconds` | INT NULL | Null for `Lifetime` and `Key`. |

### `LicensePackages`

| Column | Type | Notes |
|--------|------|-------|
| `LicensePackageId` | BIGINT UNSIGNED PK | |
| `PackageName` | VARCHAR(64) | |
| `ResellerId` | FK `Resellers` NULL | Null for platform-wide packages. |
| `IsActive` | TINYINT(1) | |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

### `Licenses`

Normative tier vocabulary: [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md). Normative environment vocabulary: [`../21-app/44-environments.md`](../21-app/44-environments.md). Every row MUST carry a non-null `LicenseTierId` and a non-null `EnvironmentId`; Admin-issued rows are not exempt.

| Column | Type | Notes |
|--------|------|-------|
| `LicenseId` | BIGINT UNSIGNED PK | |
| `LicenseCategoryId` | FK `LicenseCategories` NOT NULL | |
| `LicenseTierId` | FK `LicenseTiers` NOT NULL | Enforces AC-LT-003. For reseller-issued rows, `(ResellerId, LicenseCategoryId, LicenseTierId)` MUST match the `ResellerQuotas` row charged in the same transaction per [`../21-app/41-reseller-quotas.md`](../21-app/41-reseller-quotas.md) §4; the equality is asserted by trigger `TrgLicensesTierMatchesQuota` on INSERT and by AC-ADB-013 below. Admin-issued rows have `ResellerId = NULL` and therefore skip the trigger but still carry a valid `LicenseTierId`. |
| `EnvironmentId` | FK `Environments` NOT NULL | Enforces AC-LENV-003. Owned by [`../21-app/44-environments.md`](../21-app/44-environments.md). Immutable after INSERT: the verify-time gate in [`../21-app/44-environments.md`](../21-app/44-environments.md) §3 relies on this row not silently switching environments; enforced by trigger `TrgLicensesEnvironmentImmutable` on UPDATE. |
| `LicensePackageId` | FK `LicensePackages` NULL | |
| `ResellerId` | FK `Resellers` NULL | Issuer. |
| `IssuedByUserId` | FK `Users` | |
| `ProductVersion` | VARCHAR(16) | `V1`, `V2`, etc. |
| `IsActive` | TINYINT(1) | |
| `IssuedAt` | DATETIME | |
| `ExpiresAt` | DATETIME NULL | Null for `Lifetime`/`Key`. |
| `CreatedAt`, `UpdatedAt`, `DeletedAt` | DATETIME | |

Indexes: (`ResellerId`, `LicenseCategoryId`, `LicenseTierId`, `IssuedAt`) supports tier-scoped reporting queries; (`EnvironmentId`, `IssuedAt`) supports environment-scoped reporting per [`../21-app/44-environments.md`](../21-app/44-environments.md) §3 reporting gate.

Acceptance:

- AC-ADB-013: Trigger `TrgLicensesTierMatchesQuota` fires `BEFORE INSERT` on `Licenses`; when `NEW.ResellerId IS NOT NULL`, it SELECTs the `ResellerQuotas` row locked in the same transaction and raises `LICENSE_TIER_QUOTA_MISMATCH` if `(NEW.ResellerId, NEW.LicenseCategoryId, NEW.LicenseTierId)` does not match. Verified in CI by a schema test that attempts a mismatched INSERT (expects the exception and zero committed rows) and by a matching INSERT (expects success and a paired `ResellerQuotaLedger` row).
- AC-ADB-014: `POST /Licenses` requests missing `LicenseTierId` fail at the validator layer (AC-LT-002) before any transaction opens; the schema NOT NULL is the last line of defense. A CI test attempts a direct SQL `INSERT INTO Licenses` without `LicenseTierId` and expects the NOT NULL error with the exact column name.
- AC-ADB-015: `Licenses.EnvironmentId` is NOT NULL and immutable after INSERT. Trigger `TrgLicensesEnvironmentImmutable` fires `BEFORE UPDATE` on `Licenses` and raises `LICENSE_ENVIRONMENT_IMMUTABLE` when `OLD.EnvironmentId <> NEW.EnvironmentId`. Verified in CI by (a) an INSERT without `EnvironmentId` that expects the NOT NULL error with the exact column name and (b) an UPDATE that flips `EnvironmentId` and expects the trigger exception with zero committed rows.





### `LicenseVariations`

One-to-one with `Licenses`.

| Column | Type | Notes |
|--------|------|-------|
| `LicenseVariationId` | BIGINT UNSIGNED PK | |
| `LicenseId` | FK `Licenses` UNIQUE | |
| `UserCount` | INT NULL | Null = unlimited. |
| `MachineCount` | INT NULL | Null = unlimited. |
| `IsSingleUse` | TINYINT(1) | Serial single-use vs multi-use. |

### `Serials`

| Column | Type | Notes |
|--------|------|-------|
| `SerialId` | BIGINT UNSIGNED PK | |
| `LicenseId` | FK `Licenses` | |
| `PrefixId` | FK `Prefixes` NULL | |
| `SerialValue` | VARCHAR(64) UNIQUE | Regex enforced. |
| `IsRevoked` | TINYINT(1) | |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

---

## Bindings

### `MachineBindings`

Normative contract: [`../21-app/30-machine-bindings.md`](../21-app/30-machine-bindings.md). Raw hardware identifiers MUST NOT be persisted; only the canonical `FingerprintHash` is stored.

| Column | Type | Notes |
|--------|------|-------|
| `MachineBindingId` | BIGINT UNSIGNED PK | |
| `LicenseId` | FK `Licenses` | |
| `FingerprintHash` | CHAR(64) | Lowercase hex SHA-256 per `30-machine-bindings.md` §"Canonical fingerprint form". |
| `FirstSeenAt` | DATETIME | |
| `LastSeenAt` | DATETIME | |
| `ReleasedAt` | DATETIME NULL | NULL while active; never cleared once set. |
| `RebindCooldownUntil` | DATETIME NULL | Set at unbind; used to reject same-hash rebinds within 15 minutes. |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

Unique index (`LicenseId`, `FingerprintHash`).

Migration from v0.1.0: drop columns `MacAddress`, `MotherboardSerial`, `MachineKey`; add `FingerprintHash CHAR(64)` NOT NULL, `ReleasedAt DATETIME NULL`, `RebindCooldownUntil DATETIME NULL`. Backfill `FingerprintHash` by hashing existing `MachineKey` values with the canonical algorithm before dropping the column. This is a breaking change and MUST run before v1.0 GA.

### `UserBindings`

| Column | Type | Notes |
|--------|------|-------|
| `UserBindingId` | BIGINT UNSIGNED PK | |
| `LicenseId` | FK `Licenses` | |
| `UserIdentifier` | VARCHAR(255) | Email or hashed IP. |
| `FirstSeenAt`, `LastSeenAt` | DATETIME | |
| `IsReleased` | TINYINT(1) | |

Unique index (`LicenseId`, `UserIdentifier`).

---

## Verification State

### `AppBuilders`

| Column | Type | Notes |
|--------|------|-------|
| `AppBuilderId` | BIGINT UNSIGNED PK | |
| `AppBuilderName` | VARCHAR(128) | |
| `ClientId` | VARCHAR(64) UNIQUE | OAuth client id. |
| `ClientSecretHash` | VARCHAR(255) | |
| `HashAlgorithm` | VARCHAR(32) | Default `HMAC-SHA256`. |
| `HashLength` | SMALLINT UNSIGNED | 4 to 128. |
| `AppBuilderSalt` | VARBINARY(64) | Per-integration secret. |
| `IsActive` | TINYINT(1) | |

### `VerifyKeys`

| Column | Type | Notes |
|--------|------|-------|
| `VerifyKeyId` | BIGINT UNSIGNED PK | |
| `LicenseId` | FK `Licenses` | |
| `SerialId` | FK `Serials` | |
| `AppBuilderId` | FK `AppBuilders` | |
| `HashKeyDigest` | VARBINARY(64) | Server's recomputed hash for audit. |
| `VerifyKeyValue` | CHAR(32) UNIQUE | |
| `IssuedAt` | DATETIME | |
| `ExpiresAt` | DATETIME | `IssuedAt + 5min`. |
| `IsConsumed` | TINYINT(1) | |
| `ConsumedAt` | DATETIME NULL | |

Index (`ExpiresAt`), unique (`VerifyKeyValue`).

---

## Audit and Rate Limiting

### `AuditLogs`

| Column | Type | Notes |
|--------|------|-------|
| `AuditLogId` | BIGINT UNSIGNED PK | |
| `ActorType` | VARCHAR(32) | `User`, `AppBuilder`, `System`. |
| `ActorId` | BIGINT UNSIGNED NULL | |
| `Action` | VARCHAR(64) | e.g. `LicenseCreated`, `SerialGenerated`, `VerifyKeyConsumed`. |
| `TargetType` | VARCHAR(64) | Table name. |
| `TargetId` | BIGINT UNSIGNED NULL | |
| `RequestId` | CHAR(26) | ULID. |
| `PayloadJson` | JSON NULL | PascalCase keys. |
| `CreatedAt` | DATETIME | |

Index (`ActorType`, `ActorId`), (`TargetType`, `TargetId`), (`CreatedAt`), (`RequestId`).

Field semantics, closed `Action` catalog, `PayloadJson` redaction rules, retention, and acceptance criteria live in [`../21-app/13-audit-logging.md`](../21-app/13-audit-logging.md). This table is the storage; that file is the contract. Security-relevant subset is dual-written to `SecurityEvents` per [`../21-app/35-security-events.md`](../21-app/35-security-events.md).

### `SecurityEvents`

Normative contract: [`../21-app/35-security-events.md`](../21-app/35-security-events.md). Dual-written with `AuditLogs` in one transaction for the closed set in §1 of that file; retained hot for 60 months.

| Column | Type | Notes |
|--------|------|-------|
| `SecurityEventId` | BIGINT UNSIGNED PK | |
| `EventType` | VARCHAR(64) NOT NULL | Closed set per `35-security-events.md` §1. |
| `Severity` | ENUM(`Info`,`Low`,`Medium`,`High`,`Critical`) NOT NULL | Fixed per `EventType` per `35-security-events.md` §3; enforced by CHECK or trigger. |
| `ActorType` | VARCHAR(32) NOT NULL | `User`, `AppBuilder`, `System`, `AnonymousActor`. |
| `ActorId` | BIGINT UNSIGNED NULL | NULL only for pre-auth failures or `System`. |
| `TargetType` | VARCHAR(64) NULL | Table name when applicable. |
| `TargetId` | BIGINT UNSIGNED NULL | |
| `RequestId` | CHAR(26) NOT NULL | Same ULID as the paired `AuditLogs` row and HTTP response. |
| `IpHash` | CHAR(64) NULL | SHA-256 via active `PiiHashSalts` row per `32-auth-session-retention.md`. |
| `UserAgentHash` | CHAR(64) NULL | Same salt policy as `IpHash`. |
| `SaltVersion` | INT UNSIGNED NULL | `PiiHashSalts.SaltVersion` active at `CreatedAt`. NOT NULL whenever `IpHash` or `UserAgentHash` is non-null. |
| `PayloadJson` | JSON NULL | Same allowlist as `AuditLogs.PayloadJson`. PascalCase keys. |
| `CreatedAt` | DATETIME NOT NULL | |

Index (`EventType`, `CreatedAt`), (`ActorType`, `ActorId`, `CreatedAt`), (`Severity`, `CreatedAt`), (`RequestId`). No unique index on `RequestId` since one request may emit multiple `EventType` rows (e.g. `AbuseBlocked` + `RateLimited`).

Append-only. No UPDATE, no DELETE outside the retention job. Read access gated by `has_role(auth.uid(), 'Admin')`; every read writes a request-log line, not another `SecurityEvents` row.



### `RateLimitBuckets`

Normative fallback contract: [`../21-app/14-rate-limiting.md`](../21-app/14-rate-limiting.md) §10. This table is the durable authority; Redis is a hot-path cache only.

| Column | Type | Notes |
|--------|------|-------|
| `RateLimitBucketId` | BIGINT UNSIGNED PK | |
| `BucketKey` | VARCHAR(128) NOT NULL | `verify:{ClientId}`, `auth:{Ip}`, `block:{Rule}:{Key}`. Never a raw email or fingerprint; keys are hashed per `14-rate-limiting.md` §1.1. |
| `WindowStartAt` | DATETIME NOT NULL | Start of the fixed window in UTC. |
| `RequestCount` | INT UNSIGNED NOT NULL | Atomic `INCREMENT` under the unique index. |
| `ExpiresAt` | DATETIME NOT NULL | `WindowStartAt + WindowSeconds`. Pruner deletes rows where `ExpiresAt < NOW() - INTERVAL 60 SECOND`. |
| `SourceLastSyncedAt` | DATETIME NULL | Timestamp of the most recent Redis -> DB flush for this row. NULL means the row was authored by the DB fallback path directly. |

Unique index (`BucketKey`, `WindowStartAt`). Index (`ExpiresAt`) for the pruner. Blocks (§4.2 of `14-rate-limiting.md`) are stored as regular rows with `BucketKey` prefixed `block:` and `ExpiresAt` set to block-end; no separate table.

### `IdempotencyRecords`

Normative lifecycle: [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md). Wire contract: [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md).

| Column | Type | Notes |
|--------|------|-------|
| `IdempotencyRecordId` | BIGINT UNSIGNED PK | |
| `IdempotencyKey` | VARCHAR(128) NOT NULL | 16-128 ASCII chars from the `Idempotency-Key` header. |
| `ActorId` | BIGINT UNSIGNED NOT NULL | `UserId` or `AppBuilderId` per `ActorType`. Bind to the caller identity so two callers cannot collide. |
| `ActorType` | TINYINT UNSIGNED NOT NULL | Enum: 1=`User`, 2=`AppBuilder`, 3=`System`. |
| `Endpoint` | VARCHAR(128) NOT NULL | Canonical route from `10-endpoints.md`, e.g. `POST /Licenses/{LicenseId}/Serials`. Template form, path params replaced with `{Name}`. |
| `RequestHashSha256` | CHAR(64) NOT NULL | Lowercase hex, canonicalization per `29-idempotency-lifecycle.md` §Canonicalization. |
| `StatusCode` | SMALLINT UNSIGNED NULL | NULL while the handler is in flight. Set on COMMIT. |
| `ResponseSnapshotJson` | JSON NULL | Exact response bytes (post-serialization), 64 KiB cap per `29-idempotency-lifecycle.md`. NULL while in flight. MUST NOT contain forbidden secrets. |
| `CreatedAt` | DATETIME NOT NULL | |
| `ExpiresAt` | DATETIME NOT NULL | `CreatedAt + 24h`. Pruner deletes rows past this, subject to the in-flight guard. |

Unique index (`ActorType`, `ActorId`, `Endpoint`, `IdempotencyKey`). Index (`ExpiresAt`) for the pruner. Index (`Endpoint`, `CreatedAt`) for observability queries.

Rule: the pruner MUST NOT delete rows where `StatusCode IS NULL`, per `29-idempotency-lifecycle.md` §Pruner safety. In-flight rows exit that state only via COMMIT (sets `StatusCode`) or ROLLBACK (deletes the row transactionally).

---

## Self-Update

Normative contract: [`../21-app/17-self-update-endpoint.md`](../21-app/17-self-update-endpoint.md).

### `AppUpdates`

Manifest row per (`Product`, `Channel`, `Version`). Materialized by `POST /Admin/AppUpdates`.

| Column | Type | Notes |
|--------|------|-------|
| `AppUpdateId` | BIGINT UNSIGNED PK | |
| `Product` | VARCHAR(64) NOT NULL | e.g. `lara-cli`. |
| `Channel` | TINYINT UNSIGNED NOT NULL | Enum: 1=`Stable`, 2=`Beta`. |
| `Version` | VARCHAR(32) NOT NULL | Semver; validated by `12-error-taxonomy.md` `ValidationInvalidVersion`. |
| `MinRequiredVersion` | VARCHAR(32) NOT NULL | Semver; hard floor for clients. |
| `ReleaseNotesUrl` | VARCHAR(512) NULL | Absolute HTTPS URL. |
| `PublishedAt` | DATETIME NOT NULL | |
| `PublishedByUserId` | FK `Users` NOT NULL | Admin who called `POST /Admin/AppUpdates`. |
| `IsYanked` | TINYINT(1) NOT NULL DEFAULT 0 | Manifest hides yanked rows; asset GETs return `UpdateAssetNotFound`. |
| `YankedAt` | DATETIME NULL | |
| `YankedByUserId` | FK `Users` NULL | |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

Unique index (`Product`, `Channel`, `Version`). Index (`Product`, `Channel`, `PublishedAt`) for latest-version lookup.

### `AppUpdateAssets`

One row per `(AppUpdateId, Platform)`. Insert order: `POST /Admin/AppUpdates/UploadTicket` reserves a pending row; the storage upload MUST complete before `POST /Admin/AppUpdates` flips `IsFinalized`.

| Column | Type | Notes |
|--------|------|-------|
| `AppUpdateAssetId` | BIGINT UNSIGNED PK | |
| `AppUpdateId` | FK `AppUpdates` NOT NULL | |
| `Platform` | TINYINT UNSIGNED NOT NULL | Enum: 1=`WindowsAmd64`, 2=`LinuxAmd64`, 3=`DarwinArm64`. |
| `SizeBytes` | BIGINT UNSIGNED NOT NULL | Echoed as manifest `SizeBytes` and asset `Content-Length`. |
| `Sha256` | CHAR(64) NOT NULL | Lowercase hex; echoed as manifest `Sha256` and asset `X-Sha256` header. |
| `StorageKey` | VARCHAR(512) NOT NULL | Opaque object-store key; MUST NOT be exposed to clients. |
| `SignatureStorageKey` | VARCHAR(512) NULL | Detached signature blob, when present. |
| `UploadTicketExpiresAt` | DATETIME NOT NULL | From `POST /Admin/AppUpdates/UploadTicket` response. |
| `IsFinalized` | TINYINT(1) NOT NULL DEFAULT 0 | Set true only after `POST /Admin/AppUpdates` verifies checksum matches upload. |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

Unique index (`AppUpdateId`, `Platform`). Index (`AppUpdateId`, `IsFinalized`) for manifest assembly.

Non-finalized rows MUST NOT appear in `GET /App/UpdateManifest` results, and `GET /App/UpdateAsset/{Version}/{Platform}` MUST return `UpdateAssetNotFound` for them.



## Diagrams

- ERD: [`01-erd.mmd`](./01-erd.mmd).
- Sequence diagrams live alongside: `02-jwt-flow.mmd`, `03-oauth-client-credentials.mmd`, `09-verify-sequence.mmd`.

---

## Acceptance

- AC-ADB-001: Every table above has a corresponding Laravel migration in a later phase.
- AC-ADB-002: New columns added post-launch are NULLable with no DEFAULT.
- AC-ADB-003: ERD renders and matches this schema 1:1.
- AC-ADB-004: `ResellerQuotas` enforces `CkResellerQuotasConsumedNotOver` at the DB layer; a synthetic `UPDATE ResellerQuotas SET LicensesConsumed = LicensesGranted + 1` in the CI schema test MUST fail with the CHECK constraint name and MUST NOT succeed via any RLS bypass, verifying the "last-line defense" claim in [`../21-app/41-reseller-quotas.md`](../21-app/41-reseller-quotas.md) §3.
- AC-ADB-005: `ResellerQuotaLedger` grants exclude `UPDATE` and `DELETE` for role `authenticated`; the append-only invariant is enforced by grant absence AND by RLS. Verified by a grants snapshot test in CI that asserts only `SELECT, INSERT` are present for `authenticated` on this table.
- AC-ADB-006: For every `(ResellerId, LicenseCategoryId, LicenseTierId)`, the DB-level invariant `SUM(ResellerQuotaLedger.Delta) = -ResellerQuotas.LicensesConsumed` holds after every committed transaction. Enforced by Check 22 of [`../21-app/99-consistency-report.md`](../21-app/99-consistency-report.md) and cross-referenced by AC-QUOTA-005.
