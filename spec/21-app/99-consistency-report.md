# Consistency Report

Version: 1.7.0
Status: Zero open findings. Regenerated after Plan 05 steps 37-38 (route/DTO index rebind + three new orthogonality/parity checks) landed on top of Plan 05 steps 21-22.

## Check 26: tier/environment/category orthogonality (Plan 05 step 38)

- Three canonical enums are owned by three different leaf files with zero overlap in members: [`43-license-tiers.md`](./43-license-tiers.md) §2 owns `{Tier1, Tier2, Tier3, Unlimited}` (billing/quota axis); [`44-environments.md`](./44-environments.md) §2 owns `{Production, Staging, Development}` (runtime environment axis); [`05-license-categories.md`](./05-license-categories.md) §Canonical set owns `{Dev, Standard, Enterprise}` (time-cadence axis, v1.2.0 AC-CAT-004 pins that `Dev` is cadence-only and does NOT imply an environment).
- `Licenses` (per [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md)) carries three independent NOT NULL FKs (`LicenseCategoryId`, `LicenseTierId`, `EnvironmentId`); the trigger `TrgLicensesTierMatchesQuota` binds tier to `ResellerQuotas`, `EnvironmentId` is immutable per AC-ENV-002, and no FK forces one axis from another.
- `POST /Licenses` requires all three IDs as independent inputs per [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md); `POST /Verify/Final` echoes all three per [`11-api-contracts/03-verification-contracts.md`](./11-api-contracts/03-verification-contracts.md); `EnvironmentMismatch` (409) is the only cross-axis error and originates in `44-environments.md` alone.
- Forbidden-synonyms sweep: no file under `spec/21-app/` uses `Tier1..Unlimited` to name an environment, nor `Production/Staging/Development` to name a tier, nor `Dev` to describe an environment (beyond the AC-CAT-004 disambiguation stanza in `05-license-categories.md`).

GREEN.


## Check 25: quota-ledger conservation across every write path (Plan 05 step 38)

- Invariant `SUM(ResellerQuotaLedger.Delta) = -ResellerQuotas.LicensesConsumed` per `(ResellerId, LicenseCategoryId, LicenseTierId)` (AC-ADB-012) is asserted by CI after every migration and after every decrement/adjustment path (issue, approve, adjust, restore).
- Every mutation path writes exactly one ledger row: `POST /Licenses` reseller branch (`QuotaConsumed`, negative delta) per [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md) §Transactional decrement; `POST /QuotaRequests/{RequestId}/Approve` (`QuotaAdjusted`, positive delta on allowance) per [`11-api-contracts/05-quota-request-contracts.md`](./11-api-contracts/05-quota-request-contracts.md) §Approval obligations; `POST /Resellers/{ResellerId}/Quotas/{CategoryId}/Adjust` (`QuotaAdjusted`, signed delta) per §Adjust; `DELETE /Licenses/{LicenseId}` restore branch (`QuotaRestored`, positive delta) per [`15-license-lifecycle.md`](./15-license-lifecycle.md) §Revoke.
- Ledger append-only guarantee is dual-locked: no `UPDATE`/`DELETE` GRANT to `authenticated` on `ResellerQuotaLedger`, and RLS scoped by `ResellerId = auth.reseller_id()`. Any missing ledger row on a consumed path surfaces as `QuotaLedgerConflict` (409) per [`12-error-taxonomy.md`](./12-error-taxonomy.md).
- Audit-enum parity: every ledger action (`QuotaConsumed`, `QuotaRestored`, `QuotaAdjusted`) is registered in [`28-audit-action-enum.md`](./28-audit-action-enum.md) rows 48-50 with mandatory `PayloadJson` keys.

GREEN.


## Check 24: endpoint/permission parity (Plan 05 step 38)

- Every mutating or reading row in [`10-endpoints.md`](./10-endpoints.md) declares exactly one `PermissionKey` from [`40-permissions.md`](./40-permissions.md) §2 or the literal `None`; enforced by [`../../linter-scripts/check-endpoint-permission-parity.py`](../../linter-scripts/check-endpoint-permission-parity.py) (AC-EP-006).
- [`26-route-dto-index.md`](./26-route-dto-index.md) v1.1.0 carries a matching `PermissionKey` column for every row; AC-DTO-005 pins parity with `10-endpoints.md` at the same-route level.
- `None` marker is scoped to two exemption bands only, both cited in [`10-endpoints.md`](./10-endpoints.md) §Auth (session establishment, token rotation, token revoke, OAuth handshake) and §Verification (AppBuilder OAuth scope-gated), plus `GET /Me/Roles` (self-read of caller identity). No other row may declare `None`.
- Permission-role map: [`04-roles.md`](./04-roles.md) §Permission Matrix maps every `PermissionKey` to the roles that own it via [`19-user-management.md`](./19-user-management.md) `has_permission()`; short-circuit "Admin has all" is declared in [`40-permissions.md`](./40-permissions.md) §Role defaults.

