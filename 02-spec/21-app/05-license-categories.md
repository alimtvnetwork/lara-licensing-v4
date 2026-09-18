# License Categories

**Version:** 1.3.0
**Updated:** 2026-07-22

---

## Canonical set (single source of truth)

This file is the sole normative source for the `LicenseCategory` enum. Any other spec file (including [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md)) MUST point here rather than restate members. Values are PascalCase and stable; renaming is a breaking change.

| Ordinal (`LicenseCategoryId`) | Canonical | Duration | Renewable | Forbidden synonyms | Notes |
|---|---|---|---|---|---|
| `1` | `Daily` | 24 h | Yes | `daily`, `24h`, `OneDay` | Rolling from activation. |
| `2` | `Weekly` | 7 d | Yes | `weekly`, `7d`, `OneWeek` | |
| `3` | `Monthly` | 30 d | Yes | `monthly`, `30d`, `OneMonth` | |
| `4` | `Yearly` | 365 d | Yes | `yearly`, `Annual`, `1y` | |
| `5` | `Lifetime` | Never expires | No | `lifetime`, `Perpetual`, `Forever` | Bound to `Machine` and/or `User`. |
| `6` | `Dev` | 90 d | Yes | `dev`, `Developer`, `Trial` | Time-cadence marker for internal/pre-release builds, NOT an environment. See §Orthogonality with `LicenseEnvironment` below. Non-billable per reporting rules but MAY be issued for any `LicenseEnvironment` value. |
| `7` | `Key` | Perpetual, unlockable | No | `key`, `FeatureKey`, `Unlock` | Feature-flag key, not time-based. |

Ordinals `1..7` are seeded and stable. Ordinals `8..15` are reserved; renumbering or reusing an ordinal is a breaking change. `LicenseCategoryId` on the wire and in the database equals the ordinal in this table verbatim; no code path MAY substitute a name-to-id lookup that produces a different mapping. Collection name is `LicenseCategories`; forbidden collection synonyms are `Categories`, `license_categories`, `LicenseTypes`.

## Cross references

- Endpoint map: [`10-endpoints.md`](./10-endpoints.md) uses `LicenseCategoryId` on license creation.
- Error taxonomy: [`12-error-taxonomy.md`](./12-error-taxonomy.md) `ValidationInvalidCategory` fires on unknown values.
- API contracts: [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md) request bodies cite these strings verbatim.

## Storage

Represented as `LicenseCategoryId` int join to `LicenseCategories` table. Enum values live in code as `LicenseCategory` PascalCase enum backed by the same integer id.

## Orthogonality with `LicenseEnvironment`

`LicenseCategory` is a time-cadence axis (Daily/Weekly/Monthly/Yearly/Lifetime/Dev/Key). `LicenseEnvironment` per [`44-environments.md`](./44-environments.md) is an isolation axis (Production/Staging/Development). The two are orthogonal: a `Dev` category license MAY be issued for a `Production` environment (for example, a 90-day internal build shipped to a production tenant), and a `Yearly` category license MAY be issued for a `Development` environment (a full-year seat for a developer workstation). The `Dev` category MUST NOT be treated as a synonym for the `Development` environment anywhere in the codebase, migrations, or documentation. Every `Licenses` row therefore carries both `LicenseCategoryId` (this file) AND `EnvironmentId` (44-environments.md); the two are independently validated on `POST /Licenses` and independently gated on the verify path.

The forbidden-synonym list in [`44-environments.md`](./44-environments.md) §2 already bans the token `Dev` as an alias for `Development`; that ban is category-safe because this file owns the `Dev` category token and the linter allow-lists this file's `Dev` occurrences through the `24-vocabulary-normalization.md` single-owner rule.

## Acceptance

- AC-CAT-001: Every `License` row has a valid `LicenseCategoryId`.
- AC-CAT-002: `Lifetime` licenses never appear in expiry sweeps.
- AC-CAT-003: `Dev` licenses cannot be issued to `EndUser`-facing resellers.
- AC-CAT-004: `LicenseCategory` and `LicenseEnvironment` are orthogonal: for each `(LicenseCategoryId, EnvironmentId)` pair from the closed sets in this file and [`44-environments.md`](./44-environments.md) §2, the pair MUST be permitted by `POST /Licenses` validation unless a category-specific rule elsewhere (for example AC-CAT-003) forbids it; no code path MAY collapse `LicenseCategory.Dev` and `LicenseEnvironment.Development` into a single field. Verified by a contract test that issues one license for each of the 21 `(Category x Environment)` combinations and asserts each stored row carries the independent IDs.
- AC-CAT-005: `POST /Licenses` MUST reject any `LicenseCategoryId` outside the closed ordinal set `{1,2,3,4,5,6,7}` of §Canonical set with `400 ValidationFailed` before any RLS or quota work runs; the client MUST enforce the same closed set with a `z.union(z.literal)` guard so the rejection is observable on both sides of the wire without a round-trip. Verified by `tests/license-closed-sets.test.ts` (client) and by the contract test that also proves AC-CAT-004 (server).


