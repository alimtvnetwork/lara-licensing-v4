# License Environments

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1.
**Owner:** This file is the sole normative source for the `LicenseEnvironment` enum, its closed member set, ordinals, forbidden synonyms, and the contract-gating rules that keep a serial issued for one environment from verifying against another. Every other spec file MUST reference this file rather than restate members.
**Related:** [`04-roles.md`](./04-roles.md), [`05-license-categories.md`](./05-license-categories.md), [`06-license-variations.md`](./06-license-variations.md), [`10-endpoints.md`](./10-endpoints.md), [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md), [`11-api-contracts/03-verification-contracts.md`](./11-api-contracts/03-verification-contracts.md), [`12-error-taxonomy.md`](./12-error-taxonomy.md), [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md), [`43-license-tiers.md`](./43-license-tiers.md), [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md), [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md).

---

## 1. Model

A `LicenseEnvironment` names the deployment stage a license is provisioned for. Environment is orthogonal to `LicenseCategory` (duration), `LicenseTier` (entitlement class), and `LicenseVariation` (seat/machine scoping). Every `Licenses` row MUST carry a non-null `EnvironmentId`. A serial materialized against a license inherits that license's environment; the verification path in [`11-api-contracts/03-verification-contracts.md`](./11-api-contracts/03-verification-contracts.md) MUST reject a verify request whose caller-supplied `EnvironmentId` differs from the row's environment, returning `EnvironmentMismatch` (409) per [`12-error-taxonomy.md`](./12-error-taxonomy.md).

Environment is a lookup, not a computed field. The set is closed and versioned by this file. Adding, renaming, or removing a member requires bumping this file's Version, updating the CHECK constraint in [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §`Environments`, updating the seed row list in [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md) `S4a`, and adding a Check row to [`99-consistency-report.md`](./99-consistency-report.md).

## 2. Canonical enum

The `EnvironmentName` column of `Environments` MUST contain exactly one row for each of the following three values. No other value is legal in v1.

| `EnvironmentName` | Ordinal | Intent | Gating semantics |
|-------------------|---------|--------|------------------|
| `Production` | 1 | Live customer traffic. Default for paid licenses. | Verify path accepts `Production` only when the license row is `Production`. `Development` and `Staging` requests against a `Production` license MUST be rejected. |
| `Staging` | 2 | Pre-production integration testing by reseller or end-user teams. | Isolated from `Production`. A staging license MUST NOT be usable to verify a build shipped to production. |
| `Development` | 3 | Developer workstations and CI. Rate-limited and non-billable in reporting. | Isolated from both `Staging` and `Production`. Development-scoped serials MUST be excluded from production usage reports. |

Ordinals are stable identifiers for wire and log payloads: `EnvironmentName` is the canonical wire value, `EnvironmentId` is the FK, and the ordinal above is what serializers use when both a string and a comparable numeric are required. Ordinals MUST NOT be reused if a member is retired; retired ordinals move to the reserved list below.

Reserved ordinals: `4..7` are reserved and MUST NOT be issued to new environments without an entry in this section. `8+` is reserved for future multi-tenant sandbox partitioning and is off-limits in v1.

Forbidden synonyms (single-owner rule per [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md)):

- `Prod`, `Live`, `Release`, `Public` are forbidden aliases of `Production`.
- `Stage`, `PreProd`, `UAT`, `QA` are forbidden aliases of `Staging`.
- `Dev`, `Local`, `Sandbox`, `Test` are forbidden aliases of `Development`.

Every occurrence of a forbidden synonym anywhere under `spec/`, `src/`, `linter-scripts/`, `.lovable/`, or migrations is a consistency defect and MUST be caught by the vocabulary linter check declared in AC-LENV-004 below (this file and [`99-consistency-report.md`](./99-consistency-report.md) are the two allow-listed occurrences).

## 3. Contract gating

Environment participates in three gates:

