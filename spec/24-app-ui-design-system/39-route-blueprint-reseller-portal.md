# Route Blueprint: Reseller Portal (`/reseller`, `/reseller/licenses`, `/reseller/licenses/:LicenseId`, `/reseller/quota`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Seventh route blueprint; extends the template established by `33-`..`38-`. Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for the reseller-scope self-service portal (overview, license list + detail, quota request submission and history).
**Related:** [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`20-component-choice.md`](./20-component-choice.md), [`21-component-dialog.md`](./21-component-dialog.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md), [`32-command-registry.md`](./32-command-registry.md), [`34-route-blueprint-admin-licenses.md`](./34-route-blueprint-admin-licenses.md), [`37-route-blueprint-admin-quota-approvals.md`](./37-route-blueprint-admin-quota-approvals.md), [`../21-app/04-roles.md`](../21-app/04-roles.md), [`../21-app/14-rate-limiting.md`](../21-app/14-rate-limiting.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/41-reseller-quotas.md`](../21-app/41-reseller-quotas.md), [`../21-app/42-quota-requests.md`](../21-app/42-quota-requests.md), [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md), [`../21-app/44-environments.md`](../21-app/44-environments.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

Reseller-role self-service surface. Every route is row-level-security-scoped (RLS) to the caller's `ResellerId`; server MUST reject any request that names a `ResellerId` other than the caller's own with `403 ResellerScopeViolation`. UI MUST NEVER show a `ResellerId` selector.

Routes:

- `/reseller` overview (KPIs, recent activity, quota snapshot).
- `/reseller/licenses` list (RLS-scoped mirror of `34-route-blueprint-admin-licenses.md`).
- `/reseller/licenses/:LicenseId` detail (issue-only permissions; renew and revoke deferred to Admin).
- `/reseller/quota` quota snapshot + request submission + request history.

Out of scope: reseller-side approvals (Admin-only per `37-` §1); user management inside a reseller org (deferred, `48-route-blueprint-reseller-users.md` in future plan).

## 2. Route wiring

| Route | File | Permission | Loader |
|---|---|---|---|
| `/reseller` | `src/routes/_authenticated/reseller/index.tsx` | `Reseller.Portal.View` | `ensureQueryData(resellerOverviewQuery())` + `ensureQueryData(resellerQuotaSnapshotQuery())` + `ensureQueryData(resellerRecentActivityQuery())` via `Promise.all` |
| `/reseller/licenses` | `src/routes/_authenticated/reseller/licenses/index.tsx` | `Reseller.Licenses.Read` | `ensureQueryData(resellerLicensesListQuery(searchParams))` |
| `/reseller/licenses/:LicenseId` | `src/routes/_authenticated/reseller/licenses/$LicenseId.tsx` | `Reseller.Licenses.Read` | `ensureQueryData(resellerLicenseDetailQuery({ LicenseId }))` + `ensureQueryData(resellerLicenseAuditQuery({ LicenseId }))` |
| `/reseller/quota` | `src/routes/_authenticated/reseller/quota/index.tsx` | `Reseller.Quota.Request` (read + submit) | `ensureQueryData(resellerQuotaSnapshotQuery())` + `ensureQueryData(resellerQuotaRequestListQuery(searchParams))` |

- Layout parent: `_authenticated` gate per `12-shell-layout.md`.
- Permission keys per `40-permissions.md` §1. Permission denial renders 403 terminal card per `16-route-shell-states.md` §4, NEVER a silent redirect.
- Server MUST enforce RLS on every query; client MUST NOT filter on `ResellerId` (the server already does). Any query key holding a `ResellerId` value is BANNED because it invites client-side scope trust.
- 404: `notFoundComponent` on the detail route renders the 404 terminal card citing `LicenseId` via `Route.useParams()`. A License that exists but belongs to another reseller MUST return `404 LicenseNotFound` (not `403`) so the caller cannot enumerate other resellers' license IDs per `12-error-taxonomy.md` scope-hiding rule; UI renders it as a 404 terminal card.
- Head metadata: overview `title = "Reseller portal - Lara Licensing"`; licenses list `title = "Licenses - Lara Licensing"`; detail `title = \`License ${ShortId} - Lara Licensing\``; quota `title = "Quota - Lara Licensing"`; no `og:image`.
- Search params (licenses list, `validateSearch`): `q` (string ≤ 128 chars, server-side prefix match on serial short-id OR device fingerprint), `Status` (enum), `Tier` (enum from `43-license-tiers.md`), `EnvironmentId` (uuid from the reseller's own environments only), `PageIndex` (int ≥ 0), `PageSize` (closed set `{25, 50, 100}`), `Sort` (closed set default `IssuedAtDesc`).
- Search params (quota list, `validateSearch`): `Status` (enum from `42-quota-requests.md` closed set), `Tier` (enum), `PageIndex`, `PageSize`, `Sort` closed set default `SubmittedAtDesc`.

## 3. Layout

Overview:

```
Shell > PageHeader (H1 "Reseller portal", subhead: OrgName + PlanTier, RightActions: Request quota..., Issue license...)
  > KPI strip (four cards): Reseller.LicensesActive, Reseller.LicensesExpiringSoon (30 d),
                             Reseller.QuotaConsumed (with % of QuotaTotal), Reseller.QuotaRequestsPending
  > Two-column band (container-query `>= 960 px`, single column below):
      Column A: QuotaSnapshot card (Total, Consumed, Remaining, LastDecidedAt, per-Tier breakdown)
      Column B: RecentActivity Table (compact, 5 rows, "View all" <Link> to /reseller/licenses)
```

Licenses list mirrors `34-route-blueprint-admin-licenses.md` §3 with a reseller-scoped Table (no `Reseller` column because it is a constant across all rows).

Detail:

```
Shell > Breadcrumb (Reseller > Licenses > <ShortId>)
  > PageHeader (H1 "License <ShortId>", subhead: Status Badge + Tier + Environment + IssuedAt,
                RightActions: Copy install command, Rotate hash-key... (Admin-forwarded))
  > Two-column band:
      Column A: License metadata card (immutable Fields; edit BANNED at reseller scope)
                Bound serial card (redacted hash-key / verify-key per `35-route-blueprint-admin-serials.md` §8)
      Column B: Audit Trail (compact, reseller-scope subset only; Admin-only rows filtered server-side)
```

Quota:

```
Shell > PageHeader (H1 "Quota", RightActions: Request quota...)
  > QuotaSnapshot card (Total, Consumed, Remaining, LastDecidedAt, per-Tier bars)
  > Request history Table (URL-state filters: Status, Tier)
```

## 4. PageHeader actions

- `Request quota...`: opens the Request Dialog. Permission `Reseller.Quota.Request`.
- `Issue license...`: opens the Issue License Dialog. Permission `Reseller.Licenses.Issue`. Client MUST refuse to open (Button disabled with helper text `Quota exhausted for this tier, request more before issuing.`) when `QuotaSnapshot.Remaining[tier] <= 0`; server MUST re-check and respond `409 QuotaExhausted` per `29-idempotency-lifecycle.md`.
- `Copy install command` (detail): copies the server-provided install string to clipboard; the string is opaque and NEVER logged as a VALUE.
- `Rotate hash-key...` (detail): renders only when the reseller has `Reseller.Licenses.RequestRotate` permission; opens a Dialog that submits an Admin-forwarded rotation request (creates a row in the same queue as quota requests, decided by Admin). Direct rotate BANNED at reseller scope.
- Rendered actions are permission-hidden (not disabled) per `32-command-registry.md` §5.
- XS/SM collapse to MoreHorizontal Menu per `29-responsive-matrix.md`.

## 5. Table contracts

Licenses list, columns (pinned order): `Serial`, `Status`, `Tier`, `Environment`, `IssuedAt`, `ExpiresAt`, `Bindings`, `Actions`. `Serial` cell is a full-cell `<Link>`; whole-row click BANNED per `24-component-table.md` §6. `Reseller` column REMOVED (constant).

Quota request history, columns: `Request` (short-id + SubmittedAt stacked), `Status`, `Tier`, `RequestedDelta` (signed integer, right-aligned with mandatory `+`/`-` prefix and `aria-label` `plus`/`minus` word forms per `37-` §5), `AppliedDelta` (server echo, `-` when Pending or Denied), `DecidedAt`, `Actions`. No full-cell `<Link>` (there is no per-request detail route at reseller scope; drill-down deferred). Actions Menu: `Cancel...` renders only when `Status === "Pending"` and permission `Reseller.Quota.Cancel` present.

## 6. Filtering, search, sort

- SearchInput binds `q`, 300 ms debounce, server-side. Serial VALUES NEVER logged.
- `EnvironmentId` select shows ONLY environments the reseller owns (server-scoped). A URL-supplied `EnvironmentId` outside that scope MUST be rejected by `validateSearch` client-side (stripped and toast informs `Environment not available in your scope.`) AND rejected server-side.
- Empty results render [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 **First-run** variant when the reseller has no licenses at all (per F28) with the primary CTA `Issue license...` gated on `Licenses.Create`; **Filter-reset** variant with a `Clear filters` action when filters produced zero rows; **Permission-scope** variant when RLS forbids the whole collection. KPI skeletons are Mode B (List) per [`54-loading-state-catalog.md`](./54-loading-state-catalog.md) §2; the outer Sidebar + App bar is Mode A (Route-shell). Legacy pointer to `15-empty-error-loading-catalog.md` §4 retained.

## 7. Route states

Identical seven-row state table to `34-` §7. Partial failure on Overview: if `resellerQuotaSnapshotQuery` fails, KPI Cards 3 and 4 render Skeleton-error state (small inline Banner per `23-component-toast-banner.md` §4) and `Request quota...` / `Issue license...` Buttons DISABLE with helper text `Quota snapshot unavailable, retry before submitting.`; fallback-to-zero on KPI values BANNED because `0` cannot be distinguished from `unknown`.

## 8. Data contract

- Query keys: `["Reseller.Overview"]`, `["Reseller.Quota.Snapshot"]`, `["Reseller.Activity"]`, `["Reseller.Licenses.List", <serializedSearchParams>]`, `["Reseller.Licenses.Detail", LicenseId]`, `["Reseller.Licenses.Audit", LicenseId]`, `["Reseller.Quota.RequestList", <serializedSearchParams>]`. NONE of these keys carry a `ResellerId` value (server is authority).
- Every quota-request mutation invalidates `["Reseller.Quota"]` (prefix) AND `["Reseller.Overview"]` in one call.
- License Issue mutation invalidates `["Reseller.Licenses"]` (prefix) AND `["Reseller.Quota.Snapshot"]` AND `["Reseller.Overview"]` in one call.
- `useSuspenseQuery` + `ensureQueryData`; `useQuery`+`isLoading` BANNED.
- Optimistic mutations BANNED.
- All mutations send `Idempotency-Key` per `29-idempotency-lifecycle.md`, generated at Dialog open per `21-component-dialog.md` §5.
- Rate-limit surface per `14-rate-limiting.md`: reseller-scope routes SHARE a bucket per caller; `429` responses render `RetryAfterBanner` per `23-component-toast-banner.md` §5 and DISABLE the invoking Button while the countdown runs.

## 9. Dialogs invoked

- `Request quota...` Dialog: form with `Tier` Select (closed set from `43-license-tiers.md`, no free-text), `RequestedDelta` positive integer Field (range clamped to `[1, MaxTierIncrement]` per `43-` and `41-reseller-quotas.md`), mandatory `Justification` Field ≤ 240 chars. Non-destructive. `Idempotency-Key` at Dialog open. Emits `QuotaRequestConfirmed` and `QuotaRequestExecuted`|`Failed`. `Justification` VALUE NEVER logged (SHA-256 8-char `JustificationFingerprint` IS logged per `27-` §9).
- `Issue license...` Dialog: form with `Tier` Select, `EnvironmentId` Select (own environments only), `LicenseCategoryId`, `LicenseVariationId`, `BindingSpec` per `07-serial-generation.md`. Server pre-flight-checks quota before committing; the Dialog MUST render a pre-flight preview (server-side `["Reseller.Licenses.IssuePreview", ...]` query keyed by the Dialog Fields) but the preview is display-only. Emits `LicenseIssueConfirmed` and `LicenseIssueExecuted`|`Failed`.
- `Cancel request...` Dialog (quota history): destructive per `21-component-dialog.md` §5 phrase-typing (`CANCEL`) with mandatory `Reason`. Emits `QuotaCancelConfirmed` and `QuotaCancelExecuted`|`Failed`.
- `Rotate hash-key...` Dialog (detail): forwards to Admin queue (see §4).

## 10. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link first tab stop.
- Tab order (overview): Sidebar > Breadcrumb > Request quota > Issue license > KPI Links > QuotaSnapshot Field-set > RecentActivity table.
- Signed-delta cells announce `plus`/`minus` word forms via `aria-label` per `37-` §10.
- Rate-limit `RetryAfterBanner` announces countdown via `aria-live="polite"` per `23-` §5.

## 11. Telemetry

Per `22-log-line-contract.md`:
- `RoutePresented` with `RouteId: "Reseller.Overview" | "Reseller.Licenses.List" | "Reseller.Licenses.Detail" | "Reseller.Quota"`, `A11yViolations: 0`, `LoadDurationMs`. `ResellerId` from the session, NEVER from URL.
- `QuotaRequest*` / `LicenseIssue*` / `QuotaCancel*` with `Tier`, `RequestedDelta` or `AppliedDelta`, `IdempotencyKey`, `ErrorCode`, `RequestId`, and `JustificationFingerprint` (never VALUE).
- Serial VALUES, hash-key / verify-key VALUES, install-command VALUES, and Justification / Reason VALUES NEVER logged.

## 12. Anti-patterns (BANNED)

1. Any UI element or query key carrying a `ResellerId` value at reseller scope.
2. Client-side filtering by `ResellerId` (server RLS is authority).
3. Rendering `403` for a cross-reseller license lookup (server returns `404 LicenseNotFound` to prevent enumeration; UI matches).
4. Enabling `Issue license...` when `QuotaSnapshot.Remaining[tier] <= 0`.
5. Fallback-to-zero on KPI values when the snapshot query has failed.
6. Direct hash-key rotation at reseller scope (must forward to Admin).
7. Optimistic mutations of any kind.
8. Missing `Idempotency-Key` on Request / Issue / Cancel.
9. Free-text `Tier` Field (must be closed-set Select).
10. `useQuery`+`isLoading` initial gate.
11. Silent 403 or silent 404.
12. Logging Justification / Reason / Serial / hash-key / verify-key VALUES.

## 13. Acceptance criteria

- AC-ROUTE-RESELLER-001: All four routes render under `_authenticated`; permission denial renders 403 terminal card.
- AC-ROUTE-RESELLER-002: Cross-reseller License lookup returns `404 LicenseNotFound` and UI renders the 404 terminal card (never 403).
- AC-ROUTE-RESELLER-003: `Issue license...` disables with helper text when `QuotaSnapshot.Remaining[tier] <= 0`; server re-checks and returns `409 QuotaExhausted` if bypassed.
- AC-ROUTE-RESELLER-004: All quota-affecting mutations send `Idempotency-Key`; concurrent submits resolve as one apply + one `409` variant surfaced via `use-lara-error-toast.ts`.
- AC-ROUTE-RESELLER-005: When `resellerQuotaSnapshotQuery` fails, Request quota + Issue license Buttons disable with explanatory helper text; KPI Cards render Skeleton-error not `0`.
- AC-ROUTE-RESELLER-006: One `invalidateQueries(["Reseller.Quota"])` refreshes overview KPIs, snapshot card, and request history.
- AC-ROUTE-RESELLER-007: Axe zero `serious`/`critical` at 360/768/1440; signed-delta `aria-label`s carry `plus`/`minus` word forms.
- AC-ROUTE-RESELLER-008: No query key, log line, or URL param carries a `ResellerId` VALUE at reseller scope.

## 14. Open items (for follow-up commits)

- Reseller-scope user management deferred to a future plan (`48-route-blueprint-reseller-users.md`).
- Per-request detail route at reseller scope deferred; §5 quota history lacks full-cell `<Link>` intentionally until then.
- CSV export of License list deferred to v2 with mandatory `Idempotency-Key` on the export request.
