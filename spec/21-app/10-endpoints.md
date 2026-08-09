# Endpoints

**Version:** 1.4.0
**Updated:** 2026-07-17

## Vocabulary sources

Role strings cite [`04-roles.md`](./04-roles.md) §Canonical set. `PermissionKey` values cite [`40-permissions.md`](./40-permissions.md) §2 (Canonical `PermissionKey` set) verbatim. `LicenseCategoryId` values cite [`05-license-categories.md`](./05-license-categories.md) §Canonical set. `LicenseVariation` parameter names cite [`06-license-variations.md`](./06-license-variations.md) §Canonical set. Path casing rules cite [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md) §Path casing.

Every mutating or reading endpoint below declares exactly one `PermissionKey` from [`40-permissions.md`](./40-permissions.md) §2. Endpoints under §Auth (session establishment, token rotation, token revoke, OAuth handshake) and §Verification (AppBuilder OAuth) declare `None`: session establishment predates `has_permission()`, and verify endpoints are gated on OAuth client scope rather than on user permissions per [`04-roles.md`](./04-roles.md) §Permission Matrix. `None` is not a `PermissionKey`; it is the explicit exemption marker.

---

## Conventions

- All paths PascalCase segment names when they refer to resources.
- All JSON keys PascalCase.
- All timestamps ISO 8601 UTC.
- Every mutating call writes to `AuditLogs`.
- All responses use the universal envelope defined in [`11-api-contracts/00-overview.md`](./11-api-contracts/00-overview.md). Failure example:

```json
{
  "Status": { "IsSuccess": false, "Code": 404, "Message": "Not Found" },
  "Attributes": {
    "RequestId": "01HXYZ...",
    "Error": { "ErrorCode": "LicenseNotFound", "ErrorMessage": "License with the given serial does not exist." }
  },
  "Results": []
}
```

## Auth

| Endpoint | Method | Auth | PermissionKey |
|----------|--------|------|---------------|
| `/Auth/Token` | POST | none | None |
| `/Auth/Refresh` | POST | refresh token | None |
| `/Auth/Revoke` | POST | JWT | None |
| `/OAuth/Token` | POST | client secret | None |
| `/OAuth/Authorize` | GET | user session | None |
| `/OAuth/Revoke` | POST | client secret | None |
| `/OAuth/Introspect` | POST | client secret | None |

## Licenses

| Endpoint | Method | Auth | PermissionKey | Notes |
|----------|--------|------|---------------|-------|
| `/Licenses` | POST | Admin, Reseller | `Licenses.Create` | Create license. |
| `/Licenses/{LicenseId}` | GET | Admin, Reseller (own) | `Licenses.Read` | Read license. |
| `/Licenses/{LicenseId}` | PATCH | Admin, Reseller (own) | `Licenses.Update` | Update. |
| `/Licenses/{LicenseId}` | DELETE | Admin | `Licenses.Revoke` | Soft delete (revoke). |
| `/Licenses/{LicenseId}/Serials` | POST | Admin, Reseller (own) | `Serials.Issue` | Generate serial. |
| `/Serials/{SerialValue}` | GET | Admin, Reseller (own) | `Serials.Lookup` | Serial lookup. |

## Verification

| Endpoint | Method | Auth | PermissionKey | Notes |
|----------|--------|------|---------------|-------|
| `/Verify/Serial` | POST | AppBuilder OAuth | None | Existence + status check. |
| `/Verify/Hash` | POST | AppBuilder OAuth | None | Returns `VerifyKey`. |
| `/Verify/Final` | POST | AppBuilder OAuth | None | Consumes verify key, returns decision. |

## Resellers, Prefixes, Users

| Endpoint | Method | Auth | PermissionKey |
|----------|--------|------|---------------|
| `/Resellers` | POST, GET | Admin | `Resellers.Manage` |
| `/Resellers/{ResellerId}` | GET, PATCH, DELETE | Admin | `Resellers.Manage` |
| `/Resellers/{ResellerId}/Prefixes` | GET, POST | Admin, Reseller (own) | `Prefixes.Manage` |
| `/Prefixes/{PrefixId}` | PATCH, DELETE | Admin, Reseller (own) | `Prefixes.Manage` |
| `/Users` | POST, GET | Admin | `Users.Manage` |
| `/Users/{UserId}/Roles` | POST, DELETE | Admin | `Roles.Assign` |
| `/Admin/Users/{UserId}/Roles` | GET | Admin | `Users.Manage` |
| `/Me/Roles` | GET | Any authenticated | None |
| `/Resellers/{ResellerId}/Quotas` | GET | Admin, Reseller (own) | `Resellers.Manage` |
| `/Resellers/{ResellerId}/QuotaLedger` | GET | Admin, Reseller (own) | `Resellers.Manage` |

