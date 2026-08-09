# Quota Restore on License Revoke

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1.
**Owner:** This file is the sole normative source for when and how a `Licenses` revoke restores a `ResellerQuotas` row. It closes the gap left by [`41-reseller-quotas.md`](./41-reseller-quotas.md) §5, which defined the `QuotaRestored` ledger action without specifying the triggering path.
**Related:** [`10-endpoints.md`](./10-endpoints.md) §Licenses, [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md), [`15-license-lifecycle.md`](./15-license-lifecycle.md), [`22-retry-and-idempotency.md`](./22-retry-and-idempotency.md), [`28-audit-action-enum.md`](./28-audit-action-enum.md), [`40-permissions.md`](./40-permissions.md), [`41-reseller-quotas.md`](./41-reseller-quotas.md), [`99-consistency-report.md`](./99-consistency-report.md) Check 21, [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md).

---

## 1. When restoration fires

Restoration is deterministic. Exactly one condition triggers a `QuotaRestored` ledger row and a `+1` bump on `LicensesRemaining`:

> `DELETE /Licenses/{LicenseId}` succeeds AND the target row's `IssuerActorType = Reseller` AND the target row's `ResellerQuotaLedgerId` (the `QuotaConsumed` row created at issuance) is non-null.

All other paths leave quotas untouched:

| Path | Quota impact |
|------|--------------|
| Admin-issued license (`IssuerActorType = Admin`) revoked | None. Admin issuance skipped the ledger at §41.4 step 4, so there is nothing to restore. |
| Reseller-issued license transitioning to `Expired` via `PeriodEnd` | None. Time-based expiration is not a revoke; the seat is spent. |
| Reseller-issued license `Suspended` then unsuspended | None. Suspension is reversible; the seat remains charged. |
| Same `LicenseId` revoked twice (idempotent retry) | None on the second call. The `ResellerQuotaLedger` `UNIQUE (LicenseId, LedgerAction = 'QuotaRestored')` constraint blocks a duplicate restore. |
| Revoke after `PeriodEnd` of the quota row that funded issuance | None. Restoring into a closed period would create a negative-time credit; the ledger row is suppressed and `LicensesConsumed` on the closed row is left as-is. Audit records `QuotaRestoreSkipped` (see §5). |

## 2. Transactional contract

`DELETE /Licenses/{LicenseId}` (Admin, `Licenses.Revoke` per [`40-permissions.md`](./40-permissions.md) §2) MUST run atomically:

1. `SELECT ... FOR UPDATE` on `Licenses` by primary key. If missing, `404 LicenseNotFound`. If `RevokedAt IS NOT NULL`, short-circuit with the original response body via idempotency replay per [`22-retry-and-idempotency.md`](./22-retry-and-idempotency.md).
2. Compute restoration eligibility per §1. If ineligible, jump to step 6.
3. `SELECT ... FOR UPDATE` on the `ResellerQuotas` row identified by `(ResellerId, LicenseCategoryId, LicenseTierId, PeriodStart)` copied from the original `QuotaConsumed` ledger row (NOT recomputed from `NOW()`; the same physical quota row that was charged must be credited).
4. Verify the quota row is still open (`PeriodEnd IS NULL OR PeriodEnd > NOW()`). If closed, skip to step 6 with `RestoreSkippedReason = ClosedPeriod`.
5. Append one row to `ResellerQuotaLedger` with `LedgerAction = QuotaRestored`, `Delta = +1`, `LicenseId = target`, `ActorUserId = caller`, `RequestId = X-Request-Id`. Decrement `LicensesConsumed` by 1 on the quota row. The `UNIQUE (LicenseId) WHERE LedgerAction = 'QuotaRestored'` partial index is the durable idempotency guard; a second attempt raises unique-violation and MUST be translated to a successful idempotent replay, not `QuotaLedgerConflict`.
6. UPDATE `Licenses` setting `RevokedAt = NOW()`, `RevokedBy = caller.UserId`, `RevokeReason = <request body>`.
7. Commit. Emit audit action `LicenseRevoked` per [`28-audit-action-enum.md`](./28-audit-action-enum.md) with `PayloadJson.QuotaRestored = true|false` and, when false, `PayloadJson.RestoreSkippedReason` in `{ AdminIssued, ClosedPeriod, TimeExpired, AlreadyRestored }`.