1. **Issue-time gate.** `POST /Licenses` (see [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md)) MUST accept a `EnvironmentId` in the request body and reject any absent or non-member value with `ValidationFailed` (400) and `Details = [{ "Field": "EnvironmentId", "Rule": "MembershipRequired" }]`. This is a validator-layer failure with no transaction opened.
2. **Verify-time gate.** `POST /Verify/Serial`, `POST /Verify/Hash`, and `POST /Verify/Final` MUST include the caller's `EnvironmentId` (derived from the AppBuilder OAuth client's configured environment; end-user requests inherit from the AppBuilder client). Mismatch with the license row's `EnvironmentId` returns `EnvironmentMismatch` (409) with `Details = [{ "Field": "Environment", "Rule": "Mismatch", "Value": "<Requested>/<Licensed>" }]`. The response MUST NOT leak the license's `Environment`; the `Value` slot uses opaque markers `Requested` and `Licensed` and NEVER the actual environment names in the mismatch case, so an attacker cannot enumerate a license's environment by probing.
3. **Reporting gate.** Any report that aggregates license usage MUST group by `EnvironmentId` (not `EnvironmentName`) and MUST exclude `Development` from billable totals unless the report is explicitly a Development-scoped report.

Environment is orthogonal to reseller quota decrement: a reseller's `ResellerQuotas` row is NOT partitioned by environment in v1. This is a deliberate scope decision to keep quota provisioning simple; environment-partitioned quotas MAY be added in a later version and MUST land as a new file, not as an amendment here.

## 4. Bindings

- Physical: [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §`Environments` (canonical rows and CHECK), §`Licenses` (`EnvironmentId` NOT NULL FK).
- Migration: [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md) migration `05a create_environments_table` (L1) and seed `S4a seed_environments`.
- API: [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md) request body of `POST /Licenses` MUST accept and validate `EnvironmentId` against the closed set (validation surface for AC-LENV-002).
- Verify: [`11-api-contracts/03-verification-contracts.md`](./11-api-contracts/03-verification-contracts.md) MUST derive the caller's `EnvironmentId` from the AppBuilder OAuth client and enforce AC-LENV-004.
- Error: [`12-error-taxonomy.md`](./12-error-taxonomy.md) row `EnvironmentMismatch` (409) is the sole wire code for the verify-time gate; validator failures use the shared `ValidationFailed` code.

## 5. Acceptance

- **AC-LENV-001** `EnvironmentName` in `Environments` is constrained to exactly `{Production, Staging, Development}`. Verified by a CI schema test that attempts to insert any other value and expects the CHECK constraint violation `CkEnvironmentsMemberSet`.
- **AC-LENV-002** `POST /Licenses` (both reseller and Admin paths) rejects any request with `EnvironmentId` absent or referencing a value not present in `Environments` with `ValidationFailed` (400) and `Details = [{ "Field": "EnvironmentId", "Rule": "MembershipRequired" }]`; verified by contract test in the endpoint's AC block (AC-API-LIC-010).
- **AC-LENV-003** Every `Licenses` row created after migration `18 create_licenses_table` has a non-null `EnvironmentId`; enforced by NOT NULL at the column level and asserted in CI by a query that expects zero rows for `SELECT 1 FROM Licenses WHERE EnvironmentId IS NULL`.
- **AC-LENV-004** A verify request whose caller `EnvironmentId` differs from the license row's `EnvironmentId` returns `EnvironmentMismatch` (409); the response `Details.Value` is the opaque `"<Requested>/<Licensed>"` marker and MUST NOT contain the actual environment names. Verified by a contract test that issues a `Development` serial and probes it with a `Production` verify request.
- **AC-LENV-005** Ordinals `1..3` are stable across releases; ordinal reuse for a retired environment is a spec defect. Verified by a review-time check that hashes the `(EnvironmentName, Ordinal)` pairs in §2 and stores the hash in [`99-consistency-report.md`](./99-consistency-report.md) Check 24; a mismatch fails the check.
- **AC-LENV-006** The forbidden-synonym set in §2 is enforced by a linter check that scans `spec/`, `src/`, `linter-scripts/`, `.lovable/`, and every migration file in the repository for the exact tokens listed and fails the build if any occurrence is found outside this file and [`99-consistency-report.md`](./99-consistency-report.md); the linter MUST report the file path and line number for each hit.

---

## Changelog

- **1.0.0** (2026-07-20) Initial file. Establishes the `LicenseEnvironment` closed set (Production/Staging/Development), the three-gate model (issue-time validation, verify-time mismatch rejection, report grouping), forbidden synonyms, stable ordinals 1..3 with reserved range 4..7, and six acceptance criteria (AC-LENV-001..006). Introduced by Plan 05 Step 25.
