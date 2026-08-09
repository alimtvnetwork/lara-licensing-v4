# Admin Impersonation

**Version:** 1.1.0
**Updated:** 2026-07-22
**Status:** Normative for LaraLicensingV1. Sole owner of the impersonation contract; other spec files MUST link here rather than restate rules.

---

## 1. Purpose

Allow an Admin operator to briefly assume the identity of a non-Admin user for support and reproduction, without sharing credentials, without weakening RLS row-scope for the impersonated user, and with a complete audit trail that always names the real operator.

## 2. Normative sources

- [`04-roles.md`](./04-roles.md): closed role set and Admin short-circuit.
- [`40-permissions.md`](./40-permissions.md) §2: `Users.Impersonate` PermissionKey and Admin-only default.
- [`13-audit-logging.md`](./13-audit-logging.md), [`28-audit-action-enum.md`](./28-audit-action-enum.md) §Enum: ids 55 (`ImpersonationStarted`) and 56 (`ImpersonationEnded`).
- [`12-error-taxonomy.md`](./12-error-taxonomy.md): reused error codes; no new codes introduced by this document.
- [`31-auth-session-family.md`](./31-auth-session-family.md), [`32-auth-session-retention.md`](./32-auth-session-retention.md): parent session lineage.
- [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md): `AuthSessions.ImpersonatorUserId` column and `Kind` value.

## 3. Model

An impersonation session is a distinct `AuthSessions` row with:

- `UserId` set to the target (impersonated) user.
- `ImpersonatorUserId` set to the real Admin operator. NOT NULL for `Kind = Impersonation`; NULL for all other kinds.
- `Kind = Impersonation` (closed enum value, additive to the existing `Kind` set).
- `ParentSessionId` set to the Admin's active `Normal` session id. NOT NULL.
- `ExpiresAt` set to `NOW() + 30 minutes` at creation and never extended.

Impersonation sessions MUST NOT be issued to targets whose effective role is `Admin`. A `403 Forbidden` with `ErrorCode = PermissionDenied` is returned; no partial state is created.

Only one active impersonation session per operator may exist. A `POST /Users/{UserId}/Impersonate` while an operator already holds an active impersonation session MUST return `409 Conflict` with `ErrorCode = ImpersonationAlreadyActive`. The operator MUST call `POST /Impersonation/End` first.

## 4. Endpoints

### 4.1 `POST /Users/{UserId}/Impersonate`

- **Auth**: Bearer access token from the operator's Normal session. `PermissionKey = Users.Impersonate`.
- **Headers**: `Idempotency-Key` required per [`29-idempotency-lifecycle.md`](./29-idempotency-lifecycle.md).
- **Request body**: `{ "Reason": string(min 8, max 500) }`. Reason is stored on the audit row and is REQUIRED. Empty or whitespace-only rejects with `ValidationFailed`.
- **Response 201**: session envelope per [`11-api-contracts/01-auth-contracts.md`](./11-api-contracts/01-auth-contracts.md) §Token, with additional fields `ImpersonatorUserId`, `TargetUserId`, and `Kind = "Impersonation"`. Access token TTL is capped at 30 minutes regardless of the tenant's normal TTL.
- **Errors**: `403 PermissionDenied` (caller not Admin, or target is Admin), `404 NotFound` (target user missing or deactivated), `409 ImpersonationAlreadyActive`, `422 ValidationFailed` (reason missing / too short).

### 4.2 `POST /Impersonation/End`

- **Auth**: Bearer access token from either the impersonation session OR the parent Normal session (an operator MUST be able to force-end from their own session if the impersonation token is lost).
- **Headers**: `Idempotency-Key` required.
- **Request body**: `{ "EndReason": "OperatorEnded" | "Timeout" | "AdminForced" }`. `Timeout` is server-emitted only; client callers MUST send `OperatorEnded` or `AdminForced`.
- **Response 200**: `{ "SessionId": Guid, "EndedAt": Iso8601Utc, "EndReason": string }`.
- **Errors**: `404 NotFound` (no active impersonation for caller), `403 PermissionDenied` (caller is neither the impersonator nor the impersonation session itself).

### 4.3 Reseller shard scope (normative)

Per [`../23-app-db/10-reseller-shard-split-db.md`](../23-app-db/10-reseller-shard-split-db.md), every non-Admin user lives inside exactly one reseller shard DB. Impersonation MUST respect that boundary:

