# License Tiers

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1.
**Owner:** This file is the sole normative source for the `LicenseTier` enum, its canonical member set, forbidden synonyms, and the reseller authorization model that ties a `Licenses` row to a `ResellerQuotas` row through `LicenseTierId`. Every other spec file MUST reference this file rather than restate members.
**Related:** [`04-roles.md`](./04-roles.md), [`05-license-categories.md`](./05-license-categories.md), [`06-license-variations.md`](./06-license-variations.md), [`10-endpoints.md`](./10-endpoints.md), [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md), [`12-error-taxonomy.md`](./12-error-taxonomy.md), [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md), [`40-permissions.md`](./40-permissions.md), [`41-reseller-quotas.md`](./41-reseller-quotas.md), [`42-quota-requests.md`](./42-quota-requests.md), [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md), [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md).

---

## 1. Model

A `LicenseTier` names the entitlement class of a single license row. Tier is orthogonal to `LicenseCategory` (which names duration semantics per [`05-license-categories.md`](./05-license-categories.md)) and to `LicenseVariation` (which names seat/machine scoping per [`06-license-variations.md`](./06-license-variations.md)). Every `Licenses` row MUST carry a non-null `LicenseTierId`; every `ResellerQuotas` row is keyed by `(ResellerId, LicenseCategoryId, LicenseTierId, PeriodStart)` so that reseller entitlement is priced and audited per tier.

Tier is a lookup, not a computed field. The set is closed and versioned by this file. Adding, renaming, or removing a member requires bumping this file's Version, updating the CHECK constraint in [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §`LicenseTiers`, updating the seed row list in [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md) `S5`, and adding a Check row to [`99-consistency-report.md`](./99-consistency-report.md).

## 2. Canonical enum

The `TierName` column of `LicenseTiers` MUST contain exactly one row for each of the following four values. No other value is legal in v1.

| `TierName` | Ordinal | Intent | Ledger allowance semantics |
|------------|---------|--------|----------------------------|
| `Tier1` | 1 | Entry-level. Small volumes, single-product resellers. | `LicensesGranted` typically in the low tens. |
| `Tier2` | 2 | Mid-tier. Multi-product resellers with recurring volume. | `LicensesGranted` typically in the low hundreds. |
| `Tier3` | 3 | High-volume resellers with committed floors. | `LicensesGranted` typically in the low thousands. |
| `Unlimited` | 4 | Reserved for internal Admin-owned quota rows and pre-sales trials. | `LicensesGranted` is a large sentinel value; the reseller quota decrement path MUST still run so that the ledger invariant in [`41-reseller-quotas.md`](./41-reseller-quotas.md) §5 continues to hold. |

Ordinals are stable identifiers for wire and log payloads that need a numeric tier: `TierName` is the canonical wire value, `LicenseTierId` is the FK, and the ordinal above is what serializers use when both a string and a comparable numeric are required. Ordinals MUST NOT be reused if a member is retired; retired ordinals move to the reserved list below.

Reserved ordinals: `5..15` are reserved and MUST NOT be issued to new tiers without an entry in this section.

Forbidden synonyms (single-owner rule per [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md)):

- `Basic`, `Bronze`, `Starter`, `Small` are forbidden aliases of `Tier1`.
- `Standard`, `Silver`, `Growth`, `Medium` are forbidden aliases of `Tier2`.
- `Premium`, `Gold`, `Enterprise`, `Large`, `Pro` are forbidden aliases of `Tier3`.
- `Infinite`, `NoLimit`, `Unbounded`, `Uncapped` are forbidden aliases of `Unlimited`.

Every occurrence of a forbidden synonym anywhere under `spec/`, `src/`, `linter-scripts/`, `.lovable/`, or migrations is a consistency defect and MUST be caught by `linter-scripts/ac-index-parity.py`'s sibling check for vocabulary (extension tracked by AC-LT-004 below).

## 3. Reseller authorization model

A `Reseller` MAY issue a license at a given tier only if a matching `ResellerQuotas` row exists for `(ResellerId, LicenseCategoryId, LicenseTierId)` in an active period. Provisioning of tier access is not implicit in the reseller record. Concretely:

- Absence of a matching quota row is surfaced as `QuotaCategoryUnauthorized` (403) per [`12-error-taxonomy.md`](./12-error-taxonomy.md) with `Details = [{ "Field": "Quota", "Rule": "NotProvisioned", "Value": "<Category>/<Tier>" }]`. The `(Category, Tier)` pair is the atomic authorization unit: there is no separate "tier is not enabled for this reseller" error class; a missing pair is unauthorized.
- Presence with `LicensesRemaining <= 0` is surfaced as `QuotaExhausted` (409) per the same file. The distinction between "never provisioned" and "provisioned but depleted" is normative and MUST NOT collapse.
- `Admin`-issued licenses bypass reseller quota decrement (see [`41-reseller-quotas.md`](./41-reseller-quotas.md) §4 last paragraph) but still MUST carry a non-null `LicenseTierId` for reporting and audit continuity.

Any earlier reference to a hypothetical `TierUnauthorizedForReseller` error code is a documentation artifact; the correct code is `QuotaCategoryUnauthorized`.

## 4. Bindings

- Physical: [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §`LicenseTiers` (canonical rows and CHECK), §`Licenses` (`LicenseTierId` NOT NULL FK), §`ResellerQuotas` (`LicenseTierId` NOT NULL FK), §`ResellerQuotaLedger` (`LicenseTierId` NOT NULL FK).
- Migration: [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md) migration `04 create_license_tiers_table` and seed `S5 seed_license_tiers`.
- API: [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md) request body of `POST /Licenses` MUST accept and validate `LicenseTierId` against the closed set (validation surface for AC-LT-002).
- Quota flow: [`41-reseller-quotas.md`](./41-reseller-quotas.md) §3 and §4 use `LicenseTierId` in the FOR UPDATE resolution key.
- Reports: any report that groups by tier MUST use `LicenseTierId` (not `TierName`) as the group-by column to keep the query planner off the varchar.

## 5. Acceptance

- **AC-LT-001** `TierName` in `LicenseTiers` is constrained to exactly `{Tier1, Tier2, Tier3, Unlimited}`. Verified by a CI schema test that attempts to insert any other value and expects the CHECK constraint violation `CkLicenseTiersMemberSet`.
- **AC-LT-002** `POST /Licenses` (both reseller and Admin paths) rejects any request with `LicenseTierId` absent or referencing a value not present in `LicenseTiers` with `ValidationFailed` (400) and `Details = [{ "Field": "LicenseTierId", "Rule": "MembershipRequired" }]`; verified by contract test in the endpoint's AC block.
- **AC-LT-003** Every `Licenses` row created after migration 18 has a non-null `LicenseTierId`; enforced by NOT NULL at the column level and asserted in CI by a query that expects zero rows for `SELECT 1 FROM Licenses WHERE LicenseTierId IS NULL`.
- **AC-LT-004** The forbidden-synonym set in §2 is enforced by a linter check that scans `spec/`, `src/`, `linter-scripts/`, `.lovable/`, and every migration file in the repository for the exact tokens listed and fails the build if any occurrence is found outside this file and [`99-consistency-report.md`](./99-consistency-report.md); the linter MUST report the file path and line number for each hit.
- **AC-LT-005** Ordinals `1..4` are stable across releases; ordinal reuse for a retired tier is a spec defect. Verified by a review-time check that hashes the `(TierName, Ordinal)` pairs in §2 and stores the hash in [`99-consistency-report.md`](./99-consistency-report.md) Check 23; a mismatch fails the check.
