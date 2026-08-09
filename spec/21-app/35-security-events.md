# Security Events (Separated Ledger)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1.
**Related:** [`13-audit-logging.md`](./13-audit-logging.md), [`14-rate-limiting.md`](./14-rate-limiting.md), [`31-auth-session-family.md`](./31-auth-session-family.md), [`32-auth-session-retention.md`](./32-auth-session-retention.md), [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) (`SecurityEvents`).

`AuditLogs` retains business mutations for 24 months hot / 60 months cold (see `13-audit-logging.md` §Retention). Security-relevant signals need a different lifecycle: longer hot retention, tighter read scope, and a stable schema for downstream SIEM ingest. Interleaving both in one table forces a compromise on both. This file separates the security ledger into `SecurityEvents`.

---

## 1. Scope: what belongs in `SecurityEvents`

Closed set for V1. A row lands here IN ADDITION to (not instead of) any `AuditLogs` row emitted for the same request. Dual-write is transactional; a security-relevant action that produces an `AuditLogs` row without a matching `SecurityEvents` row is a contract bug.

| `EventType` | Trigger | Source spec |
|-------------|---------|-------------|
| `AuthLoginFailed` | `POST /Auth/Token` credential rejection. | `13-audit-logging.md`, `12-error-taxonomy.md` `AuthInvalidCredentials`. |
| `AuthLoginSucceeded` | `POST /Auth/Token` accepted. | `13-audit-logging.md`. |
| `AuthLogout` | Explicit `POST /Auth/Revoke` `Scope=Session`. | `31-auth-session-family.md` §Revoke. |
| `AuthRefreshReused` | Refresh reuse detected, family cascade fired. | `31-auth-session-family.md` §Reuse. |
| `AuthRefreshRaceLost` | Concurrent rotation lock loser. | `12-error-taxonomy.md`. |
| `AuthFamilyRevoked` | `POST /Auth/Revoke` `Scope=Family`. | `31-auth-session-family.md`. |
| `AuthFamilyEvictedByCap` | Per-user session cap eviction. | `31-auth-session-family.md`. |
| `AuthSaltRotationFailed` | Background salt rotation job failure. | `32-auth-session-retention.md`. |
| `AuthzRoleDenied` | `has_role` check rejected an authenticated caller. | `12-error-taxonomy.md`, `19-user-management.md`. |
| `AuthzRowScopeDenied` | Tenant-scope guard rejected an authenticated caller. | `12-error-taxonomy.md`. |
| `AuthzLastAdminProtected` | Last-`Admin` revoke refused (authz or DB trigger). | `19-user-management.md`. |
| `OAuthTokenRejected` | `POST /OAuth/Token` client credentials rejected. | `13-audit-logging.md`. |
| `HashKeyRejected` | `POST /Verify/Hash` mismatch. | `13-audit-logging.md`. |
| `RateLimited` | Any §2 bucket rejection in `14-rate-limiting.md`. | `14-rate-limiting.md` §9. |
| `AbuseBlocked` | §4.1 abuse rule fire in `14-rate-limiting.md`. | `14-rate-limiting.md` §4. |
| `AdminBreakGlassUsed` | `X-Break-Glass: true` accepted on an `Admin` route. | `14-rate-limiting.md` §5, `04-roles.md`. |
| `RoleGranted` | `POST /Admin/Users/{UserId}/Roles`. | `19-user-management.md`. |
| `RoleRevoked` | `DELETE /Admin/Users/{UserId}/Roles/{Role}`. | `19-user-management.md`. |
| `UpdatePublished` | `POST /Admin/AppUpdates` finalize. | `17-self-update-endpoint.md`. |
| `UpdateYanked` | `POST /Admin/AppUpdates/{Version}/Yank`. | `17-self-update-endpoint.md`. |
| `UpdateVerificationFailed` | Client SHA-256 mismatch on asset consumption. | `15-self-update.md`. |

Pure business mutations (`LicenseCreated`, `SerialGenerated`, `PrefixUpdated`, ...) DO NOT emit `SecurityEvents`. Pure validation failures (`ValidationFailed`, `InvalidJson`) DO NOT emit `SecurityEvents`. Both stay in `AuditLogs` or request logs per `13-audit-logging.md` §"Correlation with errors".

---

## 2. Row shape

Storage schema: [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) `SecurityEvents`. Wire shape (for SIEM export and admin read endpoints) is:

```json
{
  "SecurityEventId": 12345,
  "EventType": "AuthRefreshReused",
  "Severity": "High",
  "ActorType": "User",
  "ActorId": 42,
  "TargetType": "AuthSessions",
  "TargetId": 987,
  "RequestId": "01HXYZ...",
  "IpHash": "sha256:...",
  "UserAgentHash": "sha256:...",
  "SaltVersion": 3,
  "PayloadJson": { "FamilyId": 12, "InvalidatedCount": 4 },
  "CreatedAt": "2026-07-16T17:00:00Z"
}
```

