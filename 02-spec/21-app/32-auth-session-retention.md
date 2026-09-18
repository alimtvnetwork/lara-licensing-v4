# Auth Session Retention and PII-Hash Salt Rotation

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

[`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §"Auth Sessions" defines `IpHash` and `UserAgentHash` as SHA-256 of `value + daily salt` but leaves three questions unanswered: where the salt lives, when it rotates, and how retention interacts with the 90-day reuse-detection guarantee from [`31-auth-session-family.md`](./31-auth-session-family.md) §Retention. Without a normative answer, hashes from different days are non-comparable and audit evidence is deleted before the reuse window closes.

## Salt Storage

- Salt table: `PiiHashSalts` (see [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md), added in the same migration as `AuthSessions`).
- Row shape: `(SaltId BIGINT UNSIGNED PK, SaltPurpose TINYINT UNSIGNED NOT NULL, SaltBytes VARBINARY(32) NOT NULL, ActiveFrom DATETIME NOT NULL, ActiveUntil DATETIME NULL, CreatedAt DATETIME)`.
- `SaltPurpose` enum: `1 = AuthSessionIp`, `2 = AuthSessionUserAgent`. One purpose can have many rows over time; exactly one row per purpose has `ActiveUntil IS NULL` at any moment.
- Salts are 32 random bytes from a CSPRNG. They MUST NOT appear in logs, audit rows, or API responses.

## Rotation Schedule

- Cadence: every 24 hours at 00:05 UTC, a scheduled job inserts a new row per purpose with `ActiveFrom = NOW()` and closes the previous row by setting its `ActiveUntil = NOW()`.
- Missed run recovery: if the job did not run for N days, insert one catch-up row per purpose (not N) and continue. Historical rows are never back-filled.
- Manual rotation: on suspected salt compromise, an Admin MAY force rotation by inserting a new row; the previous row is closed by trigger.

## Hash Semantics

For a session row created at `T`, the hash is computed with the salt whose window contains `T`:

```
IpHash        = SHA256_hex_lower( IpString || SaltBytes(purpose=1, T) )
UserAgentHash = SHA256_hex_lower( UserAgentString || SaltBytes(purpose=2, T) )
```

Comparability rule: two `AuthSessions` rows are comparable on `IpHash` only if they were written under the same salt row. Cross-salt comparison is not supported and MUST NOT be attempted by reuse-detection code. Reuse detection in [`31-auth-session-family.md`](./31-auth-session-family.md) §Family Lifecycle relies on `RefreshTokenHash` (unsalted, per [`31-auth-session-family.md`](./31-auth-session-family.md) §Family Lifecycle step 2.1), not `IpHash`.

## Retention

| Data | Live retention | Deletion trigger |
|------|----------------|------------------|
| `AuthSessions` row | 90 days from family root `CreatedAt`, extended to `MAX(90d, RevokedAt + 30d)` when the row is revoked with `RevokeReason IN (3, 5)` (`ReuseDetected`, `EvictedByCap`) | scheduled retention job |
| `PiiHashSalts` row | `ActiveUntil + 90 days` (kept while any live `AuthSessions` row could reference it) | retention job cascade guard |
| `AuditLogs` rows for actions 5, 45, 46 | per [`13-audit-logging.md`](./13-audit-logging.md) audit retention, MUST outlive the referenced `AuthSessions` row | audit retention job |

Guard: the retention job MUST NOT delete a `PiiHashSalts` row while any `AuthSessions` row exists whose `CreatedAt` falls within that salt's `[ActiveFrom, ActiveUntil]` window. Implementation: JOIN check inside the delete transaction; abort delete if the join returns rows.

## Error Bindings

| Case | HTTP | ErrorCode | Log level |
|------|------|-----------|-----------|
| Rotation job fails to insert a new salt row | n/a (background) | `AuthSaltRotationFailed` (new, see [`12-error-taxonomy.md`](./12-error-taxonomy.md) reservation) | `Error` |
| Hash computation attempted with no active salt for purpose | 500 | `ServerConfigurationError` | `Fatal` |
| Retention delete blocked by guard | n/a | log-only, `Info` | `Info` |

The `AuthSaltRotationFailed` code is reserved here for [`12-error-taxonomy.md`](./12-error-taxonomy.md) addition in a later step (see plan 02 step 43+).

## Acceptance

- AC-ASR-001: For each `SaltPurpose`, exactly one `PiiHashSalts` row has `ActiveUntil IS NULL` at any instant.
- AC-ASR-002: An `AuthSessions` row created at time `T` has `IpHash` and `UserAgentHash` computed with the salts active at `T`; changing `NOW()` after write MUST NOT recompute the hash.
- AC-ASR-003: The retention job never deletes a salt row that is still referenced by an `AuthSessions` row within its active window.
- AC-ASR-004: `AuthSessions` rows with `RevokeReason = 3` (`ReuseDetected`) are retained at least 30 days after `RevokedAt`, even if that exceeds the 90-day absolute cap.
- AC-ASR-005: Salt bytes never appear in `AuditLogs.PayloadJson`, request logs, error responses, or metrics labels.

## Normative Cross-References

- [`31-auth-session-family.md`](./31-auth-session-family.md): family lifecycle and reuse detection.
- [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §"Auth Sessions" and §"PII Hash Salts".
- [`22-log-line-contract.md`](./22-log-line-contract.md): field redaction.
- [`13-audit-logging.md`](./13-audit-logging.md): audit retention floors.
