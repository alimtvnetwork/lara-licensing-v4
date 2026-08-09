# User Management

**Version:** 1.1.0
**Updated:** 2026-07-17
**Status:** Current. Consumers: `10-endpoints.md` v0.2.0 (`/Admin/Users/{UserId}/Roles`, `/Me/Roles`), `12-error-taxonomy.md` v1.6.0 (`AuthzRoleDenied`, `AuthzPermissionDenied`, `AuthzLastAdminProtected`, `ResourceRoleNotAssigned`, `ResourceRoleAlreadyAssigned`, `ValidationInvalidRole`), `13-audit-logging.md` v0.2.0 (`RoleGranted`, `RoleRevoked`, `RoleCheckDenied`), `14-rate-limiting.md` v0.20.0 (`actor:60:30`), `15-license-lifecycle.md` v0.21.0 (`has_role` gate), `40-permissions.md` v1.0.0 (`has_permission` contract, `PermissionKey` closed set).

Contract form of `spec/25-app-audit/15-user-management-consolidated.md`. Defines the role enum, storage, authorization primitive, endpoints, error codes, audit events, and UI surfaces for LaraLicensingV1 user management. Every JSON key PascalCase; every path segment PascalCase per `10-endpoints.md` (winner of `AF-CX-101`).

## Root cause fixed

Roles were described in prose across `spec/21-app/` but never declared as an enum, a table, or a server-side check, so every "admin only" gate was unverifiable. This file makes roles first-class.

## Role enum

```
app_role = { Admin, Reseller, AppBuilder, EndUser }
```

- **Admin.** Global control-plane. Grants/revokes roles. Publishes updates.
- **Reseller.** Sells licenses. Row-scoped to `ResellerId = auth()->resellerId()`.
- **AppBuilder.** Consumes verification endpoints and reads/publishes their own update channel.
- **EndUser.** Binds serials and reads own profile.

`app_role` is the sole source of truth. No other file redefines the enum; every citation points here.

## Storage

`User` table drops any scalar `Role` column.

```
UserRole
  Id            uuid primary key
  UserId        uuid not null → User(Id) on delete cascade
  Role          app_role not null
  GrantedAt     timestamptz not null default now()
  GrantedBy     uuid not null → User(Id)
  RevokedAt     timestamptz null
  RevokedBy     uuid null → User(Id)
  unique (UserId, Role) where RevokedAt is null
```

Singular PascalCase table name per `spec/23-app-db/` singularization rule (routed to plan step 32). Reseller row scope is a separate column on `User` (`ResellerId nullable`); it is a data attribute, not a role.

## Authorization primitive

```
has_role(UserId uuid, Role app_role) -> boolean
```

- Server-only. Executed under Laravel's authorization layer as a `SECURITY DEFINER`-equivalent function that ignores the caller's own row policies while reading `UserRole`.
- Every endpoint in `10-endpoints.md` and every contract in `11-api-contracts/` MUST cite the exact role check or row-scope predicate. Prose-only role statements are a spec defect.
- Row-scoped variant: reseller endpoints add `AND ResellerId = auth()->resellerId()`. Denial returns `AUTHZ_ROW_SCOPE_DENIED`.

### `has_permission(UserId, PermissionKey)`

The fine-grained authorization primitive introduced by [`40-permissions.md`](./40-permissions.md). Called AFTER `has_role()` succeeds; the two are not interchangeable and never collapse into a single check (see [`40-permissions.md`](./40-permissions.md) §4 for the fixed authorization order).

```
has_permission(UserId uuid, Permission text) -> boolean
```

Normative contract:

