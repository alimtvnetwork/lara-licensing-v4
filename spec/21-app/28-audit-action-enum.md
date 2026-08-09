# Audit Action Enum Freeze

**Version:** 1.4.0
**Updated:** 2026-07-22

---

## Purpose

Freeze the closed `Action` set from [`13-audit-logging.md`](./13-audit-logging.md) as a numeric enum for the `AuditLogs.Action` column, per the SA-031 rule ("Enum-backed Type/Status/Kind/Category columns") and [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md). Without a stable numeric id, historical rows silently break when a value is added and re-ordered.

## Normative sources

- [`13-audit-logging.md`](./13-audit-logging.md): `Action` catalog and rename protocol.
- [`10-endpoints.md`](./10-endpoints.md): canonical route paths.
- [`12-error-taxonomy.md`](./12-error-taxonomy.md): `ErrorCode` values referenced in `PayloadJson`.
- `.lovable/strictly-avoid/`: SA-031 (Enum-backed columns).

## Rules

1. Ids are assigned once. Never reused. A removed action reserves its id forever (marked `RESERVED` in the table).
2. Additions append with the next free id. No re-sorting.
3. The DB column is `SMALLINT UNSIGNED NOT NULL` with a CHECK constraint listing the accepted ids.
4. Application code MUST use the id, never the string, on the write path. The string surface is derived on read for observability.

## Enum

| Id | Action | Endpoint (canonical) | Required `PayloadJson` keys | Security-relevant? |
|---:|--------|----------------------|------------------------------|:------------------:|
| 1 | `AuthLoginSucceeded` | `POST /Auth/Token` | `SessionId` | no |
| 2 | `AuthLoginFailed` | `POST /Auth/Token` | `EmailHash`, `ErrorCode` | yes |
| 3 | `AuthLogout` | `POST /Auth/Revoke` | `SessionId` | no |
| 4 | `AuthRefreshRotated` | `POST /Auth/Refresh` | `SessionId`, `RotationCount` | no |
| 5 | `AuthRefreshReused` | detector | `SessionId`, `InvalidatedCount`, `ErrorCode` | yes |
| 6 | `OAuthTokenIssued` | `POST /OAuth/Token` | `ClientId`, `Scope[]` | no |
| 7 | `OAuthTokenRejected` | `POST /OAuth/Token` | `ClientId`, `ErrorCode` | yes |
| 8 | `UserCreated` | `POST /Users` | `CreatedId`, `EmailHash` | no |
| 9 | `UserUpdated` | `PATCH /Users/{UserId}` | `Before`, `After` | no |
| 10 | `UserDeactivated` | `DELETE /Users/{UserId}` | `Reason` | no |
| 11 | `RoleGranted` | `POST /Users/{UserId}/Roles` | `Role` | yes |
| 12 | `RoleRevoked` | `DELETE /Users/{UserId}/Roles` | `Role` | yes |
| 13 | `RoleCheckDenied` | any role-gated route | `Role`, `Endpoint`, `ErrorCode` | yes |
| 14 | `ResellerCreated` | `POST /Resellers` | `CreatedId`, `Name` | no |
| 15 | `ResellerUpdated` | `PATCH /Resellers/{ResellerId}` | `Before`, `After` | no |
| 16 | `ResellerDeleted` | `DELETE /Resellers/{ResellerId}` | `Reason` | no |
| 17 | `PrefixCreated` | `POST /Resellers/{ResellerId}/Prefixes` | `CreatedId`, `Value` | no |
| 18 | `PrefixUpdated` | `PATCH /Prefixes/{PrefixId}` | `Before`, `After` | no |
| 19 | `PrefixDeleted` | `DELETE /Prefixes/{PrefixId}` | `Reason` | no |
| 20 | `LicenseCategoryCreated` | admin category route | `CreatedId`, `Name` | no |
| 21 | `LicenseCategoryUpdated` | admin category route | `Before`, `After` | no |
| 22 | `LicenseCategoryArchived` | admin category route | `Reason` | no |
| 23 | `LicenseCreated` | `POST /Licenses` | `CreatedId`, `CategoryId`, `VariationId` | no |
| 24 | `LicenseUpdated` | `PATCH /Licenses/{LicenseId}` | `Before`, `After` | no |
| 25 | `LicenseRevoked` | `DELETE /Licenses/{LicenseId}` | `Reason` | no |
| 26 | `LicenseExpired` | expiry job | `PreviousExpiresAt` | no |
| 27 | `SerialGenerated` | `POST /Licenses/{LicenseId}/Serials` | `CreatedId`, `IdempotencyKey` | no |
| 28 | `SerialRevoked` | admin serial route | `Reason` | no |
| 29 | `HashKeyIssued` | `POST /Verify/Hash` | `SerialId`, `KeyHash` | no |
| 30 | `HashKeyVerified` | `POST /Verify/Hash` | `SerialId` | no |
| 31 | `HashKeyRejected` | `POST /Verify/Hash` | `SerialId`, `ErrorCode` | yes |
| 32 | `VerifyKeyIssued` | `POST /Verify/Final` | `SerialId` | no |
| 33 | `VerifyKeyConsumed` | `POST /Verify/Final` | `SerialId` | no |
| 34 | `VerifyKeyExpired` | expiry job | `SerialId` | no |
| 35 | `MachineBound` | verify flow | `SerialId`, `FingerprintHash` | no |
| 36 | `MachineUnbound` | verify flow | `SerialId`, `FingerprintHash` | no |
| 37 | `AbuseBlocked` | rate limiter | `Rule`, `WindowSeconds`, `ErrorCode` | yes |
| 38 | `RateLimited` | rate limiter (mutations only) | `Bucket`, `WindowSeconds` | yes |
| 39 | `UpdatePublished` | `POST /Admin/AppUpdates` | `Product`, `Version`, `Channel`, `Platforms` | no |
| 40 | `UpdateDownloaded` | `GET /App/UpdateAsset/{Version}/{Platform}` | `Product`, `Version`, `Platform`, `Channel` | no |
| 41 | `UpdateVerified` | `POST /App/UpdateVerified` | `Version`, `Platform`, `Sha256Match` | no |
| 42 | `UpdateVerificationFailed` | client report or upload verify | `Version`, `Platform`, `ErrorCode` | yes |
| 43 | `IdempotencyConflict` | idempotent-mutation replay with mismatched hash | `Key`, `Endpoint`, `ExpectedRequestHashSha256`, `ReceivedRequestHashSha256` | yes |
| 44 | `SchemaMigrationApplied` | rename migration per `13-audit-logging.md` §Renaming | `OldValue`, `NewValue`, `RowsRewritten`, `Strategy` | no |
| 45 | `AuthFamilyRevoked` | Admin "sign out everywhere" per [`31-auth-session-family.md`](./31-auth-session-family.md) §Family Lifecycle | `FamilyId`, `UserId`, `RevokedCount`, `TriggeredByUserId` | yes |
| 46 | `AuthFamilyEvictedByCap` | login above per-user family cap per [`31-auth-session-family.md`](./31-auth-session-family.md) §Session Cap | `FamilyId`, `UserId`, `EvictedRootCreatedAt` | yes |
| 47 | `QuotaConsumed` | `POST /Licenses` (issuance) per [`41-reseller-quotas.md`](./41-reseller-quotas.md) §Decrement | `ResellerId`, `CategoryId`, `Delta`, `RemainingAfter`, `LicenseId`, `IdempotencyKey` | yes |
| 48 | `QuotaRestored` | `DELETE /Licenses/{LicenseId}` (compensating restore) per [`41-reseller-quotas.md`](./41-reseller-quotas.md) §Restore | `ResellerId`, `CategoryId`, `Delta`, `RemainingAfter`, `LicenseId`, `Reason` | yes |
| 49 | `QuotaAdjusted` | admin quota adjustment route per [`42-quota-requests.md`](./42-quota-requests.md) §Adjustment | `ResellerId`, `CategoryId`, `Before`, `After`, `Reason`, `TriggeredByUserId` | yes |
| 50 | `QuotaRequestSubmitted` | `POST /QuotaRequests` | `RequestId`, `CategoryId`, `RequestedDelta`, `Justification` | no |
| 51 | `QuotaRequestApproved` | `POST /QuotaRequests/{Id}/Approve` | `RequestId`, `ApprovedDelta`, `TriggeredByUserId` | yes |
| 52 | `QuotaRequestDenied` | `POST /QuotaRequests/{Id}/Deny` | `RequestId`, `Reason`, `TriggeredByUserId` | yes |

