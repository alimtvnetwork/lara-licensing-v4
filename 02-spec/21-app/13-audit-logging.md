# Audit Logging Contract

**Version:** 1.1.0
**Updated:** 2026-07-16
**AI Confidence:** High
**Ambiguity:** Low

Every state-changing action in LaraLicensingV1 writes exactly one row to `AuditLogs`. This file fixes the record shape, the closed set of `Action` values, the redaction rules, and the retention policy. Request-level access logs (every HTTP call) are separate and live in the request-log stream, not `AuditLogs`.

## Scope

- Audit rows are the durable, queryable history of *what changed* and *who did it*.
- Request logs are the transient stream of *every HTTP call* including reads. They stay in the log aggregator, not the DB.
- The two streams share `RequestId`, so any audit row can be joined to the originating request log.

## Record shape

The `AuditLogs` table in [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) is the source of truth. Every field is required unless marked NULL.

| Field | Type | Rule |
|-------|------|------|
| `AuditLogId` | BIGINT | Server-assigned. |
| `ActorType` | enum | `User`, `AppBuilder`, `System`. `System` only for scheduled jobs and internal maintenance. |
| `ActorId` | BIGINT NULL | NULL only when `ActorType = System` or the action is a pre-auth failure (e.g. `AuthLoginFailed`). |
| `Action` | enum | Closed set below. PascalCase, past tense. |
| `TargetType` | string | Table name of the primary target (`Licenses`, `Serials`, `Users`, `Prefixes`, `Resellers`, `UserRole`, `MachineBindings`, `OAuthClients`, `AuthSessions`, `AppUpdates`, `IdempotencyRecords`). |
| `TargetId` | BIGINT NULL | NULL only when the action creates the target and the id is emitted in `PayloadJson.CreatedId`. |
| `RequestId` | CHAR(26) | ULID matching `Attributes.RequestId` and the request-log correlation id. |
| `PayloadJson` | JSON NULL | Structured, field-safe context. Rules below. |
| `CreatedAt` | DATETIME | Server clock, UTC. |

Every audit write is transactional with the domain change: they commit or roll back together. A domain mutation that succeeds without a matching audit row is a contract bug.

## `Action` catalog

Closed set. Adding a value requires a minor version bump of this file. Every listed action maps to at least one endpoint from [`10-endpoints.md`](./10-endpoints.md).