## Quota Requests

Contract lives in [`42-quota-requests.md`](./42-quota-requests.md); this table is the canonical route index.

| Endpoint | Method | Auth | PermissionKey | Notes |
|----------|--------|------|---------------|-------|
| `/Resellers/{ResellerId}/QuotaRequests` | POST | Admin, Reseller (own) | `Quotas.Request` | Submit a quota-increase request. `Idempotency-Key` required. |
| `/Resellers/{ResellerId}/QuotaRequests` | GET | Admin, Reseller (own) | `Quotas.Request` | List requests; Admin sees all with `Quotas.Approve`. |
| `/QuotaRequests/{RequestId}` | GET | Admin, Reseller (owner) | `Quotas.Request` | Read one; Admin sees all with `Quotas.Approve`. |
| `/QuotaRequests/{RequestId}/Approve` | POST | Admin | `Quotas.Approve` | Applies `+ApprovedDelta` atomically per [`42-quota-requests.md`](./42-quota-requests.md) §Approval. |
| `/QuotaRequests/{RequestId}/Deny` | POST | Admin | `Quotas.Approve` | Denies with `Reason`; no allowance change. |
| `/QuotaRequests/{RequestId}/Cancel` | POST | Reseller (owner) | `Quotas.Request` | Cancels while `Pending`. |
| `/Resellers/{ResellerId}/Quotas/{CategoryId}/Adjust` | POST | Admin | `Quotas.Adjust` | Direct signed-delta adjustment; MUST NOT drive `Allowance < Consumed`. |

## Self-Update (`/App/*`)

Contract lives in [`17-self-update-endpoint.md`](./17-self-update-endpoint.md); this table is the canonical route index.

| Endpoint | Method | Auth | PermissionKey | Notes |
|----------|--------|------|---------------|-------|
| `/App/UpdateManifest` | GET | `Stable` public, `Beta` requires `has_role(AppBuilder)` or `has_role(Admin)` | None | Returns latest manifest for `Product`, `Platform`, `Channel`. Cache-friendly. |
| `/App/UpdateAsset/{Version}/{Platform}` | HEAD, GET | Same as manifest channel | None | Streams binary; `X-Sha256` response header MUST match manifest checksum. |
| `/Admin/AppUpdates/UploadTicket` | POST | `has_role(Admin)` | `Updates.Publish` | Issues `UploadToken` + `UploadUrl` for a single platform asset. |
| `/Admin/AppUpdates` | POST | `has_role(Admin)` | `Updates.Publish` | Publishes manifest referencing uploaded assets. Idempotent by `(Product, Version, Channel)`. |

Strict-list endpoints (`/Admin/*`, `/Verify/*`, `/App/UpdateAsset/*`) MUST reject requests missing `X-Request-Id` with `RequestIdMissing` (400) per [`20-observability.md`](./20-observability.md).

## Rate Limits

- Verify endpoints: 60 req/min per `ClientId`.
- Auth endpoints: 10 req/min per IP.
- Mutating admin endpoints: 120 req/min per session.

## Acceptance

- AC-EP-001: Every endpoint returns the standard error envelope on failure.
- AC-EP-002: Every mutating call produces an `AuditLog` row containing `Actor`, `Action`, `TargetType`, `TargetId`, `RequestId`.
- AC-EP-003: Rate limits return `429 RateLimited` with `Retry-After` header.
- AC-EP-004: Requests and responses conform to [`11-api-contracts/`](./11-api-contracts/00-overview.md).
- AC-EP-005: Every route in this file appears in [`21-error-management-binding.md`](./21-error-management-binding.md) with a log level and retry class.
- AC-EP-006: Every mutating or reading row in this file declares exactly one `PermissionKey` from [`40-permissions.md`](./40-permissions.md) §2, or the literal `None` for session establishment (§Auth) and verify routes (§Verification). Enforced by the permission-parity linter added in Plan 05 Step 07.
