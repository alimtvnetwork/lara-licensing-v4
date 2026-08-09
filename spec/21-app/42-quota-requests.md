# Quota Approval Workflow

**Version:** 1.0.0
**Status:** stable

Defines the state machine and approval workflow for reseller quota increase requests.

## 1. State Machine

Quota requests follow a linear lifecycle:
`Pending -> Approved | Denied | Cancelled`

- **Pending.** Initial state upon submission.
- **Approved.** Admin approved the request; `ResellerQuotas` allowance increased, ledger updated.
- **Denied.** Admin rejected the request with a reason.
- **Cancelled.** Reseller withdrew the request while still `Pending`.

## 2. Data Model

### `QuotaRequests`
| Field | Type | Description |
| :--- | :--- | :--- |
| Id | uuid | Primary key. |
| ResellerId | uuid | Foreign key to `Reseller`. |
| LicenseCategoryId | integer | |
| LicenseTierId | integer | |
| RequestedDelta | integer | Number of additional licenses requested. |
| ApprovedDelta | integer | Actual number granted (may differ from requested). |
| Status | enum | `Pending`, `Approved`, `Denied`, `Cancelled`. |
| SubmittedByUserId | uuid | |
| SubmittedAt | datetime | |
| DecidedByUserId | uuid | Admin who approved/denied. |
| DecidedAt | datetime | |
| DenialReason | string | Required when status is `Denied`. |
| Justification | string | Reseller's reasoning for the request. |

## 3. Approval Invariants

1. **Transactional Allowance Increase.** Approval MUST:
   - SELECT the matching `ResellerQuotas` row `FOR UPDATE`.
   - UPDATE `ResellerQuotas` setting `LicensesGranted = LicensesGranted + ApprovedDelta`.
   - INSERT a `ResellerQuotaLedger` row with `Delta = ApprovedDelta`, `Action = 'Adjusted'`.
   - UPDATE `QuotaRequests` status to `Approved`.
   - All steps MUST run in the same transaction. (AC-QR-002, AC-QR-003)
2. **Idempotency.** Every mutation (`POST /QuotaRequests/{Id}/Approve`, etc.) MUST use `Idempotency-Key` header to prevent double-approval or double-denial. (AC-API-QR-004)
3. **No Self-Approval.** An Admin MUST NOT approve their own request (if they happen to be linked to a reseller). (AC-QR-007)
4. **Lifecycle.** Decisions are final. Once `Approved`, `Denied`, or `Cancelled`, status is immutable. (AC-QR-004)

## 4. Audit Actions

- `QuotaRequestSubmitted`
- `QuotaRequestApproved`
- `QuotaRequestDenied`

## 5. Acceptance Criteria

- AC-QR-001: Linear lifecycle `Pending -> Approved | Denied | Cancelled`.
- AC-QR-002: Approval MUST selectively lock `ResellerQuotas` row `FOR UPDATE`.
- AC-QR-003: Approval delta MUST be appended to `ResellerQuotaLedger` in same transaction.
- AC-QR-004: Decided status (Approved/Denied) is immutable.
- AC-QR-005: Deny requires a `DenialReason` >= 10 chars.
- AC-QR-006: Cancel is only permitted for `Pending` requests.
- AC-QR-007: Approval MUST NOT be self-decided (Admin != Submitter).