| Action | Trigger | Target | Notes |
|--------|---------|--------|-------|
| `AuthLoginSucceeded` | `POST /Auth/Token` success | `AuthSessions` | Actor is the authenticated user. |
| `AuthLoginFailed` | `POST /Auth/Token` failure | `Users` NULL | ActorId NULL, `PayloadJson.EmailHash` only, never raw email. |
| `AuthLogout` | `POST /Auth/Revoke` | `AuthSessions` | |
| `AuthRefreshRotated` | `POST /Auth/Refresh` success | `AuthSessions` | |
| `AuthRefreshReused` | refresh reuse detected | `AuthSessions` | Family invalidated; `PayloadJson.InvalidatedCount`. |
| `OAuthTokenIssued` | `POST /OAuth/Token` success | `OAuthClients` | Actor is the client. |
| `OAuthTokenRejected` | `POST /OAuth/Token` failure | `OAuthClients` | `PayloadJson.ErrorCode` from taxonomy. |
| `UserCreated` / `UserUpdated` / `UserDeactivated` | admin user routes | `Users` | |
| `RoleGranted` / `RoleRevoked` | `POST/DELETE /Admin/Users/{UserId}/Roles` | `UserRole` | `PayloadJson.Role` from `app_role`. Revoking the last active `Admin` grant is refused with `AuthzLastAdminProtected`, no audit row emitted (validation failure). |
| `RoleCheckDenied` | `has_role` refusal on any role-gated endpoint | `Users` (target = caller) | `PayloadJson.Role`, `PayloadJson.Endpoint`, `PayloadJson.ErrorCode = "AuthzRoleDenied"`. Security-relevant per §Correlation with errors. |
| `ResellerCreated` / `ResellerUpdated` / `ResellerDeleted` | reseller routes | `Resellers` | |
| `PrefixCreated` / `PrefixUpdated` / `PrefixDeleted` | prefix routes | `Prefixes` | |
| `LicenseCategoryCreated` / `LicenseCategoryUpdated` / `LicenseCategoryArchived` | category routes | `LicenseCategories` | |
| `LicenseCreated` / `LicenseUpdated` / `LicenseRevoked` / `LicenseExpired` | license routes and expiry job | `Licenses` | `LicenseExpired` is `ActorType = System`. |
| `SerialGenerated` / `SerialRevoked` | serial routes | `Serials` | |
| `HashKeyIssued` / `HashKeyVerified` / `HashKeyRejected` | `POST /Verify/Hash` | `Serials` | Rejected records `PayloadJson.ErrorCode`. |
| `VerifyKeyIssued` / `VerifyKeyConsumed` / `VerifyKeyExpired` | `POST /Verify/Final` | `Serials` | |
| `MachineBound` / `MachineUnbound` | verify routes | `MachineBindings` | `PayloadJson.FingerprintHash` only. |
| `AbuseBlocked` | abuse rule fired | `OAuthClients` or `Users` | `PayloadJson.Rule` and `PayloadJson.WindowSeconds`. |
| `RateLimited` | rate limiter rejected a mutation | actor row | Read-only rate-limit events stay in request logs, not `AuditLogs`. |
| `UpdatePublished` | `POST /Admin/AppUpdates` success | `AppUpdates` | `PayloadJson.Product`, `Version`, `Channel`, `Platforms[]`. Actor is `Admin`. |
| `UpdateDownloaded` | `GET /App/UpdateAsset/*` 200 | `AppUpdates` | `PayloadJson.Product`, `Version`, `Platform`, `Channel`. Actor is `AppBuilder` or `System` for public `Stable`. |
| `UpdateVerified` | `HEAD /App/UpdateAsset/*` client-verified checksum match reported via `POST /App/UpdateVerified` (spec 17) | `AppUpdates` | `PayloadJson.Version`, `Platform`, `Sha256Match=true`. |
| `UpdateVerificationFailed` | checksum mismatch reported by client or detected at asset upload verify (`UpdateChecksumMismatch`) | `AppUpdates` | `PayloadJson.Version`, `Platform`, `ErrorCode="UpdateChecksumMismatch"`. Security-relevant. |
| `IdempotencyConflict` | replay of `Idempotency-Key` with mutated body on any idempotent mutation | `IdempotencyRecords` | `PayloadJson.Key`, `Endpoint`, `ExpectedRequestHashSha256`, `ReceivedRequestHashSha256`. Actor is the caller. Counted against `IdempotencyConflict` rate bucket per `14-rate-limiting.md`. |

Any endpoint from [`10-endpoints.md`](./10-endpoints.md) that mutates state MUST emit one of the actions above. Read endpoints do not write audit rows.

## `PayloadJson` rules

- PascalCase keys, JSON scalars or shallow objects, max 4 KB serialized.
- Include the minimum context needed to reconstruct intent: prior value, new value, and any identifiers not covered by `TargetId`.
- Field-safe only. The following are forbidden in `PayloadJson`:
  - Raw passwords, password hashes, or password history.
  - Access tokens, refresh tokens, OAuth authorization codes, client secrets.
  - Raw `HashKey`, `VerifyKey`, PKCE verifiers, or session cookies.
  - Raw machine fingerprints, MAC addresses, motherboard serials. Store the SHA-256 hex as `*Hash` fields.
  - Raw email addresses on pre-auth failure paths. Store `EmailHash` (SHA-256 of lowercased email) instead.
- Diffs use `{ "Before": ..., "After": ... }`. Omit unchanged fields.
- Never store user-agent strings verbatim; store parsed `{ "Browser": ..., "Os": ... }`.

## Retention and access

