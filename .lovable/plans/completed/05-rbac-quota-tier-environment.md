# Plan 05: RBAC, Quota, Tier, Environment, Features

**Status:** completed
**Created:** 2026-07-17
**Owner:** spec/21-app + spec/23-app-db

Coherent design pass covering the six gaps identified in the RBAC/quota/tier audit:
fine-grained permissions, reseller quota economy, quota approval workflow,
license tiers, environment class, and feature flags. Each step is small enough
to execute in a single "next" turn.

---

## Layer A: Fine-grained permissions (Spatie-style)

- **Step 01** [DONE] Define `Permissions` catalog: create `spec/21-app/40-permissions.md` v1.0.0 with canonical PascalCase permission keys (`Licenses.Create`, `Licenses.Read`, `Licenses.Update`, `Licenses.Revoke`, `Serials.Issue`, `Serials.Lookup`, `Resellers.Manage`, `Prefixes.Manage`, `Users.Manage`, `Roles.Assign`, `Quotas.Approve`, `Updates.Publish`, `Audit.Read`). One row per key with description, default-role map, forbidden synonyms.
- **Step 02** [DONE] Add `Permissions`, `RolePermissions`, `UserPermissions` (override) tables to `spec/23-app-db/01-schema.md`; enforce PascalCase; add GRANTs and RLS policies; add to `spec/23-app-db/02-migration-order.md` layer.
- **Step 03** [DONE] Add `has_permission(UserId, PermissionKey)` security-definer contract to `spec/21-app/19-user-management.md`; specify short-circuit "Admin has all" rule; add caching guidance.
- **Step 04** [DONE] Extend `spec/21-app/12-error-taxonomy.md` with `AuthzPermissionDenied` (403) distinct from `AuthzRoleDenied`; wire into log-line contract; add parity test row.
- **Step 05** [DONE] Rewrite `spec/21-app/04-roles.md` §Permission Matrix as a Role -> Permission mapping (not Role -> Capability), pointing to `40-permissions.md`; add AC-PERM-001..010.
- **Step 06** [DONE] Update `spec/21-app/10-endpoints.md` and every `spec/21-app/11-api-contracts/*.md` row to cite the required `PermissionKey` per endpoint alongside the role.
- **Step 07** [DONE] Add linter `linter-scripts/check-endpoint-permission-parity.py` that asserts every endpoint row in `10-endpoints.md` names a permission defined in `40-permissions.md`.

## Layer B: Reseller quota economy

- **Step 08** [DONE] Create `spec/21-app/41-reseller-quotas.md` v1.0.0: define `ResellerQuotas(ResellerId, LicenseCategoryId, LicenseTierId, LicensesGranted, LicensesConsumed, LicensesRemaining, PeriodStart, PeriodEnd)`; define decrement semantics (transactional on `POST /Licenses`).
- **Step 09** [DONE] Add `ResellerQuotas` and `ResellerQuotaLedger` (append-only) tables to `spec/23-app-db/01-schema.md`; GRANTs + RLS scoped by `ResellerId = auth.reseller_id()`.
- **Step 10** [DONE] Extend `spec/21-app/12-error-taxonomy.md` with `QuotaExhausted`, `QuotaCategoryUnauthorized`, `QuotaLedgerConflict`; add AC rows.
- **Step 11** [DONE] Update `POST /Licenses` in `spec/21-app/11-api-contracts/02-license-contracts.md`: reseller path MUST decrement matching quota row in same transaction; `QuotaExhausted` returns 409 with `Details[]` naming category and remaining.
- **Step 12** [DONE] Add `GET /Resellers/{ResellerId}/Quotas` and `GET /Resellers/{ResellerId}/QuotaLedger` to `04-admin-contracts.md`; PascalCase, paginated per `07-admin-list-envelope-hardening.md`.
- **Step 13** [DONE] Add `QuotaConsumed`, `QuotaRestored`, `QuotaAdjusted` rows to `spec/21-app/28-audit-action-enum.md`.

