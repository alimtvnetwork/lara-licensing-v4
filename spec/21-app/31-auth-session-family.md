# Auth Session Family Invariants

**Version:** 1.2.0
**Updated:** 2026-07-16

---

## Purpose

`spec/21-app/02-authentication-jwt.md` §"Errors" declares `AuthRefreshReused` "revokes entire chain" but does not define the chain, the persistence shape, the reuse detection window, or the revocation cascade. Two conforming implementations could therefore disagree on which sessions die when a replay is detected. This file locks the refresh family model so `AuthSessions` (see `spec/23-app-db/01-schema.md` §"Auth Sessions") is unambiguous.

## Terminology

- **Session**: one row in `AuthSessions`, representing one issued refresh token.
- **Family**: the tree of sessions produced by successive rotations from a single interactive login. All rows share `FamilyId`.
- **Ancestor chain**: for a session `S`, the set of rows reachable by walking `ParentSessionId` up to the family root.

## Family Lifecycle

1. Login (`POST /Auth/Token`) mints a new `FamilyId` (ULID) and inserts one `AuthSessions` row with `ParentSessionId = NULL`.
2. Refresh (`POST /Auth/Refresh`) MUST, in a single DB transaction:
   1. Locate the presented refresh row by opaque token hash (`RefreshTokenHash`, SHA-256 hex, lower case, no salt).
   2. If not found: return `AuthInvalidCredentials` (log level `Warning`).
   3. If `RevokedAt IS NOT NULL` and `ReplacedBySessionId IS NULL`: return `AuthTokenRevoked`.
   4. If `RevokedAt IS NOT NULL` and `ReplacedBySessionId IS NOT NULL`: this is reuse. Set `RevokedAt = NOW()` and `RevokeReason = 3` (`ReuseDetected`) on every row of the family where `RevokedAt IS NULL`, write one `AuditLogs` row (`Action = AuthRefreshReused`, id 5 per [`28-audit-action-enum.md`](./28-audit-action-enum.md)), and return `AuthRefreshReused`.
   5. If `ExpiresAt <= NOW()`: return `AuthTokenExpired`.
   6. Otherwise insert a new row with the same `FamilyId`, `ParentSessionId = <presented>.SessionId`, then set `<presented>.RevokedAt = NOW()`, `<presented>.RevokeReason = 1` (`Rotated`), and `<presented>.ReplacedBySessionId = <new>.SessionId`. Write `AuditLogs` `Action = AuthRefreshRotated` (id 4).
3. Revoke (`POST /Auth/Revoke`) sets `RevokedAt = NOW()` and `RevokeReason = 2` (`ExplicitRevoke`) on the target row only; it does NOT cascade. Writes `AuthLogout` (id 3).
4. Logout of the family (Admin-triggered "sign out everywhere") sets `RevokedAt = NOW()` and `RevokeReason = 4` (`FamilyRevoked`) on every live row of the family and writes `AuthFamilyRevoked` (id 45, per [`28-audit-action-enum.md`](./28-audit-action-enum.md)).

## Timers

| Timer | Value | Notes |
|-------|-------|-------|
| Access token TTL | 900 s (15 min) | Per `02-authentication-jwt.md`. |
| Refresh sliding TTL | 30 days | Reset on successful rotation. |
| Refresh absolute TTL | 90 days | Family root `CreatedAt + 90d`, hard cap; refresh after this returns `AuthTokenExpired`. |
| Reuse detection window | Life of family | A revoked-and-replaced row remains detectable for the family's absolute TTL. Rows older than absolute TTL MAY be pruned by the retention job. |

## Retention

The retention job (see `13-audit-logging.md`) MUST NOT delete an `AuthSessions` row until `NOW() >= FamilyRootCreatedAt + 90 days AND RevokedAt IS NOT NULL`. This preserves reuse detection for the full life of the family.

## Session Cap

Per user, at most 10 concurrent live families (rows where `ParentSessionId IS NULL AND RevokedAt IS NULL AND ExpiresAt > NOW()`). New login above the cap sets `RevokedAt = NOW()` and `RevokeReason = 5` (`EvictedByCap`) on the oldest live family and logs `AuthFamilyEvictedByCap` (id 46, per [`28-audit-action-enum.md`](./28-audit-action-enum.md)).

## Error Bindings

| Case | HTTP | ErrorCode | Retry class (per `25-retry-decision-matrix.md`) | Log level |
|------|------|-----------|------------------------------------------------|-----------|
| Reuse detected | 401 | `AuthRefreshReused` | `FatalClear` | `Warning` |
| Sliding or absolute TTL exceeded | 401 | `AuthTokenExpired` | `RefreshThenRetry` (from access-token callers), `FatalClear` (from refresh callers) | `Info` |
| Explicit revoke | 401 | `AuthTokenRevoked` | `FatalClear` | `Info` |
| Concurrent rotation lost race | 409 | `AuthRefreshRaceLost` | `RetryOnce` | `Debug` |

## Acceptance

- AC-ASF-001: Presenting a refresh token that has been rotated once revokes every live row of its family within the same DB transaction.
- AC-ASF-002: Absolute TTL is enforced from family root `CreatedAt`, not from the latest rotation.
- AC-ASF-003: An 11th concurrent login for the same user revokes the oldest live family and logs `AuthFamilyEvictedByCap`.
- AC-ASF-004: `AuthSessions.RefreshTokenHash` is unique across the table; two families never share a hash.
- AC-ASF-005: Retention job leaves at least one revoked row per family reachable until absolute TTL.

## Normative Cross-References

- `spec/21-app/02-authentication-jwt.md` §"Endpoints", §"Errors".
- `spec/21-app/25-retry-decision-matrix.md` for caller-side retry classes.
- `spec/21-app/28-audit-action-enum.md` IDs 3, 4, 5, 45, 46.
- `spec/21-app/11-api-contracts/01-auth-contracts.md` §`POST /Auth/Revoke` for the request/response DTO.
- `spec/23-app-db/01-schema.md` §"Auth Sessions".