1. **Signature.** `Permission` is a `PermissionKey` value from [`40-permissions.md`](./40-permissions.md) §2 verbatim (PascalCase, dot-separated). Passing any other string is a caller bug and MUST raise `ValidationFailed` at the transport layer before this function is reached; the function itself returns `false` for unknown keys and MUST NOT throw so that callers can log the denial with a stable `AuthzPermissionDenied` code.
2. **Security definer.** Runs `SECURITY DEFINER` with `search_path = public`, mirroring `has_role()`. Bypasses RLS on `UserRole`, `RolePermissions`, and `UserPermissions` so the check is decidable regardless of the caller's row-scope policy. The function reads its own tables only; it MUST NOT read domain tables (`License`, `Serial`, `AuditLog`) because row visibility of the target is a separate step in the authorization order.
3. **Admin short-circuit.** If `has_role(UserId, 'Admin')` is true, `has_permission` returns `true` for every `PermissionKey` regardless of `RolePermissions` and `UserPermissions` state. This mirrors [`04-roles.md`](./04-roles.md) §Admin and [`40-permissions.md`](./40-permissions.md) §1, and MUST be encoded as an explicit early-return so that a corrupt or empty `RolePermissions` seed cannot silently downgrade an Admin.
4. **Effective set.** For non-Admin callers the function computes `Effective(U)` per the formula in [`40-permissions.md`](./40-permissions.md) §1: union of `RolePermissions` across the caller's active `UserRole` rows, plus any `UserPermissions` row with `Grant = true`, minus any `UserPermissions` row with `Grant = false`. Revoked (`RevokedAt IS NOT NULL`) `UserRole` rows MUST NOT contribute. The negative override wins over positive override for the same `(UserId, PermissionKey)`.
5. **Return value.** Pure boolean. No side effects. No log lines emitted from inside the function; the caller is responsible for the `AuthzPermissionDenied` log line per [`22-log-line-contract.md`](./22-log-line-contract.md).
6. **Determinism.** Two calls with the same arguments within a single request MUST return the same value. Implementations MAY memoize `Effective(U)` in a request-scoped cache (see [`40-permissions.md`](./40-permissions.md) §5); cross-request caches MUST key on `(UserId, RolesFingerprint, OverridesFingerprint)` where fingerprints derive from row versions in `UserRole` and `UserPermissions`, never wall-clock time. A permission revoke MUST be observable within one request.
7. **Failure mode.** If the underlying query fails (DB down, timeout), the function MUST propagate the error to the caller. Silent `return false` on infrastructure failure is forbidden: it would look like an authorization denial and hide the outage.
8. **Callers.** Every mutating or reading endpoint declared in [`10-endpoints.md`](./10-endpoints.md) MUST cite exactly one `PermissionKey` and call `has_permission()` after the role gate. A prose-only permission statement is a spec defect equivalent to a prose-only role statement (parity linter Step 07 of Plan 05).

### Authorization order (summary)

The full four-step ladder from [`40-permissions.md`](./40-permissions.md) §4 is repeated here for enforcement clarity, because this file is the primitive owner:

1. Authentication -> `AuthUnauthorized` on failure.
2. Role gate: endpoint's allowed role subset contains caller's role -> `AuthzRoleDenied` on failure.
3. Permission gate: `has_permission(caller, DeclaredPermissionKey)` returns true -> `AuthzPermissionDenied` on failure.
4. Row scope (RLS): target row visible under caller's row-scope policy -> `NotFound` (never `403`, to prevent existence leak).

Short-circuit on the first failure. Emitting `AuthzRoleDenied` when the real failure is permission (or vice versa) is a contract bug.
## Endpoints

Registered in `10-endpoints.md` (plan step 28).


### `POST /Admin/Users/{UserId}/Roles`

Grants a role. Auth: `has_role(caller, Admin)`.

Request:

```json
{ "Role": "Reseller" }
```

Response 201:

```json
{
  "Status": { "IsSuccess": true, "Code": 201, "Message": "Created" },
  "Attributes": { "RequestId": "..." },
  "Results": [{ "UserId": "...", "Role": "Reseller", "GrantedAt": "2026-07-16T..." }]
}
```

Errors: `AUTHZ_ROLE_DENIED` (403), `VALIDATION_INVALID_ROLE` (400), `RESOURCE_USER_NOT_FOUND` (404), `RESOURCE_ROLE_ALREADY_ASSIGNED` (409).

### `DELETE /Admin/Users/{UserId}/Roles/{Role}`

Revokes a role (sets `RevokedAt`). Auth: `has_role(caller, Admin)` AND `caller.UserId != UserId` when `Role = Admin` (prevents last-admin lockout at authz layer; DB constraint enforces global "at least one active Admin exists").

Response 204.

Errors: `AUTHZ_ROLE_DENIED`, `RESOURCE_ROLE_NOT_ASSIGNED`, `AUTHZ_LAST_ADMIN_PROTECTED` (409).

### `GET /Me/Roles`

Any authenticated caller.

Response 200:

```json
{
  "Status": { "IsSuccess": true, "Code": 200, "Message": "OK" },
  "Attributes": { "RequestId": "..." },
  "Results": [{ "Roles": ["Reseller"] }]
}
```