1. **Caller role gate.** Only `Admin` may invoke `POST /Users/{UserId}/Impersonate`. `Reseller`, `AppBuilder`, and `EndUser` receive `403 PermissionDenied` regardless of any `UserPermissions` grant of `Users.Impersonate`; the permission check short-circuits on role for this endpoint. Rationale: `Users.Impersonate` is an Admin-only capability in v1 (spec 40 §2); a positive `UserPermissions` override does NOT elevate a non-Admin to impersonate.
2. **Target shard resolution.** The handler resolves the target's `ResellerId` from the Root DB (`Users.ResellerId` or `NULL` for Root-scope users). The impersonation `AuthSessions` row MUST be written in the SAME shard DB that owns the target user. Writing it in the Root DB when the target is shard-scoped is a hard invariant violation (audit trail lands where the mutating writes will happen).
3. **Parent session lineage across shards.** `ParentSessionId` MAY reference an `AuthSessions.SessionId` in a different DB (Root Admin session referencing a shard impersonation row). The FK constraint is enforced logically, not physically: the shard `AuthSessions` table declares `ParentSessionId UUID NOT NULL` with no cross-DB FK, and the audit-enrichment helper (spec 47 §6) MUST verify the parent exists in Root before insert. Violation surfaces as `ImpersonationParentSessionInvalid` (409).
4. **Cross-reseller impersonation is forbidden.** Even for Admin callers, an active impersonation targeting reseller `A` MUST end (via `POST /Impersonation/End`) before starting a new one against reseller `B`. This is a stricter statement of AC-IMP-004: the single-active-session rule holds globally across shards, not per-shard.
5. **Root DB record.** For observability, the Root DB writes a lightweight `ImpersonationIndex` row `{ SessionId, ImpersonatorUserId, TargetUserId, TargetResellerId, StartedAt, EndedAt }` inside the same transaction that inserts the shard `AuthSessions` row; a two-phase commit failure aborts the whole start. Column definitions land in [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) when the shard-provisioning migration ships.

## 5. Audit

Every start writes `AuditLogs` row with `Action = 55 (ImpersonationStarted)` and `PayloadJson` containing `TargetUserId`, `SessionId`, `Reason`, `TriggeredByUserId` (the operator, NOT the target). Every end writes `Action = 56 (ImpersonationEnded)` with `TargetUserId`, `SessionId`, `EndReason`, `TriggeredByUserId`. `EndReason` is a closed enum: `OperatorEnded`, `Timeout`, `AdminForced`.

Any write performed under an impersonation session MUST additionally record `ImpersonatorUserId` in the row's `PayloadJson` alongside the normal `TriggeredByUserId` (which reflects the target's `UserId`, because that is the authenticated identity for RLS). This is a hard invariant: an audit query filtered by `PayloadJson.ImpersonatorUserId` MUST return every action taken during that operator's impersonation windows.

## 6. UI

- Admin Console displays a persistent banner (`role="status"`, non-dismissible) whenever the active session is `Kind = Impersonation`. Banner text names the target and the remaining TTL. Banner tokens live in [`../24-app-ui-design-system/02-tokens.md`](../24-app-ui-design-system/02-tokens.md) §Alert.
- No route MAY hide the banner. The banner MUST render before any protected route content.
- The "End impersonation" action lives in the banner and calls `POST /Impersonation/End` with `EndReason = OperatorEnded`.

## 7. Acceptance criteria

- **AC-IMP-001**: A non-Admin caller invoking `POST /Users/{UserId}/Impersonate` receives `403 PermissionDenied` and no `AuditLogs` row is written.
- **AC-IMP-002**: An Admin caller invoking against an Admin target receives `403 PermissionDenied`; no session row is inserted.
- **AC-IMP-003**: Success path inserts exactly one `AuthSessions` row with `Kind = Impersonation`, `ImpersonatorUserId = caller.UserId`, `ParentSessionId = caller.session.SessionId`, `ExpiresAt = NOW() + 30 minutes`.
- **AC-IMP-004**: A second `POST /Users/{UserId}/Impersonate` from the same operator while an active impersonation exists returns `409 ImpersonationAlreadyActive`; no new row.
- **AC-IMP-005**: `POST /Impersonation/End` is idempotent per key: repeating the same `Idempotency-Key` returns the original 200 response and does NOT write a duplicate `ImpersonationEnded` row.
- **AC-IMP-006**: Server-side expiration writes exactly one `ImpersonationEnded` row with `EndReason = Timeout` at or before `ExpiresAt + 60s`.
- **AC-IMP-007**: A mutating call under an impersonation token writes an `AuditLogs` row whose `PayloadJson.ImpersonatorUserId` equals the operator's `UserId`.
- **AC-IMP-008**: The Admin Console banner is rendered on every `_authenticated` route while the session is `Kind = Impersonation`. Failing to render is a UI regression and MUST fail the visual test suite.
- **AC-IMP-009**: A `Reseller`, `AppBuilder`, or `EndUser` caller holding an explicit `UserPermissions` grant of `Users.Impersonate` still receives `403 PermissionDenied`; role gate wins over permission override for this endpoint.
- **AC-IMP-010**: A start against a shard-scoped target inserts the `AuthSessions` row inside the target's reseller shard DB and one `ImpersonationIndex` row inside Root, both in the same two-phase transaction; a Root-only or shard-only write is a violation.
- **AC-IMP-011**: An Admin holding an active impersonation for reseller `A` invoking `POST /Users/{UserId}/Impersonate` with a target in reseller `B` receives `409 ImpersonationAlreadyActive`; no new session or index row is written.

## 8. Out of scope for v1

- Reseller role acting as impersonator (Reseller-initiated impersonation). Admin impersonating a user inside a reseller shard IS in scope and is governed by §4.3.
- Chained impersonation (an impersonation session starting another impersonation).
- Read-only impersonation modes; v1 grants the target's full effective permissions for the 30-minute window.
