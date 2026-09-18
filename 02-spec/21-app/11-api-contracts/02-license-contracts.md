# License API Contracts

**Version:** 1.4.0
**Updated:** 2026-07-22

## Vocabulary sources

`PermissionKey` column values cite [`../40-permissions.md`](../40-permissions.md) §2 verbatim. Every row declares the permission the caller MUST hold after the role gate passes; on failure the endpoint returns `403 AuthzPermissionDenied` per [`../12-error-taxonomy.md`](../12-error-taxonomy.md), naming the missing `PermissionKey` in `Attributes.Error.Details`. `LicenseTierId` request-field values cite [`../43-license-tiers.md`](../43-license-tiers.md) §2 (closed set `{Tier1, Tier2, Tier3, Unlimited}`). `EnvironmentId` request-field values cite [`../44-environments.md`](../44-environments.md) §2 (closed set `{Production, Staging, Development}`).

## License resource

`LicenseResult` contains `LicenseId` integer, `LicenseCategoryId` integer, `LicenseTierId` integer, `EnvironmentId` integer, optional `LicensePackageId` integer, optional `ResellerId` integer, `IssuedByUserId` integer, `ProductVersion` string, `IsActive` boolean, `IssuedAt` timestamp, optional `ExpiresAt` timestamp, `UserCount` optional integer, `MachineCount` optional integer, and `IsSingleUse` boolean.

`LicenseTierId` and `EnvironmentId` are always echoed in the response so the caller can confirm the row was written with the intended tier and environment; neither field is optional in the response, even for Admin-issued rows.

## License endpoints

| Endpoint | PermissionKey | Request | Result | Responses |
|----------|---------------|---------|--------|-----------|
| `POST /Licenses` | `Licenses.Create` | `LicenseCategoryId`, `LicenseTierId`, `EnvironmentId`, optional `LicensePackageId`, optional `ResellerId`, `ProductVersion`, optional `UserCount`, optional `MachineCount`, `IsSingleUse` | one `LicenseResult` | `201`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `403 QuotaCategoryUnauthorized`, `409 LicenseConflict`, `409 QuotaExhausted`, `409 QuotaLedgerConflict` |
| `GET /Licenses/{LicenseId}` | `Licenses.Read` | Positive integer path id | one `LicenseResult` | `200` (with `ETag` header per [`09-concurrency-control.md`](./09-concurrency-control.md)), `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 LicenseNotFound` |
| `PATCH /Licenses/{LicenseId}` | `Licenses.Update` | At least one of `LicenseCategoryId`, `LicenseTierId`, `LicensePackageId`, `ProductVersion`, `IsActive`, `ExpiresAt`, `UserCount`, `MachineCount`, `IsSingleUse`. `EnvironmentId` is IMMUTABLE (AC-ADB-015) and MUST be rejected in the request body with `ValidationFailed` and `Details = [{ "Field": "EnvironmentId", "Rule": "Immutable" }]`. Requires `If-Match` per [`09-concurrency-control.md`](./09-concurrency-control.md) §Scope. | updated `LicenseResult` (with new `ETag`) | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 LicenseNotFound`, `409 LicenseConflict`, `412 PreconditionFailed`, `428 PreconditionRequired` |
| `DELETE /Licenses/{LicenseId}` | `Licenses.Revoke` | Positive integer path id. Requires `If-Match` per [`09-concurrency-control.md`](./09-concurrency-control.md) §Scope. | `LicenseId`, `IsDeleted=true` | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 LicenseNotFound`, `412 PreconditionFailed`, `428 PreconditionRequired` |

Unknown request keys are rejected. `PATCH` distinguishes an omitted property from an explicit `null`. Optimistic-concurrency `If-Match`/`ETag` semantics for `PATCH`, `DELETE`, and the paired feature routes are normatively fixed in [`09-concurrency-control.md`](./09-concurrency-control.md); this section defers to that file for header rules, error envelope shape, and idempotency ordering.

### Request body validation for `POST /Licenses`

Validation runs BEFORE any transaction opens and BEFORE the reseller quota decrement contract. Each rule below fails fast with `ValidationFailed` (400) and the exact `Details` shape shown:

