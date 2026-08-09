# Quota Request Contracts

**Version:** 1.0.0
**Status:** normative

## Vocabulary sources

`PermissionKey` column values cite [`../40-permissions.md`](../40-permissions.md) §2 verbatim. On permission failure, endpoints return `403 AuthzPermissionDenied` per [`../12-error-taxonomy.md`](../12-error-taxonomy.md).

## Quota Requests API

| Endpoint | PermissionKey | Request | Result | Responses |
|----------|---------------|---------|--------|-----------|
| `POST /QuotaRequests` | `Resellers.Manage` | `LicenseCategoryId`, `LicenseTierId`, `RequestedDelta` (positive integer), `Justification` (string, max 500) | `QuotaRequestId`, `Status` (Pending), `SubmittedAt` | `201`, `400 ValidationFailed`, `403 AuthzPermissionDenied`, `409 QuotaRequestConflict` |
| `GET /QuotaRequests` | `Resellers.Manage` | Query: optional `Page`, `PageSize`, optional `Status`, optional `ResellerId` (Admin only) | Collection of `QuotaRequest` DTOs + Pagination | `200`, `403 AuthzPermissionDenied` |
| `GET /QuotaRequests/{Id}` | `Resellers.Manage` | UUID path parameter | Single `QuotaRequest` DTO | `200`, `403 AuthzPermissionDenied`, `404 QuotaRequestNotFound` |
| `POST /QuotaRequests/{Id}/Approve` | `Quotas.Approve` | `ApprovedDelta` (positive integer), optional `Note` | `QuotaRequestId`, `Status` (Approved), `DecidedAt` | `200`, `403 AuthzPermissionDenied`, `404 QuotaRequestNotFound`, `409 QuotaRequestNotPending` |
| `POST /QuotaRequests/{Id}/Deny` | `Quotas.Approve` | `DenialReason` (string, required) | `QuotaRequestId`, `Status` (Denied), `DecidedAt` | `200`, `403 AuthzPermissionDenied`, `404 QuotaRequestNotFound`, `409 QuotaRequestNotPending` |
| `POST /QuotaRequests/{Id}/Cancel` | `Resellers.Manage` | None | `QuotaRequestId`, `Status` (Cancelled) | `200`, `403 AuthzPermissionDenied`, `404 QuotaRequestNotFound`, `409 QuotaRequestNotPending` |

## Data Transfer Objects

### QuotaRequest DTO
```json
{
  "Id": "uuid",
  "ResellerId": "uuid",
  "LicenseCategoryId": 1,
  "LicenseTierId": 1,
  "RequestedDelta": 50,
  "ApprovedDelta": 50,
  "Status": "Pending|Approved|Denied|Cancelled",
  "SubmittedByUserId": "uuid",
  "SubmittedAt": "2026-08-06T10:14:00Z",
  "DecidedByUserId": "uuid",
  "DecidedAt": "2026-08-06T10:20:00Z",
  "Justification": "Expanding to region X",
  "DenialReason": null
}
```

## Approval Obligations

1. **Transactional Integrity.** `POST /QuotaRequests/{Id}/Approve` MUST execute the following in a single transaction:
   - Lock `QuotaRequests` row `FOR UPDATE` and assert `Status = 'Pending'`.
   - Lock `ResellerQuotas` row `(ResellerId, CategoryId, TierId)` `FOR UPDATE`.
   - Increment `ResellerQuotas.LicensesGranted` by `ApprovedDelta`.
   - Append `ResellerQuotaLedger` row (Action: `QuotaAdjusted`, Delta: `ApprovedDelta`, `QuotaRequestId`: `{Id}`).
   - Update `QuotaRequests` to `Status = 'Approved'`, setting `ApprovedDelta` and `DecidedByUserId`.
   - Emit `QuotaRequestApproved` audit log.

2. **Error Cases.**
   - `409 QuotaRequestNotPending`: Thrown if attempting to Approve, Deny, or Cancel a request that is already decided.
   - `409 QuotaRequestConflict`: Thrown if a reseller attempts to open a second `Pending` request for the same (Category, Tier) tuple.

## Acceptance

- AC-QREQ-001: Resellers can only list and read their own quota requests.
- AC-QREQ-002: Admins can list and read all quota requests.
- AC-QREQ-003: `RequestedDelta` and `ApprovedDelta` must be positive integers > 0.
- AC-QREQ-004: All mutation endpoints REQUIRE `Idempotency-Key` header.
- AC-QREQ-005: `DenialReason` is mandatory for `POST /Deny`.
- AC-QREQ-006: `ApprovedDelta` defaults to `RequestedDelta` but may be overridden by the Admin.
