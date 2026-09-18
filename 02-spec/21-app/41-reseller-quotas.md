# Reseller Quota Economy

**Version:** 1.0.0
**Status:** stable

Defines the transactional logic for reseller license quotas, ensuring that license issuance is constrained by pre-allocated allowances.

## 1. Data Model

### `ResellerQuotas`
| Field | Type | Description |
| :--- | :--- | :--- |
| ResellerId | uuid | Foreign key to `Reseller`. |
| LicenseCategoryId | integer | Foreign key to `LicenseCategory`. |
| LicenseTierId | integer | Foreign key to `LicenseTier`. |
| LicensesGranted | integer | Total licenses allocated to this reseller for this (Category, Tier) in the current period. |
| LicensesConsumed | integer | Number of licenses already issued. |
| LicensesRemaining | integer | Calculated: `LicensesGranted - LicensesConsumed`. |
| PeriodStart | datetime | Start of the quota window (UTC). |
| PeriodEnd | datetime | End of the quota window (UTC). |

### `ResellerQuotaLedger` (Append-only)
| Field | Type | Description |
| :--- | :--- | :--- |
| Id | uuid | Primary key. |
| ResellerId | uuid | |
| LicenseCategoryId | integer | |
| LicenseTierId | integer | |
| Delta | integer | Signed change (e.g., `-1` for consumption, `+50` for grant). |
| Action | string | One of: `Consumed`, `Restored`, `Adjusted`. |
| ReferenceId | uuid | ID of the related License, QuotaRequest, or Audit log entry. |
| OccurredAt | datetime | |

## 2. Invariants

1. **Transactional Decrement.** Every `POST /Licenses` by a reseller MUST:
   - SELECT the matching `ResellerQuotas` row `FOR UPDATE`.
   - ASSERT `LicensesConsumed < LicensesGranted`.
   - UPDATE `ResellerQuotas` setting `LicensesConsumed = LicensesConsumed + 1`.
   - INSERT a `ResellerQuotaLedger` row with `Delta = -1`, `Action = 'Consumed'`.
   - All steps MUST run in the same database transaction as the license creation.
2. **Quota Exhaustion.** If the assertion fails, the API MUST return `409 QuotaExhausted`.
3. **Admin Exemption.** Admin-issued licenses (where `auth.reseller_id()` is null) do NOT touch the quota economy.

## 3. Operations

- **Restoration.** When a license is revoked within its first 24 hours (grace period), the quota is restored (`LicensesConsumed` decremented, ledger `+1`).
- **Adjustment.** Admins increase `LicensesGranted` via the quota approval workflow.