- Rows are append-only. No UPDATE, no DELETE outside the retention job.
- Retention: 24 months, then archive to cold storage, then hard-delete at 60 months.
- Read access requires role `Admin`. No `Auditor` role exists in v1 (see [`04-roles.md`](./04-roles.md) AC-ROLE-004). Actors can read their own rows (`ActorType`, `ActorId` match) through a scoped endpoint, not general list access.
- Any read of `AuditLogs` writes a request log entry (not a new audit row) so audit-of-audit does not recurse.

## Correlation with errors

- On failure, `PayloadJson.ErrorCode` is the code from [`12-error-taxonomy.md`](./12-error-taxonomy.md).
- The HTTP response's `RequestId`, the request-log `RequestId`, and the audit row's `RequestId` are the same ULID.
- Failed mutations still emit audit rows when the failure carries security signal (`AuthLoginFailed`, `AuthRefreshReused`, `HashKeyRejected`, `OAuthTokenRejected`, `AbuseBlocked`, `RateLimited`, `RoleCheckDenied`, `UpdateVerificationFailed`). Pure validation failures (`ValidationFailed`, `InvalidJson`, `ValidationInvalidRole`) do not, they stay in request logs.

## Observability

- Every audit write emits one log line at level `INFO` with fields `RequestId`, `Action`, `ActorType`, `ActorId`, `TargetType`, `TargetId`, `OutcomeCode` (`Success` or an `ErrorCode`), and `DurationMs`.
- The line MUST fire after the transaction commits. A commit without the log line is a contract bug.
- Metrics: counter `audit_actions_total{Action, Outcome}` and histogram `audit_write_duration_ms`.

## Renaming closed-set values (MUST)

Every value in the `TargetType` closed set at line 26 and every value in the `Action` catalog is persisted verbatim into historical `AuditLogs` rows. A rename is a breaking change to persisted data and MUST follow this protocol; "just rename the enum" is a spec violation because it silently orphans historical rows from queries and reports.

1. Land the new value in the closed set alongside the old value (both accepted for read).
2. Write a data migration that rewrites every historical row from the old value to the new value in a single transaction, or create a compatibility alias table `AuditTargetTypeAliases { OldValue, NewValue }` that read paths JOIN through.
3. Only after the migration commits (or the alias row exists), remove the old value from the closed set in this file and bump the contract minor version.
4. The migration itself emits one `SchemaMigrationApplied` audit row with `PayloadJson.OldValue`, `NewValue`, `RowsRewritten`, `Strategy ∈ {"rewrite","alias"}`.

This protocol also applies to renaming any `Action` catalog entry. It does NOT apply to adding new values (additive change, no migration required).

## Acceptance

- AC-AUD-001: Every mutating endpoint in [`10-endpoints.md`](./10-endpoints.md) maps to at least one `Action` in the catalog above.
- AC-AUD-002: Every `Action` value emitted at runtime appears in the catalog.
- AC-AUD-003: `PayloadJson` for any action never contains a forbidden field. Enforced by a serialization allowlist.
- AC-AUD-004: Domain mutation and audit write share a single DB transaction.
- AC-AUD-005: `RequestId` on the audit row equals `Attributes.RequestId` on the response and the correlation id on the request log line.
- AC-AUD-006: Failed security-relevant actions listed above write an audit row with `PayloadJson.ErrorCode` set from [`12-error-taxonomy.md`](./12-error-taxonomy.md).
- AC-AUD-007: Any rename of a `TargetType` or `Action` value ships with the migration/alias defined in §"Renaming closed-set values"; the closed sets never lose a value without a data-migration commit or alias row landing first.
- AC-AUD-008: The stable numeric id and required `PayloadJson` fields for every `Action` catalog entry are frozen in [`28-audit-action-enum.md`](./28-audit-action-enum.md); adding or removing an `Action` here MUST update that file in the same commit.
- AC-AUD-009: Every endpoint reference in the `Action` catalog above uses the canonical PascalCase path from [`10-endpoints.md`](./10-endpoints.md), matching the vocabulary rule in [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md).

## References

- [`10-endpoints.md`](./10-endpoints.md)
- [`11-api-contracts/00-overview.md`](./11-api-contracts/00-overview.md)
- [`12-error-taxonomy.md`](./12-error-taxonomy.md)
- [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) (`AuditLogs`)