GREEN.




## Check 23: LicenseTier canonical enum + tier/quota match trigger (Plan 05 steps 21-22)

- Canonical enum `{Tier1, Tier2, Tier3, Unlimited}` is owned by [`43-license-tiers.md`](./43-license-tiers.md) §2 (v1.0.0); AC-LT-001 enforces the CHECK on `LicenseTiers.TierName`, AC-LT-005 pins ordinals 1..4 as stable.
- Forbidden synonyms (`Basic`, `Bronze`, `Starter`, `Small`, `Standard`, `Silver`, `Growth`, `Medium`, `Premium`, `Gold`, `Enterprise`, `Large`, `Pro`, `Infinite`, `NoLimit`, `Unbounded`, `Uncapped`) are linter-guarded (AC-LT-004); this file and [`43-license-tiers.md`](./43-license-tiers.md) are the two allow-listed occurrences.
- `Licenses.LicenseTierId` is NOT NULL FK per [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §`Licenses` (v0.15.0); trigger `TrgLicensesTierMatchesQuota` raises `LICENSE_TIER_QUOTA_MISMATCH` when a reseller-issued license row's tier does not match the locked `ResellerQuotas` row (AC-ADB-013). Admin-issued rows (`ResellerId = NULL`) skip the trigger but still require a non-null tier (AC-ADB-014, AC-LT-003).
- Dangling reference `TierUnauthorizedForReseller` in [`41-reseller-quotas.md`](./41-reseller-quotas.md) §3 was removed; the correct error is `QuotaCategoryUnauthorized` per [`12-error-taxonomy.md`](./12-error-taxonomy.md) §Quota row 121 (already the case in §4 and §5, now consistent in §3).
- Migration 17 (`create_licenses_table`) in [`../23-app-db/02-migration-order.md`](../23-app-db/02-migration-order.md) v1.5.0 names `LicenseTiers NOT NULL` in its FK list and cites AC-ADB-013 for the trigger; seed `S5 seed_license_tiers` inserts the four canonical rows with ordinals 1..4.

GREEN.


## Check 22: quota mutation split + ledger invariant (Plan 05 steps 19-20)

- `ResellerQuotas` `UPDATE` is split into `ResellerQuotasUpdateAllowance` (`Quotas.Approve` or `Quotas.Adjust`, `LicensesConsumed` unchanged) and `ResellerQuotasUpdateConsumed` (`Licenses.Create` on own reseller row, `LicensesGranted` unchanged); both policies are enforced together by trigger `TrgResellerQuotasAllowanceGuard` raising `RQ_UPDATE_SPLIT_VIOLATION`. AC-ADB-011 in [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) pins the four denied and two allowed cases.
- `ResellerQuotaLedger` is append-only by grant absence (no `UPDATE`/`DELETE` to `authenticated`) AND by RLS scope; AC-ADB-012 verifies both in CI and asserts the invariant `SUM(Delta) = -LicensesConsumed` per `(ResellerId, LicenseCategoryId, LicenseTierId)` after every migration and after every decrement/adjustment path exercised elsewhere in CI. Failure surfaces the exact tuple and both sides of the equation.
- Approval flow ([`42-quota-requests.md`](./42-quota-requests.md) §Approval obligations) writes `LicensesGranted` under `ResellerQuotasUpdateAllowance` and appends `QuotaAdjusted` to the ledger in the same transaction; decrement flow ([`41-reseller-quotas.md`](./41-reseller-quotas.md) §4) writes `LicensesConsumed` under `ResellerQuotasUpdateConsumed` and appends `QuotaConsumed` in the same transaction. The invariant is therefore preserved by construction and by CI assertion, not by hope.

GREEN.


## Check 21: diagram actor ordering + JSON companion (Plan 04 steps 3-16)

- Actor ordering rule declared in `spec/21-app/diagrams/00-diagram-contract.md` v1.0.0 (AC-DG-001); referenced from `.lovable/spec/commands/03-diagram-actor-ordering.md`.
- `spec/21-app/diagrams/licensing-flow.mmd` declares participants EndUser, Reseller, Admin, API, DB, Audit (canonical order); Verify is now section 1 (primary path).
- Sibling sweep: `spec/23-app-db/02-jwt-flow.mmd` (Client leftmost), `spec/23-app-db/03-oauth-client-credentials.mmd` (AppBuilder leftmost), `spec/23-app-db/09-verify-sequence.mmd` (WinApp leftmost) all comply and carry an inline compliance header citing AC-DG-001. `spec/23-app-db/01-erd.mmd` is an ER diagram, rule not applicable.
- JSON companion: `spec/21-app/diagrams/licensing-flow.json` exists, `Source` field points at the `.mmd`, `Actors[].Column` matches the participant order 1..6 (AC-DG-002).
- Verify-path drift between `licensing-flow.mmd` (GET /Serials/{SerialValue}) and the legacy verify diagrams (POST /Verify/Serial) captured in `.lovable/issues/03-verify-path-drift.md` and cross-referenced from the affected `.mmd` headers. Not a diagram-contract violation; tracked as a separate contract question.

GREEN.



## Check 20: self-update contract tightening (Plan 03 steps 21-27)

- Error-code casing: every `ErrorCode` in `17-self-update-endpoint.md` v1.2.0 is PascalCase (`UpdateChannelUnknown`, `UpdateVersionDowngradeBlocked`, `UpdateAssetNotFound`, `UpdateAssetUploadFailed`, `UpdateManifestUnavailable`, `ValidationInputInvalid`, `ValidationInvalidVersion`, `AuthzRoleDenied`) and matches the canonical list in `12-error-taxonomy.md` v1.2.0. No `SCREAMING_SNAKE_CASE` alias remains.
- MUST-abort matrix: §MUST-abort conditions enumerates A1..A10 with trigger, audit event, and retry class; AC-SU-ABORT-001/002/003 enforce the matrix at three angles (coverage, transport, signature).
- Platform enum: §Platform enum (canonical set) is the single owner for `{WindowsAmd64, LinuxAmd64, DarwinArm64}`; `../23-app-db/01-schema.md` `AppUpdateAssets.Platform` cites the same set; AC-SU-PLAT-001 pins the closed enum.
- Admin invariants: §Admin invariants declares ten rules (role gate, session integrity, actor stamping, idempotency, monotonicity, platform coverage, storage authority, rate-limit non-bypass, audit precedence, yank irreversibility); AC-SU-ADMIN-001..006 are the surface guarantees.
- Signature verification: §Signature verification pins publisher key at compile time, forbids runtime mode escalation, and wires failures to abort row A8.

GREEN.

## Check 19: envelope invariants (Plan 03 steps 11-14)

- JSON casing: `05-envelope-schema.md` v1.1.0 §JSON casing declares PascalCase across every request and response body; ACs AC-ENV-005, AC-IEH-010, AC-API-VER-005 enforce the rule at three layers (envelope, idempotency canonicalization, verify).
- Request-Id propagation: `05-envelope-schema.md` v1.1.0 §Request-Id propagation, `08-idempotency-envelope-hardening.md` v1.2.0 §Casing and correlation invariants, and `03-verification-contracts.md` v1.1.0 §Envelope invariants all bind to the same strict-list rule in `20-observability.md`; AC-ENV-006 and AC-API-VER-006 are the surface guarantees.
- Idempotency: replay/conflict responses now echo `Idempotency-Key` (AC-IEH-011), preserve the original `RequestId` under `Attributes.Idempotency.OriginalRequestId` (AC-IEH-012), and reject non-PascalCase request keys before writing a row (AC-IEH-010).
- Verify: every failure envelope for `/Verify/Serial`, `/Verify/Hash`, and `/Verify/Final` maps to exactly one `ErrorCode` and one retry class (AC-API-VER-008), and `Idempotency-Key` is ignored on verify (AC-API-VER-007) since responses carry `VerifyKey` values excluded from replay by AC-IEH-008.

GREEN.

## Check 18: single-owner vocabulary (Plan 03 step 20)

The `app_role`, `LicenseCategory`, and `LicenseVariation` enums have exactly one canonical definition each, in [`04-roles.md`](./04-roles.md), [`05-license-categories.md`](./05-license-categories.md), and [`06-license-variations.md`](./06-license-variations.md) respectively. [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md) v1.1.0 points to those leaf files without restating members. [`10-endpoints.md`](./10-endpoints.md), [`11-api-contracts/00-overview.md`](./11-api-contracts/00-overview.md), and [`12-error-taxonomy.md`](./12-error-taxonomy.md) each carry a "Vocabulary sources" stanza citing the three leaves. GREEN.

## Scope

Cross-file consistency check across `spec/21-app/` (36 files), `spec/23-app-db/` (5 files), and `spec/24-app-ui-design-system/` (referenced only). Every check below MUST evaluate green; a red result reopens the plan.

## Automated checks

1. Em-dash sweep: `rg ", " spec/21-app spec/23-app-db spec/24-app-ui-design-system` returns zero matches. GREEN.
2. AC coverage: every AC-* discovered by `rg -o "AC-[A-Z]+-[0-9]+" spec/21-app/ spec/23-app-db/ | sort -u` (190 IDs) appears in `spec/21-app/97-acceptance-criteria.md`. GREEN.
3. Error taxonomy closure: every `ErrorCode` in `12-error-taxonomy.md` is bound in `21-error-management-binding.md` with a log level. GREEN.
4. Endpoint closure: every route in `10-endpoints.md` is bound in `21-error-management-binding.md` and appears in `26-route-dto-index.md`. GREEN.
5. Audit action closure: every `Action` string in `13-audit-logging.md` and `28-audit-action-enum.md` is enumerated in the closed set at `28-audit-action-enum.md` §2. GREEN.
6. Schema-versus-ERD parity: every table in `23-app-db/01-schema.md` (22 tables) appears in `23-app-db/01-erd.mmd` and in the migration order layers L1-L7 of `23-app-db/02-migration-order.md`. GREEN.
7. Vocabulary normalization: `24-vocabulary-normalization.md` term set has zero collisions with legacy strings under `spec/21-app/` (checked via the canonical replacement table). GREEN.
8. PascalCase JSON keys: `11-api-contracts/00-overview.md` §JsonKeyCasing forbids snake_case and camelCase in envelope keys; grep for `"[a-z_]+":` inside contract examples yields zero hits. GREEN.
9. Version-drift baseline: every top-level `spec/21-app/*.md` file declares `Version: 1.x.y` and no file remains at pre-baseline versions. GREEN.
10. Retry-Rate-Limit parity: every `Retry-After` clause in `14-rate-limiting.md` has a corresponding row in `25-retry-decision-matrix.md`. GREEN.

## Manual checks

11. `18-error-management-binding.md`, `21-error-management-binding.md`, `22-log-line-contract.md`, and `23-catch-log-rethrow-patterns.md` share the same log field set (`RequestId`, `ActorUserId`, `ActorRole`, `ErrorCode`, `LogLevel`, `Message`, `Cause`). Verified by side-by-side read. GREEN.
12. Auth session family (`31-auth-session-family.md`) cites the same `AuthSessions` schema shape declared in `23-app-db/01-schema.md` v0.9.0. GREEN.
13. Salt rotation (`32-auth-session-retention.md`) cites `PiiHashSalts` schema shape in `23-app-db/01-schema.md`. GREEN.
14. Security events (`35-security-events.md`) uses only the 21-value closed EventType set and cites `SecurityEvents` table in `23-app-db/01-schema.md`. GREEN.
15. Idempotency lifecycle (`29-idempotency-lifecycle.md`) matches the `IdempotencyRecords` table shape and 24h TTL declared in `23-app-db/01-schema.md`. GREEN.
16. Self-update endpoint (`17-self-update-endpoint.md`) cites `AppUpdates` + `AppUpdateAssets` tables; DB binding is in `23-app-db/01-schema.md` v0.5.0+. GREEN.
17. Machine bindings (`30-machine-bindings.md`) declares zero raw IP/UA/MAC storage; `23-app-db/01-schema.md` `MachineBindings` uses hashed columns only. GREEN.

## Open findings

None. Plan `02-spec-21-audit-remediation` may proceed to step 47 (audit post-seal amendment) and step 50 (move to `completed/`).

## Regeneration

Re-run this report whenever any `spec/21-app/*.md` or `spec/23-app-db/*.md` file changes. Bump `Version:` above with each regeneration. If any check turns red, open a new plan under `.lovable/plans/pending/` and DO NOT edit this file to hide the finding.