| Field | Rule | `Details[]` on failure |
|-------|------|------------------------|
| `LicenseCategoryId` | Positive integer AND member of [`../05-license-categories.md`](../05-license-categories.md) canonical set. | `[{ "Field": "LicenseCategoryId", "Rule": "MembershipRequired" }]` |
| `LicenseTierId` | Positive integer AND member of [`../43-license-tiers.md`](../43-license-tiers.md) §2 closed set. Enforces AC-LT-002. | `[{ "Field": "LicenseTierId", "Rule": "MembershipRequired" }]` |
| `EnvironmentId` | Positive integer AND member of [`../44-environments.md`](../44-environments.md) §2 closed set. Enforces AC-LENV-002. | `[{ "Field": "EnvironmentId", "Rule": "MembershipRequired" }]` |
| `ProductVersion` | Matches `^V[0-9]{1,3}$`. | `[{ "Field": "ProductVersion", "Rule": "Format" }]` |
| `ResellerId` | Present iff caller resolves to `Reseller` role, and equals `auth.reseller_id()`. Admin MUST NOT supply `ResellerId` (Admin-issued rows have `ResellerId = NULL` per [`../41-reseller-quotas.md`](../41-reseller-quotas.md) §4). | `[{ "Field": "ResellerId", "Rule": "RowScope" }]` |

Multiple field failures produce a single `ValidationFailed` response whose `Details[]` array lists every failing field in the order above. The validator MUST NOT short-circuit on the first failure so tooling surfaces every missing field in one round-trip.

## Reseller quota decrement (POST /Licenses)

When the caller resolves to the `Reseller` role (role gate per [`../04-roles.md`](../04-roles.md) §Authorization ladder), `POST /Licenses` MUST execute the tier-aware transactional decrement contract defined verbatim in [`../41-reseller-quotas.md`](../41-reseller-quotas.md) §4. Restated here as a wire obligation, not a duplicate specification:

1. The `SELECT ... FOR UPDATE` on `ResellerQuotas` MUST resolve the row by the exact tuple `(ResellerId = auth.reseller_id(), LicenseCategoryId = <request.LicenseCategoryId>, LicenseTierId = <request.LicenseTierId>)` where `PeriodStart <= NOW() AND (PeriodEnd IS NULL OR PeriodEnd > NOW())`. The three tuple components come DIRECTLY from the validated request body plus the caller's row-scope claim; no server-side fallback to a "default tier" is permitted. If the request omits `LicenseTierId`, validation already failed in the previous section and this step is not reached.
2. The `SELECT ... FOR UPDATE`, the `INSERT` on `Licenses`, the `INSERT` on `ResellerQuotaLedger`, and the `INSERT` on `AuditLogs` MUST commit in the SAME database transaction. Partial commits are a spec violation.
3. Failure to resolve a matching `(ResellerId, LicenseCategoryId, LicenseTierId)` row returns `403 QuotaCategoryUnauthorized` with `Attributes.Error.Details = [{ "Field": "LicenseCategoryId", "Value": <categoryId> }, { "Field": "LicenseTierId", "Value": <tierId> }]`. It MUST NOT return `QuotaExhausted`; the two conditions are distinguishable per AC-ERR-007.
4. Row resolved but `LicensesRemaining <= 0` returns `409 QuotaExhausted` with `Attributes.Error.Details = [{ "Field": "LicenseCategoryId", "Value": <categoryId> }, { "Field": "LicenseTierId", "Value": <tierId> }, { "Field": "LicensesRemaining", "Value": 0 }]`. Absolute `LicensesGranted` and `LicensesConsumed` counts MUST NOT be leaked (AC-ERR-006).
5. On successful commit: exactly one `ResellerQuotaLedger` row exists with `Delta = -1`, `LedgerAction = "QuotaConsumed"`, `LicenseId = <new license id>`, `LicenseTierId = <request.LicenseTierId>`, `RequestId = <X-Request-Id>`; `LicensesConsumed` on the quota row increased by exactly 1 (AC-QUOTA-001). The ledger row's `LicenseTierId` MUST equal the request's `LicenseTierId` MUST equal the resolved `ResellerQuotas.LicenseTierId`; the trigger `TrgLicensesTierMatchesQuota` (AC-ADB-013) is the last-line defense if application code drifts.
6. Deadlock on `SELECT ... FOR UPDATE` is retried exactly once with jitter per [`../25-retry-decision-matrix.md`](../25-retry-decision-matrix.md); a second deadlock surfaces `409 QuotaLedgerConflict` with `Retry-After: 1`.
7. Admin-issued licenses (any caller resolving to the `Admin` role) MUST NOT decrement `ResellerQuotas` and MUST NOT emit `ResellerQuotaLedger` rows (AC-QUOTA-003). `LicenseTierId` is still validated as a closed-set member per AC-LT-002 and persisted on the `Licenses` row for reporting; `EnvironmentId` is validated identically per AC-LENV-002. The `Actor` recorded in `AuditLogs` is the sole distinguisher between the reseller and Admin paths.

