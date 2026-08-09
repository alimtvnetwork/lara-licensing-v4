# Administration API Contracts

**Version:** 1.3.0
**Updated:** 2026-07-17

## Vocabulary sources

`PermissionKey` column values cite [`../40-permissions.md`](../40-permissions.md) §2 verbatim. On permission failure endpoints return `403 AuthzPermissionDenied` per [`../12-error-taxonomy.md`](../12-error-taxonomy.md), naming the missing key in `Attributes.Error.Details.Value`.

## Resellers

| Endpoint | PermissionKey | Request | Result | Responses |
|----------|---------------|---------|--------|-----------|
| `POST /Resellers` | `Resellers.Manage` | `ResellerName`, `ContactEmail`, optional `IsActive` | `ResellerId`, echoed fields, timestamps | `201`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `409 ResellerConflict` |
| `GET /Resellers` | `Resellers.Manage` | Query: optional `Page`, `PageSize` 1 to 100, optional `IsActive`, optional `Search` (see [`07-admin-list-envelope-hardening.md`](./07-admin-list-envelope-hardening.md)) | reseller collection plus `Attributes.Pagination` | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied` |
| `GET /Resellers/{ResellerId}` | `Resellers.Manage` | Positive integer path id | one reseller | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 ResellerNotFound` |
| `PATCH /Resellers/{ResellerId}` | `Resellers.Manage` | At least one mutable reseller field | updated reseller | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 ResellerNotFound` |
| `DELETE /Resellers/{ResellerId}` | `Resellers.Manage` | Positive integer path id | `ResellerId`, `IsDeleted=true` | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 ResellerNotFound`, `409 ResellerInUse` |

## Prefixes

| Endpoint | PermissionKey | Request | Result | Responses |
|----------|---------------|---------|--------|-----------|
| `GET /Resellers/{ResellerId}/Prefixes` | `Prefixes.Manage` | optional `Page`, `PageSize` 1 to 100, optional `IsActive` (see [`07-admin-list-envelope-hardening.md`](./07-admin-list-envelope-hardening.md)) | prefix collection plus `Attributes.Pagination` | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 ResellerNotFound` |
| `POST /Resellers/{ResellerId}/Prefixes` | `Prefixes.Manage` | `PrefixValue` matching `[A-Z0-9]{3,12}` | `PrefixId`, `ResellerId`, `PrefixValue`, `IsActive` | `201`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `409 PrefixConflict` |
| `PATCH /Prefixes/{PrefixId}` | `Prefixes.Manage` | `PrefixValue` and/or `IsActive` | updated prefix | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 PrefixNotFound`, `409 PrefixConflict` |
| `DELETE /Prefixes/{PrefixId}` | `Prefixes.Manage` | Positive integer path id | `PrefixId`, `IsDeleted=true` | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 PrefixNotFound`, `409 PrefixInUse` |

## Users and roles

| Endpoint | PermissionKey | Request | Result | Responses |
|----------|---------------|---------|--------|-----------|
| `POST /Users` | `Users.Manage` | `Email`, `Password`, optional `TenantId`, `IsActive` | `UserId`, `Email`, `TenantId`, `IsActive` | `201`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `409 UserConflict` |
| `GET /Users` | `Users.Manage` | optional `Page`, `PageSize` 1 to 100, `TenantId`, `IsActive`, `Search` (see [`07-admin-list-envelope-hardening.md`](./07-admin-list-envelope-hardening.md)) | user collection plus `Attributes.Pagination` | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied` |
| `POST /Users/{UserId}/Roles` | `Roles.Assign` | `RoleId` integer | `UserId`, `RoleId`, `CreatedAt` | `201`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 UserNotFound`, `409 RoleAlreadyAssigned` |
| `DELETE /Users/{UserId}/Roles` | `Roles.Assign` | `RoleId` integer | `UserId`, `RoleId`, `IsDeleted=true` | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 RoleAssignmentNotFound` |
| `GET /Admin/Users/{UserId}/Roles` | `Users.Manage` | Positive integer path id | list of `{ RoleId, RoleName, GrantedAt }` | `200`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 UserNotFound` |

Passwords and password hashes never appear in results. Role assignment is server-authorized and never trusts a role claim supplied in the request body.

## Reseller quotas

Read-side surface for the `ResellerQuotas` and `ResellerQuotaLedger` tables owned by [`../41-reseller-quotas.md`](../41-reseller-quotas.md). Mutation of quotas is exclusive to the approval workflow (Plan 05 Layer C, [`../42-quota-requests.md`](../42-quota-requests.md) once landed) and to the transactional decrement on `POST /Licenses`; there is no direct `POST` or `PATCH` on either resource from the wire.

| Endpoint | PermissionKey | Request | Result | Responses |
|----------|---------------|---------|--------|-----------|
| `GET /Resellers/{ResellerId}/Quotas` | `Resellers.Manage` | Positive integer path id. Query: optional `Page`, `PageSize` 1 to 100, optional `LicenseCategoryId`, optional `LicenseTierId`, optional `IncludeExpired` boolean default `false` (see [`07-admin-list-envelope-hardening.md`](./07-admin-list-envelope-hardening.md)) | Collection of `{ ResellerId, LicenseCategoryId, LicenseTierId, LicensesGranted, LicensesConsumed, LicensesRemaining, PeriodStart, PeriodEnd }` plus `Attributes.Pagination` | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 ResellerNotFound` |
| `GET /Resellers/{ResellerId}/QuotaLedger` | `Resellers.Manage` | Positive integer path id. Query: optional `Page`, `PageSize` 1 to 100, optional `LicenseCategoryId`, optional `LicenseTierId`, optional `LedgerAction` enum `QuotaConsumed`/`QuotaRestored`/`QuotaAdjusted`, optional `From` and `To` ISO 8601 UTC (see [`07-admin-list-envelope-hardening.md`](./07-admin-list-envelope-hardening.md)) | Collection of `{ LedgerId, ResellerId, LicenseCategoryId, LicenseTierId, LedgerAction, Delta, LicenseId, QuotaRequestId, RequestId, ActorUserId, CreatedAt }` ordered by `CreatedAt` descending, plus `Attributes.Pagination` | `200`, `400 ValidationFailed`, `403 AuthzRoleDenied`, `403 AuthzPermissionDenied`, `404 ResellerNotFound` |

