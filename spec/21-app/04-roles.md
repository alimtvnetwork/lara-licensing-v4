# Roles

**Version:** 1.3.0
**Updated:** 2026-07-17

---

## Canonical set (single source of truth)

This file is the sole normative source for the `app_role` enum. Any other spec file (including [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md)) MUST point here rather than restate the members. Members are PascalCase and stable; renaming is a breaking change.

| Canonical | Description | Forbidden synonyms |
|-----------|-------------|--------------------|
| `Admin` | Platform owner, full control. | `admin`, `Administrator`, `Owner`, `SuperAdmin` |
| `Reseller` | Manages prefixes, generates licenses in quota. | `reseller`, `Partner`, `Tenant`, `Vendor` |
| `AppBuilder` | Integrates API; calls verify endpoints. | `app-builder`, `App Builder`, `Builder`, `Integrator`, `Developer` |
| `EndUser` | Not an API caller; identified indirectly via serial + machine data. | `end-user`, `End User`, `Customer`, `Consumer`, `Player` |

`Auditor` is NOT a member of `app_role` in v1. Audit read access in v1 is `Admin` only. Any legacy reference to `Auditor` is superseded by this file.

## Cross references

- Error taxonomy: `AuthzRoleDenied` in [`12-error-taxonomy.md`](./12-error-taxonomy.md) fires when a JWT actor's role is not in the endpoint's allowed subset.
- Endpoint map: [`10-endpoints.md`](./10-endpoints.md) §Verification restricts verify routes to `AppBuilder` OAuth clients.
- API contracts: [`11-api-contracts/04-admin-contracts.md`](./11-api-contracts/04-admin-contracts.md) `/Admin/Users/{UserId}/Role` uses these canonical role strings verbatim in the request and response bodies.

## Permission Matrix

The capability-per-role matrix that previously lived here has been superseded by the normative Role -> `PermissionKey` mapping in [`40-permissions.md`](./40-permissions.md) §3 (Default role -> permission mapping). This file MUST NOT restate that matrix: duplicating it invites drift between the two files, and the linter added in Plan 05 Step 07 (`linter-scripts/check-endpoint-permission-parity.py`) treats [`40-permissions.md`](./40-permissions.md) §2 as the sole source of truth for `PermissionKey` values.

Per-endpoint authorization is now declared in three places, in this order:

1. **Role gate** (which of `Admin`, `Reseller`, `AppBuilder`, `EndUser` may reach the route) is declared in [`10-endpoints.md`](./10-endpoints.md) and returns `403 AuthzRoleDenied` on failure.
2. **Permission gate** (`has_permission(UserId, PermissionKey)`) is declared in [`10-endpoints.md`](./10-endpoints.md) and each [`11-api-contracts/*.md`](./11-api-contracts/00-overview.md) row, using a `PermissionKey` defined in [`40-permissions.md`](./40-permissions.md) §2, and returns `403 AuthzPermissionDenied` on failure per [`12-error-taxonomy.md`](./12-error-taxonomy.md).
3. **Row scope** (RLS) is enforced in the database per [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) and returns `404 NotFound` on failure to prevent existence-leak.

Verification endpoints (`/Verify/*`) are gated on `AppBuilder` OAuth client scope and do NOT carry a `PermissionKey`; see [`10-endpoints.md`](./10-endpoints.md) §Verification. The `Admin` short-circuit rule (Admin holds every `PermissionKey`) is defined in [`40-permissions.md`](./40-permissions.md) §1 and enforced by `has_permission()` per [`19-user-management.md`](./19-user-management.md) §Authorization primitive.

## Enforcement

- API layer rejects unauthorized calls with the codes listed above; audit read access remains `Admin`-only in v1 per §Cross references.
- Every mutating call writes an `AuditLog` row (see `10-endpoints.md`).

## Acceptance

- AC-ROLE-001: A `Reseller` calling admin-only endpoints receives `403 AuthzRoleDenied`.
- AC-ROLE-002: Role assignments require `Admin` (via the `Roles.Assign` permission in [`40-permissions.md`](./40-permissions.md) §3) and are auditable.
- AC-ROLE-003: `Admin` or `Reseller` JWT sessions calling `/Verify/Serial`, `/Verify/Hash`, or `/Verify/Final` receive `403 AuthzRoleDenied`.
- AC-ROLE-004: `Auditor` is not a member of `app_role` in v1; audit reads require `Admin` and the `Audit.Read` permission.
- AC-ROLE-005: This file MUST NOT restate the Role -> Permission matrix from [`40-permissions.md`](./40-permissions.md) §3; a duplicate table anywhere under `spec/21-app/` outside `40-permissions.md` is a spec defect and MUST fail the permission-parity linter added in Plan 05 Step 07.
