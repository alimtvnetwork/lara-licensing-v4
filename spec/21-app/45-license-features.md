# License Features

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1.
**Owner:** This file is the sole normative source for the `LicenseFeature` model: the canonical `FeatureKey` registry, the typed value domains, and the precedence rule that resolves a runtime feature payload for a verified serial. Every other spec file MUST reference this file rather than restate keys or precedence.
**Related:** [`04-roles.md`](./04-roles.md), [`05-license-categories.md`](./05-license-categories.md), [`10-endpoints.md`](./10-endpoints.md), [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md), [`11-api-contracts/03-verification-contracts.md`](./11-api-contracts/03-verification-contracts.md), [`12-error-taxonomy.md`](./12-error-taxonomy.md), [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md), [`43-license-tiers.md`](./43-license-tiers.md), [`44-environments.md`](./44-environments.md), [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md), [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md).

---

## 1. Model

A `LicenseFeature` is a typed key/value pair that gates a client capability at verify-time. Features are orthogonal to `LicenseCategory` (duration), `LicenseTier` (entitlement class), `LicenseEnvironment` (deployment stage), and `LicenseVariation` (seat/machine scoping). The runtime feature payload attached to a `POST /Verify/Final` success response (contract in [`11-api-contracts/03-verification-contracts.md`](./11-api-contracts/03-verification-contracts.md) §Response) MUST be the result of resolving the precedence rule in §4 against the verified `LicenseId` and its `LicenseTierId`.

`FeatureKey` is a closed, versioned registry (§2). `FeatureValue` is a typed scalar in one of three domains: `Boolean`, `Number`, or `String` (§3). Introducing a new key, renaming a key, or changing a key's value type requires bumping this file's Version, updating the `Features` catalog seed in [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md), and adding a Check row to [`99-consistency-report.md`](./99-consistency-report.md).

## 2. Canonical FeatureKey registry

`FeatureKey` values are PascalCase, dot-segmented, and match `^[A-Z][A-Za-z0-9]+(\.[A-Z][A-Za-z0-9]+)*$` per [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md). The v1 closed set is:

| `FeatureKey` | `ValueType` | Intent |
|--------------|-------------|--------|
| `Modules.Reports` | `Boolean` | Enables the Reports module. |
| `Modules.Api` | `Boolean` | Enables the public HTTP API. |
| `Limits.MaxUsers` | `Number` | Concurrent authenticated users cap. `0` MUST be treated as "no license"; `Unlimited` tier resolves to `-1` sentinel. |
| `Limits.MaxProjects` | `Number` | Owned-project count cap; same sentinel rules. |
| `Branding.Watermark` | `Boolean` | When `true`, the client MUST render the "Powered by" watermark. |
| `Support.Tier` | `String` | One of `Community`, `Standard`, `Priority`. Closed set enforced by the value validator in §3. |

Forbidden synonyms (MUST return `FeatureUnknown` if received on any admin write path): `feature.reports`, `report_module`, `max_users`, `usersLimit`, `watermark`, `supportLevel`.

## 3. Value type contract

| `ValueType` | JSON shape | Validation |
|-------------|------------|------------|
| `Boolean` | `true` or `false` | Strict; `0`, `1`, `"true"`, `"false"` MUST return `FeatureValueInvalid`. |
| `Number` | JSON number, finite | Integer for `Limits.*`; negative values allowed only as the documented `-1` "unlimited" sentinel. |
| `String` | JSON string | For `Support.Tier` the value MUST be one of the closed enum in §2; other `String` keys accept any UTF-8 up to 128 chars. |

Every admin write path that stores a `FeatureValue` MUST validate against this table before persisting; a violation returns `FeatureValueInvalid` (400) per [`12-error-taxonomy.md`](./12-error-taxonomy.md).

## 4. Precedence

The runtime feature map for a `LicenseId` is resolved deterministically:

1. Start from the empty map `M := {}`.
2. Load every `TierFeatures` row for the license's `LicenseTierId` and set `M[FeatureKey] := Value`. This is the tier default layer.
3. Load every `LicenseFeatures` row for the `LicenseId` and set `M[FeatureKey] := Value`. This is the per-license override layer.
4. Emit `M` as the `Features` object in the `POST /Verify/Final` response.

Precedence is strictly `LicenseFeatures` > `TierFeatures`. There is no third layer in v1. Absence of a key means "not licensed": clients MUST NOT infer a default at read-time.

## 5. Admin surfaces

Writes to `TierFeatures` and `LicenseFeatures` require the `Licenses.Update` permission per [`40-permissions.md`](./40-permissions.md); tier-scoped writes additionally require `Roles.Assign` because they change the effective grant across every license on that tier. Every write emits an audit row (`FeatureAssigned`, `FeatureRevoked`) per [`28-audit-action-enum.md`](./28-audit-action-enum.md).

## 6. Acceptance

- AC-FEAT-001: Every `FeatureKey` written to `Features`, `TierFeatures`, or `LicenseFeatures` matches the closed set in §2; violations return `FeatureUnknown` (400).
- AC-FEAT-002: Every `Value` matches the `ValueType` contract in §3; violations return `FeatureValueInvalid` (400).
- AC-FEAT-003: The `POST /Verify/Final` response `Features` map is the result of the §4 precedence resolution against the verified license.
- AC-FEAT-004: A `LicenseFeatures` row for `(LicenseId, FeatureKey)` overrides the `TierFeatures` row with the same `FeatureKey` for that license's `LicenseTierId`; asserted by an integration test seeded from this file.
- AC-FEAT-005: Removing a `LicenseFeatures` row causes the corresponding key to fall back to `TierFeatures` on the next verify, without any client-side cache reset.
- AC-FEAT-006: Every write to `TierFeatures` or `LicenseFeatures` emits exactly one `FeatureAssigned` or `FeatureRevoked` audit row per [`28-audit-action-enum.md`](./28-audit-action-enum.md).