### `GET /Admin/Users/{UserId}/Roles`

Auth: `has_role(caller, Admin)`.

Response 200 shape identical to `/Me/Roles`.

## Capability matrix

| Capability | Admin | Reseller | AppBuilder | EndUser |
|------------|:-----:|:--------:|:----------:|:-------:|
| Read all Resellers | Y | own only | N | N |
| Create Reseller | Y | N | N | N |
| Issue License | Y | Y (own) | N | N |
| Revoke License | Y | Y (own) | N | N |
| Issue Serial | Y | Y (own) | N | N |
| Bind Serial | Y | Y (own) | Y (own key) | Y (self) |
| Read Audit log | Y | own only | N | N |
| Publish Update (Stable channel) | Y | N | N | N |
| Publish Update (Beta channel) | Y | N | Y | N |
| Read Update manifest (Stable) | Y | Y | Y | Y |
| Read Update manifest (Beta) | Y | N | Y | N |
| Grant/Revoke Role | Y | N | N | N |
| Read own profile | Y | Y | Y | Y |

Every row backs into one endpoint in `10-endpoints.md`. Any capability without a matching endpoint is a plan-step-28 finding.

## Error codes (routed to `12-error-taxonomy.md`, plan step 29)

| Code | HTTP | Meaning |
|------|:----:|---------|
| `AUTHZ_ROLE_DENIED` | 403 | Caller lacks the required role. |
| `AUTHZ_ROW_SCOPE_DENIED` | 403 | Role present but row-scope predicate excludes target. |
| `AUTHZ_LAST_ADMIN_PROTECTED` | 409 | Refuse to revoke the last active Admin. |
| `RESOURCE_ROLE_NOT_ASSIGNED` | 404 | Revocation target does not hold the role. |
| `RESOURCE_ROLE_ALREADY_ASSIGNED` | 409 | Grant target already holds the role. |
| `VALIDATION_INVALID_ROLE` | 400 | Request body role not in `app_role`. |
| `REQUEST_ID_MISSING` | 400 | `X-Request-Id` header absent when required by observability policy. |

## Audit events (routed to `13-audit-logging.md`, plan step 30)

- `RoleGranted { RequestId, ActorUserId, SubjectUserId, Role, GrantedAt }`
- `RoleRevoked { RequestId, ActorUserId, SubjectUserId, Role, RevokedAt, Reason }`
- `RoleCheckDenied { RequestId, ActorUserId, Role, EndpointPath, Reason }`

Every role check MUST additionally emit a structured log `{RequestId, UserId, Role, Decision}` regardless of audit destination; `X-Request-Id` correlation is mandatory per `spec/25-app-audit/16-coding-guideline-alignment.md` AF-CG-013.

## UI surfaces (routed to plan step 33; `16-ui-surfaces.md` update)

- `/admin/users` list: table of users with active role chips.
- `/admin/users/{userId}` detail: role picker calling `POST/DELETE /Admin/Users/{UserId}/Roles[/{Role}]`; confirmation modal when revoking `Admin` from another user; disabled+tooltip when target is the last Admin.
- `/me` reads `GET /Me/Roles` and hides admin nav items when `Admin` absent.

Client wiring lives in `src/lib/lara-user-role.ts` (typed helpers) and `src/routes/_authenticated/admin.users*.tsx` (plan step 33).

## Migration path (routed to plan step 32; `spec/23-app-db/` update)

1. Create `UserRole` table with the schema above.
2. Backfill: for each row in current `User`, insert one `UserRole` row keyed off the legacy scalar `Role` column.
3. Drop the `User.Role` column in a follow-up migration once every endpoint reads from `has_role`.
4. Add global constraint: `count(*) FROM UserRole WHERE Role = 'Admin' AND RevokedAt IS NULL >= 1` enforced by a trigger.

## Acceptance

Passes `AC-AUD-004`, `AC-AUD-018`, `AC-AUD-019`, `AC-AUD-020`; contributes to `AC-AUD-024` (structured logs) and `AC-AUD-025` (X-Request-Id correlation).

## Cross-references

- Envelope: `spec/21-app/11-api-contracts/00-overview.md`.
- Error taxonomy: `spec/21-app/12-error-taxonomy.md`.
- Audit log: `spec/21-app/13-audit-logging.md`.
- Bridge decisions: `spec/25-app-audit/15-user-management-consolidated.md`.
- DB schema: `spec/23-app-db/01-schema.md`.