## Idempotency and quota interaction (POST /Licenses)

`POST /Licenses` is in scope for `Idempotency-Key` per [`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md) and follows the lifecycle in [`../29-idempotency-lifecycle.md`](../29-idempotency-lifecycle.md). The `RequestHashSha256` MUST hash the canonicalized request body INCLUDING `LicenseTierId` and `EnvironmentId`; a replay that differs in either field but reuses the key is an `IdempotencyConflict` (409), not a fresh execution. The interaction with the reseller quota decrement is:

- A replay hit (matching `RequestHashSha256`, `StatusCode IS NOT NULL`) short-circuits the handler and returns the stored `ResponseSnapshotJson`. The replay MUST NOT re-execute steps 1-7 of the decrement contract; `ResellerQuotaLedger` and `LicensesConsumed` are unchanged (AC-IDL-006 + AC-QUOTA-001 together forbid double-decrement).
- A conflict hit (same key, different `RequestHashSha256`, including a different `LicenseTierId` or `EnvironmentId`) returns `409 IdempotencyConflict` per [`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md) BEFORE any quota row is touched; no ledger row is written and `LicensesConsumed` is unchanged.
- A fresh execution that fails after the `IdempotencyRecords` INSERT (§Decision tree step 5) but before COMMIT MUST ROLLBACK the entire transaction, including the ledger row and the quota UPDATE; on retry the caller sees no row and re-enters the decrement contract from step 1 (AC-IDL-003 combined with AC-QUOTA-002).
- The response snapshot (§Response snapshot in `29-idempotency-lifecycle.md`) MUST include the newly-issued `LicenseId`, `LicenseTierId`, and `EnvironmentId`; it MUST NOT include `LicensesRemaining` or any absolute quota counter, so replays never leak counts that may have changed since the original write.



## Serial endpoints

