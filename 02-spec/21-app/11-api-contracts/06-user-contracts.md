# User Contracts

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1.
**Owner:** This file is the sole normative source for the wire contract of the self-read identity endpoint `GET /Users/Me` and the `Users.ReadSelf` permission. Every other spec or client that reads the caller's identity MUST reference this file, not restate the schema.
**Related:** [`../04-roles.md`](../04-roles.md), [`../40-permissions.md`](../40-permissions.md), [`../12-error-taxonomy.md`](../12-error-taxonomy.md), [`../26-route-dto-index.md`](../26-route-dto-index.md), [`05-envelope-schema.md`](./05-envelope-schema.md).

---

## 1. Rationale

Reseller-facing routes (e.g. the quota-request portal) must not trust a `ResellerId` URL segment for authorization decisions. The client MUST obtain the caller's identity from an authenticated server read, then assert consistency against the route parameter and short-circuit locally on mismatch. Server-side row-scope per [`../40-permissions.md`](../40-permissions.md) §Row-scope remains the sole enforcement point; client assertions are UX guardrails, not authorization.

## 2. Endpoint

| Method | Path | PermissionKey | RoleGate | Idempotency | RetryPolicy |
|--------|------|---------------|----------|-------------|-------------|
| `GET` | `/Users/Me` | `Users.ReadSelf` | Any authenticated role: `Admin`, `SuperAdmin`, `Reseller`, `Support`, `Auditor` | n/a (safe read) | `RetryWithBackoff` on `503`, `NoRetry` on `4xx` |

`Users.ReadSelf` is a new closed-set entry added to [`../40-permissions.md`](../40-permissions.md) §2 with default grant `Yes` for every authenticated role. It has no row-scope: the endpoint's row scope is fixed to `auth.uid()`, so a caller cannot read any identity other than its own.

## 3. Response schema

Success envelope `Data` payload (single row, wrapped per [`05-envelope-schema.md`](./05-envelope-schema.md)):

| Field | Type | Nullable | Notes |
|-------|------|:--------:|-------|
| `UserId` | positive integer | No | Primary key from `Users`. |
| `Email` | RFC 5322 email | No | Case-preserved as stored. |
| `RoleName` | closed set | No | One of `SuperAdmin`, `Admin`, `Reseller`, `Support`, `Auditor` per [`../04-roles.md`](../04-roles.md) §2. |
| `ResellerId` | positive integer | Yes | Present iff the caller's row in `Users.ResellerId` is non-null. `Admin`, `SuperAdmin`, `Support`, `Auditor` MAY have null. `Reseller` MUST have non-null. |
| `DisplayName` | string(1..200) | Yes | Optional. Absent field and JSON `null` are equivalent. |

The response envelope MUST NOT carry any additional fields (no preferences, no last-login timestamps, no permission list). Preferences live on a separate surface reserved for a later revision.

## 4. Error responses

| Status | ErrorCode | Trigger |
|--------|-----------|---------|
| `401` | `AuthTokenInvalid` | Missing or malformed bearer per [`01-auth-contracts.md`](./01-auth-contracts.md). |
| `401` | `AuthTokenExpired` | Expired bearer; client MUST honor the refresh contract per [`01-auth-contracts.md`](./01-auth-contracts.md). |
| `403` | `AuthzPermissionDenied` | Caller lacks `Users.ReadSelf` (only possible if an operator explicitly revoked the default grant). |
| `500` | `UnknownServerError` | `Users` row missing for a valid session, or `RoleName` value outside the [`../04-roles.md`](../04-roles.md) §2 closed set. |

`404 NotFound` MUST NOT be returned: for a valid session, the row exists by construction; if it does not, that is a server invariant break and MUST surface as `500 UnknownServerError` per [`../12-error-taxonomy.md`](../12-error-taxonomy.md).

## 5. Client obligations

Client code binding to this endpoint MUST:

1. Cache the response under a stable query key (`["LaraApi", "Users", "Me"]`) with `staleTime` at least one minute so route mounts do not thrash the endpoint.
2. On `RoleName === "Reseller"`, treat a `null` `ResellerId` in the response as a server invariant break and surface `UnknownServerError` in the UI. This is not a `401`, and the client MUST NOT silently coerce.
3. When a route consumes a `ResellerId` URL segment, assert `MeResource.RoleName !== "Reseller" || MeResource.ResellerId === urlResellerId` before rendering row-scoped mutation forms. On mismatch, render a 403 gate: server-side row-scope will already reject the mutation, this is a UX short-circuit.

## 6. Acceptance

- AC-API-USR-001: `GET /Users/Me` returns exactly one row whose shape matches §3 verbatim; the field set MUST NOT be extended without bumping this file's Version.
- AC-API-USR-002: A caller whose `Users` row has `RoleName = "Reseller"` receives a non-null `ResellerId` or the server returns `500 UnknownServerError` per §4.
- AC-API-USR-003: `Users.ReadSelf` appears in the [`../40-permissions.md`](../40-permissions.md) §2 registry with default grant `Yes` for every authenticated role; a table-driven CI check MUST fail if any listed role has `No`.
- AC-API-USR-004: A `Reseller` client whose `MeResource.ResellerId` does not equal the `ResellerId` URL segment of a row-scoped route MUST render a 403 gate; mutation forms MUST NOT mount.
- AC-API-USR-005: The endpoint MUST NOT accept any query string, request body, or `Idempotency-Key`; extra parameters MUST return `400 ValidationFailed` with the offending field named in `Attributes.Error.Details`.
