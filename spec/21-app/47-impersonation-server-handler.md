# Impersonation: Server Handler Contract

**Version:** 1.0.0
**Updated:** 2026-07-25
**Status:** Normative. Sole owner of the server-side implementation contract for the wire endpoints defined in [`46-impersonation.md`](./46-impersonation.md). File 46 owns the wire contract and acceptance criteria; this file owns transaction ordering, idempotency replay, timeout sweep, and audit enrichment.

---

## 1. Scope

Backend implementers MUST follow this document to satisfy AC-IMP-003 through AC-IMP-007. UI, wire schemas, and error codes remain in [`46-impersonation.md`](./46-impersonation.md). This file does not introduce new error codes; every reject path reuses [`12-error-taxonomy.md`](./12-error-taxonomy.md).

## 2. Transactional order for `POST /Users/{UserId}/Impersonate`

The handler runs inside a single database transaction. Order is fixed and MUST NOT be reordered.

1. Idempotency lookup per [`29-idempotency-lifecycle.md`](./29-idempotency-lifecycle.md). On replay hit, return the stored envelope verbatim and skip the rest of this section.
2. Authorize the caller via `Users.Impersonate` permission per [`40-permissions.md`](./40-permissions.md). Reject with `403 PermissionDenied`.
3. Load the target user with `FOR UPDATE`. Reject `404 NotFound` when missing or deactivated. Reject `403 PermissionDenied` when the target's effective role is `Admin`.
4. `SELECT ... FOR UPDATE` the caller's parent Normal session. Reject with `401 SessionMissing` when absent or expired.
5. Assert no active impersonation exists for the caller. The uniqueness predicate is: `AuthSessions` row where `ImpersonatorUserId = caller.UserId AND Kind = Impersonation AND EndedAt IS NULL AND ExpiresAt > NOW()`. Reject with `409 ImpersonationAlreadyActive`.
6. Insert the impersonation `AuthSessions` row with the fields listed in [`46-impersonation.md`](./46-impersonation.md) §3. `ExpiresAt = NOW() + INTERVAL '30 minutes'`. `Kind = Impersonation`. `ParentSessionId` is the row locked in step 4.
7. Insert one `AuditLogs` row with `Action = 55 (ImpersonationStarted)`, `TriggeredByUserId = caller.UserId`, `PayloadJson = { TargetUserId, SessionId, Reason }`.
8. Persist the idempotency record with the response envelope.
9. Commit. Only after commit MAY the handler mint and return the access token.

Any error before commit MUST roll back the entire transaction: no half-created session, no dangling audit row.

## 3. Transactional order for `POST /Impersonation/End`

1. Idempotency lookup. On replay hit, return the stored envelope verbatim.
2. Resolve the caller's session. Accept either the impersonation session token OR the parent Normal session token (spec 46 §4.2). Reject with `403 PermissionDenied` otherwise.
3. `SELECT ... FOR UPDATE` the target impersonation row. Reject with `404 NotFound` when no active row exists for the caller pairing.
4. Validate `EndReason`. Client callers MUST send `OperatorEnded` or `AdminForced`. Any other value rejects with `422 ValidationFailed`. `Timeout` is server-emitted only (see §4).
5. Update the row: `EndedAt = NOW()`, `RevokeReason = <EndReason mapped enum>` per [`23-app-db/01-schema.md`](../23-app-db/01-schema.md) `RevokeReason`.
6. Insert `AuditLogs` row with `Action = 56 (ImpersonationEnded)`, `TriggeredByUserId = caller.UserId` (the real operator, resolved via `ImpersonatorUserId` when the impersonation token is used), `PayloadJson = { TargetUserId, SessionId, EndReason }`.
7. Persist the idempotency record.
8. Commit. After commit, invalidate any in-memory token cache tied to the impersonation `SessionId`.

## 4. Timeout sweep job (satisfies AC-IMP-006)

A background job named `ImpersonationTimeoutSweep` runs on a fixed cadence of 15 seconds. For each row where `Kind = Impersonation AND EndedAt IS NULL AND ExpiresAt <= NOW()`:

1. `SELECT ... FOR UPDATE SKIP LOCKED` the row.
2. Set `EndedAt = ExpiresAt` (NOT `NOW()`: audit rows must reflect the contractual end time, not scheduler jitter).
3. Insert `AuditLogs` row with `Action = 56`, `TriggeredByUserId = ImpersonatorUserId`, `PayloadJson.EndReason = "Timeout"`.
4. Commit each row in its own transaction; a failure on one row MUST NOT block the batch.

The sweep MUST write exactly one `ImpersonationEnded` row per expired session even if the batch runs late. AC-IMP-006 requires the audit row to appear no later than `ExpiresAt + 60s`; the 15s cadence and per-row commit isolate slow rows from starving the deadline.

## 5. Audit enrichment invariant (satisfies AC-IMP-007)

Every mutating server handler MUST call a single enrichment helper before writing its `AuditLogs` row. The helper reads the current request's session context; when `session.Kind = Impersonation`, it MUST:

- Set `TriggeredByUserId` to `session.UserId` (the target's identity, which is the authenticated identity for RLS).
- Merge `{ "ImpersonatorUserId": session.ImpersonatorUserId }` into `PayloadJson` at the top level, overwriting any client-supplied key of that name.

This is a hard invariant: an audit query filtered by `PayloadJson->>'ImpersonatorUserId' = :operator` MUST return every mutation performed inside that operator's impersonation windows. Handlers that write audit rows without calling the enrichment helper are a spec violation and MUST fail the audit-completeness test in [`27-error-code-test-matrix.md`](./27-error-code-test-matrix.md).

The enrichment helper is the ONLY place `PayloadJson.ImpersonatorUserId` is set. Downstream code MUST NOT set it manually; duplicated set-sites drift and break AC-IMP-007 silently.

## 6. Idempotency replay contract

Both endpoints reuse [`29-idempotency-lifecycle.md`](./29-idempotency-lifecycle.md) with these clarifications:

- The idempotency key namespace is per-caller and per-endpoint. `POST /Users/{a}/Impersonate` and `POST /Users/{b}/Impersonate` are distinct namespaces; the same key across them MUST NOT collide.
- Replay MUST return the original 201 (start) or 200 (end) with the original body, even if the underlying session has since ended or expired. The endpoint reports the historical write, not the live state.
- Retention follows the standard 24-hour window from [`29-idempotency-lifecycle.md`](./29-idempotency-lifecycle.md).

## 7. Failure modes and observability

- Log every start/end at INFO with fields `{ CallerUserId, TargetUserId, SessionId, EndReason, IdempotencyKey, Replay }` per [`22-log-line-contract.md`](./22-log-line-contract.md).
- Log timeout sweep completions at INFO with `{ BatchSize, ExpiredSessions, Errors }`; log per-row failures at WARN with the offending `SessionId`.
- Never log `Reason` body text below WARN. Reason strings may contain PII and MUST be redacted from lower-severity log lines but preserved in the audit row.

## 8. Test matrix

Every AC in [`46-impersonation.md`](./46-impersonation.md) §7 maps to a specific server test. Tests MUST cover:

- Transaction rollback when step 7 (audit insert) fails: no session row survives.
- Idempotency replay after the impersonation has already ended: replay still returns the original 201.
- Timeout sweep under simulated 5-minute clock skew: `EndedAt` equals `ExpiresAt`, not the sweep run time.
- Audit enrichment: a `POST /Licenses` performed under an impersonation token writes an `AuditLogs` row where `PayloadJson.ImpersonatorUserId` equals the operator, and the same call from a Normal session does NOT include that key.