| Endpoint | PermissionKey | Request | Result | Responses |
|----------|---------------|---------|--------|-----------|
| `POST /Licenses/{LicenseId}/Serials` | `Serials.Issue` | optional `PrefixId`, optional `RandomLength` enum 16, 24, or 32 | `SerialId`, `LicenseId`, `SerialValue`, `CreatedAt` | `201`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `403 PrefixForbidden`, `404 LicenseNotFound`, `409 SerialCollisionExhausted` |
| `GET /Serials/{SerialValue}` | `Serials.Lookup` | URL-encoded serial path value | `SerialId`, `LicenseId`, `SerialValue`, `IsRevoked`, `CreatedAt` | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 SerialNotFound` |

### Idempotency (MUST)

Serial creation is idempotent only when an `Idempotency-Key` header (ULID or opaque, 16-128 chars) is supplied. The server MUST persist one row per key in `IdempotencyRecords` with the shape `{ Key, ActorId, Endpoint, RequestHashSha256, ResponseSnapshotJson, StatusCode, CreatedAt, ExpiresAt }`. `RequestHashSha256` is the SHA-256 of the canonicalized request body (PascalCase keys, sorted, no whitespace). Retention TTL is exactly 24 hours from `CreatedAt`; expired rows are pruned by the same job that prunes `RateLimitBuckets`. Replay with the same key AND matching `RequestHashSha256` MUST return the stored `ResponseSnapshotJson` and `StatusCode` verbatim. Reusing a key with a different `RequestHashSha256` MUST return `409 IdempotencyConflict` and MUST emit one `IdempotencyConflict` audit row (see `13-audit-logging.md`) and MUST count against the `actor:User:{UserId}` `IdempotencyConflict` bucket (`60:20`, see `14-rate-limiting.md`).

## Acceptance

- AC-API-LIC-001: Reseller reads and writes are restricted to the authenticated reseller scope.
- AC-API-LIC-002: License creation and serial creation commit their audit row in the same transaction.
- AC-API-LIC-003: Deleted licenses are excluded from normal reads and cannot issue serials.
- AC-API-LIC-004: Every row above declares one `PermissionKey` from [`../40-permissions.md`](../40-permissions.md) §2, and a caller passing the role gate but missing that key receives `403 AuthzPermissionDenied` with the missing key named in `Attributes.Error.Details.Value`.
- AC-API-LIC-005: A reseller-issued `POST /Licenses` that succeeds writes exactly one `Licenses` row, one `ResellerQuotaLedger` row with `Delta = -1`, and one `AuditLogs` row in the SAME database transaction; a rollback of any of them rolls back all of them.
- AC-API-LIC-006: A reseller-issued `POST /Licenses` returns `403 QuotaCategoryUnauthorized` when no matching `(ResellerId, LicenseCategoryId, LicenseTierId)` row is chargeable, and `409 QuotaExhausted` when the row exists with `LicensesRemaining <= 0`; the response never leaks `LicensesGranted` or `LicensesConsumed`.
- AC-API-LIC-007: An `Idempotency-Key` replay of `POST /Licenses` returns the stored snapshot verbatim and produces zero additional `ResellerQuotaLedger` rows and zero change to `LicensesConsumed`.
- AC-API-LIC-008: An `Idempotency-Key` conflict on `POST /Licenses` (same key, different `RequestHashSha256`, including a differing `LicenseTierId` or `EnvironmentId`) returns `409 IdempotencyConflict` without writing any `ResellerQuotaLedger` row and without changing `LicensesConsumed`.
- AC-API-LIC-009: A `POST /Licenses` request that omits `LicenseTierId` OR `EnvironmentId`, or supplies a value outside the closed sets in [`../43-license-tiers.md`](../43-license-tiers.md) §2 / [`../44-environments.md`](../44-environments.md) §2, returns `400 ValidationFailed` BEFORE any transaction opens; both fields are named in `Details[]` when both fail. Verified in CI by four table-driven cases (missing tier, invalid tier, missing environment, invalid environment) plus a combined case that expects two entries in `Details[]`.
- AC-API-LIC-010: A `PATCH /Licenses/{LicenseId}` request that includes `EnvironmentId` in the body returns `400 ValidationFailed` with `Details = [{ "Field": "EnvironmentId", "Rule": "Immutable" }]` and MUST NOT open a write transaction; the immutability trigger `TrgLicensesEnvironmentImmutable` (AC-ADB-015) is the last-line defense if application code drifts.
- AC-API-LIC-011: A reseller-issued `POST /Licenses` writes `ResellerQuotaLedger.LicenseTierId` equal to `Licenses.LicenseTierId` equal to the request `LicenseTierId`; verified by a CI test that inspects both rows after a successful issue and asserts triple equality per row.

## Feature admin endpoints (v1.4.0)

Feature registry, precedence, and value-type contract are owned verbatim by [`../45-license-features.md`](../45-license-features.md); this section defines only the wire endpoints that persist rows to `Features`, `TierFeatures`, and `LicenseFeatures`. Every mutating row below is in scope for `Idempotency-Key` per [`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md) and MUST emit one `FeatureAssigned` or `FeatureRevoked` audit row in the SAME transaction as the write (AC-FEAT-006).