Steps 1-7 run inside a single DB transaction. A failure at any step rolls back the whole request; no partial state is permitted.

## 3. Concurrency

Two operators revoking the same license race in step 1: the loser's `SELECT ... FOR UPDATE` blocks, then observes `RevokedAt IS NOT NULL` and takes the idempotent-replay branch. There is no window in which two `QuotaRestored` rows can be written for the same `LicenseId`; the partial unique index is the ultimate guard, but row-level locking is the primary mechanism so the unique-violation path is rare and only fires on true idempotency retries.

Restoration and consumption never contend for the same ledger row because ledger is append-only; they contend only for the parent `ResellerQuotas` row's `FOR UPDATE` lock, which is the correct serialization point.

## 4. Ledger invariant (unchanged from §41)

Check 21 in [`99-consistency-report.md`](./99-consistency-report.md) still requires `SUM(Delta) OVER (ResellerId, LicenseCategoryId, LicenseTierId, PeriodStart) = -LicensesConsumed` for every quota row. This spec is the operational path that keeps that invariant true during revoke.

## 5. Skipped-restore observability

When step 4 short-circuits with `RestoreSkippedReason = ClosedPeriod`, or step 2 concludes ineligibility with `AdminIssued`, `TimeExpired`, or `AlreadyRestored`, the handler MUST:

1. Emit the audit `LicenseRevoked` row with `PayloadJson.QuotaRestored = false` and the reason.
2. Emit a `Warn`-level structured log line `quota.restore.skipped { LicenseId, Reason, ResellerId, LicenseCategoryId, LicenseTierId }` per [`20-observability.md`](./20-observability.md).
3. NOT return an error to the caller; revoke succeeds regardless of restore eligibility.

Silent skip (no audit, no log) is forbidden by [`03-error-manage/`](../03-error-manage/) rules.

## 6. Error mapping

No new error codes are introduced by this spec. Reused codes:

| Condition | ErrorCode | HTTP |
|-----------|-----------|------|
| License not found | `LicenseNotFound` | 404 |
| Caller lacks `Licenses.Revoke` | `AuthzPermissionDenied` | 403 |
| Ledger unique-violation on `QuotaRestored` NOT resolvable by idempotent replay (corrupted request state) | `QuotaLedgerConflict` | 409 |

## 7. Acceptance criteria

- **AC-QREST-001** A reseller-issued license revoked while its quota row is open produces exactly one `QuotaRestored` ledger row with `Delta = +1` targeting the same `(ResellerId, LicenseCategoryId, LicenseTierId, PeriodStart)` that was originally charged, and decrements `LicensesConsumed` by 1 on that row, in the same transaction as the `Licenses` UPDATE.
- **AC-QREST-002** An admin-issued license revoked never produces a `QuotaRestored` ledger row; audit payload records `QuotaRestored = false, RestoreSkippedReason = AdminIssued`.
- **AC-QREST-003** A reseller-issued license revoked after its funding quota row's `PeriodEnd` never produces a ledger row; audit payload records `RestoreSkippedReason = ClosedPeriod` and one `Warn` log line fires.
- **AC-QREST-004** Repeating the same revoke `Idempotency-Key` returns the original response and does NOT write a duplicate `QuotaRestored` row; `LicensesConsumed` moves by 1 total, not 2.
- **AC-QREST-005** After any sequence of `POST /Licenses` and `DELETE /Licenses/{LicenseId}` operations, Check 21 holds: `SUM(Delta)` across `ResellerQuotaLedger` equals `-LicensesConsumed` for every quota row.
- **AC-QREST-006** Two concurrent `DELETE /Licenses/{LicenseId}` requests against the same license produce exactly one `QuotaRestored` ledger row and one `LicenseRevoked` audit row; the loser observes idempotent replay, not an error.

IDs are registered in [`97-acceptance-criteria.md`](./97-acceptance-criteria.md) by the next runtime plan step; the ledger uniqueness partial index and `ResellerQuotaLedgerId` back-reference column are added to [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) in the same migration.

---

## Changelog

- **1.0.0** (2026-07-26) Initial file. Defines the revoke path that produces `QuotaRestored` ledger rows, the closed-period skip case, idempotent-replay semantics, and six acceptance criteria closing the gap left by 41-reseller-quotas.md §5.