## Layer C: Quota approval workflow

- **Step 14** [DONE] Create `spec/21-app/42-quota-requests.md` v1.0.0: state machine `Pending -> Approved -> Denied -> Cancelled`; approval writes ledger row and adjusts `ResellerQuotas` in one transaction.
- **Step 15** [DONE] Add `QuotaRequests` table to `spec/23-app-db/01-schema.md` with the state enum, GRANTs, RLS (reseller sees own, admin sees all).
- **Step 16** [DONE] Add `POST /Resellers/{ResellerId}/QuotaRequests`, `GET .../QuotaRequests`, `GET .../QuotaRequests/{Id}` to `05-quota-request-contracts.md`.
- **Step 17** [DONE] Add `POST /Admin/QuotaRequests/{Id}/Approve` and `.../Deny` (Admin-only, `Quotas.Approve` permission) to `05-quota-request-contracts.md`.
- **Step 18** [DONE] Extend `spec/21-app/12-error-taxonomy.md` with `QuotaRequestConflict`, `QuotaRequestNotPending`, `QuotaRequestSelfApproval`.
- **Step 19** [DONE] Add `QuotaRequestSubmitted`, `QuotaRequestApproved`, `QuotaRequestDenied` to `28-audit-action-enum.md`; add AC-QREQ-001..006 to `97-acceptance-criteria.md`.
- **Step 20** [DONE] Add Mermaid sequence `spec/21-app/diagrams/quota-approval-flow.mmd` + JSON companion, enforcing EndUser/Reseller/Admin actor ordering per `00-diagram-contract.md`.

## Layer D: License tiers

- **Step 21** [DONE] Create `spec/21-app/43-license-tiers.md` v1.0.0: canonical `LicenseTier` enum `Tier1 | Tier2 | Tier3 | Unlimited`; feature-bundle mapping (which permissions/features each tier enables); forbidden synonyms (`T1`, `Basic`, `Pro`, `Enterprise`).
- **Step 22** [DONE] Add `LicenseTiers` table + `LicenseTierId` FK on `Licenses` in `spec/23-app-db/01-schema.md`; migration order updated.
- **Step 23** Extend `spec/21-app/11-api-contracts/02-license-contracts.md` `POST /Licenses` and `PATCH /Licenses/{LicenseId}` with required `LicenseTierId`; result echoes tier.
- **Step 24** [DONE] Extend `12-error-taxonomy.md` with `ValidationInvalidTier`, `TierUnauthorizedForReseller`; add AC-TIER-001..004.
- **Step 25** [DONE] Update `spec/21-app/24-vocabulary-normalization.md` to point at `43-license-tiers.md` as single owner (single-owner check 18).

## Layer E: Environment class

- **Step 26** [DONE] Create `spec/21-app/44-license-environments.md` v1.0.0: canonical `LicenseEnvironment` enum `Development | Qa | Staging | Production`; orthogonal to `LicenseCategory` and `LicenseTier`.
- **Step 27** [DONE] Add `LicenseEnvironments` table + `LicenseEnvironmentId` FK on `Licenses`; update schema + migration order.
- **Step 28** Extend `POST /Licenses` and verify endpoints to accept/echo `LicenseEnvironmentId`; `POST /Verify/Final` response includes environment so app can gate features.
- **Step 29** [DONE] Extend `12-error-taxonomy.md` with `ValidationInvalidEnvironment`, `EnvironmentMismatch` (client environment does not match license); add AC-ENV-100..104.
- **Step 30** [DONE] Update `spec/21-app/05-license-categories.md` to note that `Dev` category is time-cadence only and does NOT imply environment; environment is a separate axis.

## Layer F: License features (feature flags)