Field rules:

- `Severity` is a closed set: `Info`, `Low`, `Medium`, `High`, `Critical`. Fixed per `EventType` in §3, never chosen by the caller.
- `IpHash` and `UserAgentHash` come from `PiiHashSalts` per `32-auth-session-retention.md`. `SaltVersion` is stored on the row so a rotated salt does not break correlation.
- `PayloadJson` follows the same allowlist as `AuditLogs.PayloadJson` (`13-audit-logging.md` §"`PayloadJson` rules"): PascalCase keys, no raw email/IP/UA, no fingerprints, no secrets.
- `RequestId` is the same ULID as the paired `AuditLogs` row and the HTTP response `RequestId`.

---

## 3. Severity mapping

Frozen for V1. Renames follow `13-audit-logging.md` §"Renaming closed-set values".

| `EventType` | `Severity` |
|-------------|-----------|
| `AuthLoginFailed` | `Low` |
| `AuthLoginSucceeded` | `Info` |
| `AuthLogout` | `Info` |
| `AuthRefreshReused` | `High` |
| `AuthRefreshRaceLost` | `Low` |
| `AuthFamilyRevoked` | `Medium` |
| `AuthFamilyEvictedByCap` | `Info` |
| `AuthSaltRotationFailed` | `Critical` |
| `AuthzRoleDenied` | `Medium` |
| `AuthzRowScopeDenied` | `Medium` |
| `AuthzLastAdminProtected` | `Medium` |
| `OAuthTokenRejected` | `Medium` |
| `HashKeyRejected` | `Medium` |
| `RateLimited` | `Low` |
| `AbuseBlocked` | `High` |
| `AdminBreakGlassUsed` | `High` |
| `RoleGranted` | `Medium` |
| `RoleRevoked` | `Medium` |
| `UpdatePublished` | `Medium` |
| `UpdateYanked` | `High` |
| `UpdateVerificationFailed` | `High` |

---

## 4. Retention and access

- Hot: 60 months (`AuditLogs` is 24). Cold archive after that; hard-delete after 120 months unless legal hold applies.
- Rows are append-only. No UPDATE, no DELETE outside the retention job.
- Read access requires role `Admin`. Self-read is disabled by default: an actor CANNOT list their own `SecurityEvents` rows without `Admin`, because the payload surfaces information a compromised account should not see (e.g. reuse cascades on their own family).
- Every read of `SecurityEvents` writes a request-log line at level `Info` with `AdminUserId`, `Filter`, `RowCount`, `RequestId`. No `SecurityEvents` row about the read (avoid recursion).
- Legal hold flag lives on the retention job configuration, not on individual rows in V1.

---

## 5. Dual-write and transactional guarantees

For every request that produces a security-relevant outcome per §1:

1. The domain mutation and both writes (`AuditLogs` INSERT and `SecurityEvents` INSERT) share ONE DB transaction.
2. Commit order is domain -> `AuditLogs` -> `SecurityEvents`, but all in the same transaction so they succeed or fail together.
3. If the transaction rolls back, no partial ledger row exists. Silent success on one and failure on the other is a spec violation.
4. Log line at `INFO` fires post-commit with `RequestId`, `EventType`, `Severity`, `ActorType`, `ActorId`. Missing log after a commit is a contract bug.

---

## 6. Observability

- Counter `laralicensing_security_events_total{EventType, Severity}`.
- Histogram `laralicensing_security_event_write_duration_ms`.
- Alert: any `EventType` with `Severity=Critical` fires a page immediately (`AuthSaltRotationFailed` is the only V1 member).
- Alert: `AuthRefreshReused` rate above 5 per minute for one `UserId` pages security on-call.

---

## 7. Acceptance

- AC-SEC-001: Every `EventType` in §1 has a paired `Action` in `13-audit-logging.md`'s catalog (or a defined reason for absence). Enforced by a spec sync test.
- AC-SEC-002: Every request that emits a `SecurityEvents` row also emits an `AuditLogs` row with the same `RequestId` inside the same transaction.
- AC-SEC-003: `PayloadJson` on `SecurityEvents` obeys the `AuditLogs` allowlist verbatim: no raw email, IP, UA, fingerprint, hash-key material, or secret.
- AC-SEC-004: Read access to `SecurityEvents` requires `has_role(auth.uid(), 'Admin')`; non-admin reads return `AuthzRoleDenied`.
- AC-SEC-005: `Severity` on any row matches §3 for its `EventType`; drift is rejected at write time by a CHECK / trigger.
- AC-SEC-006: `SaltVersion` on any row is the `PiiHashSalts.SaltVersion` active at `CreatedAt`; a NULL is a contract bug.
- AC-SEC-007: Retention job keeps `SecurityEvents` hot for 60 months and NEVER deletes rows under a legal-hold flag.