| 53 | `FeatureAssigned` | admin feature write route per [`45-license-features.md`](./45-license-features.md) §5 | `Scope`, `TargetId`, `FeatureKey`, `ValueType`, `ValueBefore`, `ValueAfter`, `TriggeredByUserId` | yes |
| 54 | `FeatureRevoked` | admin feature write route per [`45-license-features.md`](./45-license-features.md) §5 | `Scope`, `TargetId`, `FeatureKey`, `ValueBefore`, `TriggeredByUserId` | yes |
| 55 | `ImpersonationStarted` | `POST /Users/{UserId}/Impersonate` per [`46-impersonation.md`](./46-impersonation.md) | `TargetUserId`, `SessionId`, `Reason`, `TriggeredByUserId` | yes |
| 56 | `ImpersonationEnded` | `POST /Impersonation/End` per [`46-impersonation.md`](./46-impersonation.md) | `TargetUserId`, `SessionId`, `EndReason`, `TriggeredByUserId` | yes |

Ids 57+ reserved for future additions. `Scope` in rows 53/54 is one of the closed pair `Tier` (write to `TierFeatures`) or `License` (write to `LicenseFeatures`); `TargetId` is the corresponding `LicenseTierId` or `LicenseId`. Rows 55/56 `EndReason` is a closed enum defined in [`46-impersonation.md`](./46-impersonation.md) §5.

## Actions that do NOT write audit rows

Codes that stay in request logs only (per [`13-audit-logging.md`](./13-audit-logging.md) §Correlation with errors): `ValidationFailed`, `InvalidJson`, `ValidationInvalidRole`, `RequestIdMissing`, `IdempotencyKeyRequired` (pre-hash failure), `NotFound` on reads.

## Acceptance

- AC-AAE-001: Row count in the Enum table equals the count of `Action` catalog rows in [`13-audit-logging.md`](./13-audit-logging.md) expanded per slash (`X / Y / Z` counts as 3).
- AC-AAE-002: No id is reused, ever. A removed action's row remains as `RESERVED` with the old id.
- AC-AAE-003: Every row lists at least one required `PayloadJson` key OR explicitly declares `none` in the column.
- AC-AAE-004: The `security-relevant` column matches the security-relevant list in [`13-audit-logging.md`](./13-audit-logging.md) §Correlation with errors verbatim.
- AC-AAE-005: The DB migration for `AuditLogs.Action` uses the ids in this table and a CHECK constraint enumerates them.
- AC-AAE-006: Every `Action` name referenced by an `AC-FEAT-*` row in [`45-license-features.md`](./45-license-features.md) §6 appears in the enum table above with the required `PayloadJson` keys `Scope`, `TargetId`, `FeatureKey`.