Reseller-scoped callers (role `Reseller` holding `Resellers.Manage`, path id equals `auth.reseller_id()`) MAY read their own rows; other `ResellerId` values return `403 AuthzPermissionDenied` per row-scope check in [`../04-roles.md`](../04-roles.md) §Authorization ladder (step 3), NOT `404`. Admin callers see all rows. Absolute `LicensesGranted`/`LicensesConsumed` counters are surfaced here (this is the owner endpoint) but MUST NOT be echoed by any error path (see AC-ERR-006, AC-API-LIC-006).



## Acceptance

- AC-API-ADM-001: Collection endpoints use `Page`/`PageSize` pagination per [`07-admin-list-envelope-hardening.md`](./07-admin-list-envelope-hardening.md). Cursor pagination is forbidden in v1.
- AC-API-ADM-002: Reseller scope checks occur before resource disclosure.
- AC-API-ADM-003: Every mutation records actor, action, target, request id, and safe changed fields.
- AC-API-ADM-004: Destructive Admin mutations (license issue, renew, revoke) honour `Idempotency-Key` per [`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md).
- AC-API-ADM-005: Every row above declares one `PermissionKey` from [`../40-permissions.md`](../40-permissions.md) §2, and a caller passing the role gate but missing that key receives `403 AuthzPermissionDenied` with the missing key named in `Attributes.Error.Details.Value`.
- AC-API-ADM-006: `GET /Resellers/{ResellerId}/Quotas` and `GET /Resellers/{ResellerId}/QuotaLedger` are read-only; there is no wire endpoint that mutates `ResellerQuotas` or appends to `ResellerQuotaLedger` outside the `POST /Licenses` decrement contract and the quota-approval workflow (Layer C).
- AC-API-ADM-007: A `Reseller` caller requesting `ResellerId` values other than their own `auth.reseller_id()` receives `403 AuthzPermissionDenied`, never `404 ResellerNotFound`, so scope leaks are not observable through 404 timing.