| Endpoint | PermissionKey | Request | Result | Responses |
|----------|---------------|---------|--------|-----------|
| `GET /Features` | `Licenses.Read` | pagination params | `FeatureCatalogResource[]` (`FeatureKey`, `ValueType`) | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied` |
| `GET /Tiers/{LicenseTierId}/Features` | `Licenses.Read` | positive integer path id from [`../43-license-tiers.md`](../43-license-tiers.md) §2 closed set | `TierFeatureResource[]` (`FeatureKey`, `Value`) | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied` |
| `PUT /Tiers/{LicenseTierId}/Features/{FeatureKey}` | `Roles.Assign` | `Value` typed per [`../45-license-features.md`](../45-license-features.md) §3 | `TierFeatureResource` | `200`, `400 ValidationFailed`, `400 FeatureUnknown`, `400 FeatureValueInvalid`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied` |
| `DELETE /Tiers/{LicenseTierId}/Features/{FeatureKey}` | `Roles.Assign` | envelope only | envelope only | `200`, `400 FeatureUnknown`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 FeatureAssignmentNotFound` |
| `GET /Licenses/{LicenseId}/Features` | `Licenses.Read` | positive integer path id | `LicenseFeatureResource[]` (`FeatureKey`, `Value`) | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 LicenseNotFound` |
| `PUT /Licenses/{LicenseId}/Features/{FeatureKey}` | `Licenses.Update` | `Value` typed per [`../45-license-features.md`](../45-license-features.md) §3 | `LicenseFeatureResource` | `200`, `400 ValidationFailed`, `400 FeatureUnknown`, `400 FeatureValueInvalid`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 LicenseNotFound` |
| `DELETE /Licenses/{LicenseId}/Features/{FeatureKey}` | `Licenses.Update` | envelope only | envelope only | `200`, `400 FeatureUnknown`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 FeatureAssignmentNotFound`, `404 LicenseNotFound` |

Validation obligations (run BEFORE any transaction opens):

1. `FeatureKey` MUST match a row in the `Features` catalog seeded from [`../45-license-features.md`](../45-license-features.md) §2. Otherwise the endpoint returns `400 FeatureUnknown` with `Attributes.Error.Details = [{ "Field": "FeatureKey", "Value": <raw> }]`. This applies to both `PUT` and `DELETE` so the client cannot silently target a synonym.
2. `Value` on `PUT` MUST match the declared `ValueType` per [`../45-license-features.md`](../45-license-features.md) §3. Failures return `400 FeatureValueInvalid` naming both `FeatureKey` and the observed JSON type. Coercions (`"true"`, `1`, `"1"`) MUST NOT be applied.
3. `PUT /Licenses/{LicenseId}/Features/{FeatureKey}` under a `Reseller` role gate MUST additionally enforce row-scope: the target license row's `ResellerId` MUST equal `auth.reseller_id()`. Otherwise `403 AuthzPermissionDenied` per [`../40-permissions.md`](../40-permissions.md) §Row-scope; the response MUST NOT leak whether the license exists.

Idempotency semantics (per [`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md)):

- `PUT` and `DELETE` above are idempotent by verb, but MUST still honor `Idempotency-Key` when supplied so callers can distinguish "replay of a prior write" from "fresh write with same key and body". The stored `ResponseSnapshotJson` MUST include `FeatureKey`, resolved `Value`, and the audit row id; a replay MUST NOT emit a second audit row (AC-FEAT-006 combined with AC-IDL-006).
- A `PUT` replay whose `RequestHashSha256` differs in `Value` (same key, same URL, different body) returns `409 IdempotencyConflict` BEFORE the audit row is written.

Acceptance (append to the existing AC-API-LIC series):

- AC-API-LIC-012: Every feature admin row above declares one `PermissionKey` from [`../40-permissions.md`](../40-permissions.md) §2, and a caller passing the role gate but missing that key receives `403 AuthzPermissionDenied` with the missing key named in `Attributes.Error.Details.Value`.
- AC-API-LIC-013: A `PUT /Tiers/{LicenseTierId}/Features/{FeatureKey}` or `PUT /Licenses/{LicenseId}/Features/{FeatureKey}` request whose `FeatureKey` is outside the [`../45-license-features.md`](../45-license-features.md) §2 closed set returns `400 FeatureUnknown` before any transaction opens; verified by a table-driven CI case using every forbidden synonym listed in that file.
- AC-API-LIC-014: A `PUT` request whose `Value` fails the [`../45-license-features.md`](../45-license-features.md) §3 `ValueType` contract returns `400 FeatureValueInvalid` before any transaction opens; verified by six cases (`Boolean` receives `"true"`, `1`, `0`; `Number` receives `"5"`, `NaN`; `String` for `Support.Tier` receives `Premium`).
- AC-API-LIC-015: Every successful `PUT` and `DELETE` above writes exactly one `AuditLogs` row (`FeatureAssigned` for `PUT`, `FeatureRevoked` for `DELETE`) in the SAME transaction as the `TierFeatures` or `LicenseFeatures` write; a replay via `Idempotency-Key` MUST NOT produce a second audit row.
- AC-API-LIC-016: The next successful `POST /Verify/Final` after a `DELETE /Licenses/{LicenseId}/Features/{FeatureKey}` MUST resolve the key from `TierFeatures` if present, or omit it entirely otherwise; asserted by an integration test seeded from [`../45-license-features.md`](../45-license-features.md) §4 precedence (matches AC-FEAT-005).