- **Step 31** [DONE] Create `spec/21-app/45-license-features.md` v1.0.0: `LicenseFeatures(LicenseId, FeatureKey, Value)` shape; canonical FeatureKey registry; Boolean/Number/String value types; precedence rules (License override > Tier default).
- **Step 32** [DONE] Add `Features` catalog + `LicenseFeatures` and `TierFeatures` tables to schema; GRANTs + RLS; migration order updated.
- **Step 33** [DONE] Extend `POST /Verify/Final` response contract in `11-api-contracts/03-verification-contracts.md` with `Features` map (PascalCase keys, typed values); update JSON schema.
- **Step 34** [DONE] Extend `12-error-taxonomy.md` with `FeatureUnknown`, `FeatureValueInvalid`; add AC-FEAT-001..006.
- **Step 35** Add linter `linter-scripts/check-feature-registry-parity.py` asserting every `FeatureKey` referenced anywhere in `spec/21-app/` exists in `45-license-features.md`.

## Layer G: Cross-cutting consolidation

- **Step 36** [DONE] Rewrite `spec/21-app/97-acceptance-criteria.md` to absorb every new AC-* ID from steps 04..35; rerun `linter-scripts/ac-index-parity.py`.
- **Step 37** [DONE] Regenerate `spec/21-app/26-route-dto-index.md` with the new fields (`LicenseTierId`, `LicenseEnvironmentId`, `PermissionKey`, `Features`).
- **Step 38** [DONE] Update `spec/21-app/99-consistency-report.md` with Check 20 (permission parity), Check 21 (quota ledger conservation), Check 22 (tier-environment orthogonality).
- **Step 39** [DONE] Update `spec/21-app/diagrams/licensing-flow.mmd` + JSON to show quota decrement in issue path and feature payload in verify-final path.
- **Step 40** [DONE] Bump versions on every touched spec file to v1.x.0; refresh CHANGELOG and RELEASE-NOTES with a single "RBAC + Quota + Tier + Environment + Features" section citing every AC added.

---

## Runtime alignment (after spec settles)

- **Step 41** Add `PermissionKeyType` enum + `permissionKeySchema` to `src/lib/lara-user-role.ts`; regenerate parity test.
- **Step 42** Add `LicenseTierType` and `LicenseEnvironmentType` enums to `src/lib/lara-license.ts`; extend `laraLicenseSchema`.
- **Step 43** Add `resellerQuotasQueryOptions` and `quotaRequestsQueryOptions` to a new `src/lib/lara-quota.ts` with Zod schemas.
- **Step 44** Add `submitQuotaRequest`, `approveQuotaRequest`, `denyQuotaRequest` mutations with idempotency-key support.
- **Step 45** Extend `src/lib/lara-api-error.ts` `ApiErrorCodeType` with every new code from steps 04, 10, 18, 24, 29, 34; run taxonomy parity test.
- **Step 46** Build Admin UI `src/routes/_authenticated/admin.quota-requests.tsx` listing pending requests with Approve/Deny actions.
- **Step 47** Build Reseller UI `src/routes/_authenticated/reseller.quotas.tsx` showing remaining quota per (category, tier) and a "Request more" form.
- **Step 48** Extend `src/components/admin/license-issue-form.tsx` with Tier and Environment selectors; disable categories with zero remaining quota.
- **Step 49** Add Vitest coverage: quota decrement conflict, quota-exhausted UX, permission-denied fallback, feature payload parsing, environment mismatch.
- **Step 50** Final verification pass: `bunx tsgo --noEmit`, `bunx vitest run`, all linter-scripts, em-dash sweep, AC coverage report; bump project to v0.150.0; close plan 05.

---

## Success criteria

- Every endpoint in `10-endpoints.md` cites exactly one `PermissionKey` from `40-permissions.md`.
- A reseller cannot issue a license without a matching non-zero `ResellerQuotas` row; ledger sum equals `LicensesConsumed` for every (ResellerId, Category, Tier).
- `LicenseCategory`, `LicenseTier`, `LicenseEnvironment` are three orthogonal axes; no spec file conflates them.
- Every new error code has: taxonomy row + AC row + parity test row + client enum entry.
- All linter-scripts pass on the final commit.
