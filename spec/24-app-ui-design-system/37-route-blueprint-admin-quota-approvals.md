# Route Blueprint: Admin Quota Approvals (`/admin/quotas`, `/admin/quotas/:QuotaRequestId`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Fifth route blueprint; extends the template established by [`33-route-blueprint-admin-overview.md`](./33-route-blueprint-admin-overview.md), [`34-route-blueprint-admin-licenses.md`](./34-route-blueprint-admin-licenses.md), [`35-route-blueprint-admin-serials.md`](./35-route-blueprint-admin-serials.md), [`36-route-blueprint-admin-users.md`](./36-route-blueprint-admin-users.md). Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for the Admin quota-request queue and per-request decision route.
**Related:** [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`21-component-dialog.md`](./21-component-dialog.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md), [`32-command-registry.md`](./32-command-registry.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/41-reseller-quotas.md`](../21-app/41-reseller-quotas.md), [`../21-app/42-quota-requests.md`](../21-app/42-quota-requests.md), [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

`/admin/quotas` is the queue of pending reseller quota requests plus a filtered view of recent decisions. `/admin/quotas/:QuotaRequestId` is the per-request decision route (approve / deny / adjust with `Idempotency-Key`).

Out of scope: reseller-side quota request submission (owned by `39-route-blueprint-reseller-portal.md`, Step 36); quota accounting itself (owned by `spec/21-app/41-reseller-quotas.md`).

## 2. Route wiring

| Route | File | Permission | Loader |
|---|---|---|---|
| `/admin/quotas` | `src/routes/_authenticated/admin/quotas/index.tsx` | `Quotas.Approve` | `ensureQueryData(quotaRequestsListQuery(searchParams))` |
| `/admin/quotas/:QuotaRequestId` | `src/routes/_authenticated/admin/quotas/$QuotaRequestId.tsx` | `Quotas.Approve` | `ensureQueryData(quotaRequestDetailQuery({ QuotaRequestId }))` + `ensureQueryData(resellerQuotaSnapshotQuery({ ResellerId }))` + `ensureQueryData(quotaRequestAuditQuery({ QuotaRequestId }))` in parallel via `Promise.all` |

- Layout parent: `_authenticated` gate per `12-shell-layout.md`.
- Permission key: `Quotas.Approve` per `40-permissions.md` §1 (Admin-only by default).
- Permission denial: 403 terminal card per `16-route-shell-states.md` §4, NEVER a silent redirect.
- 404: `notFoundComponent` renders the 404 terminal card citing the `QuotaRequestId` via `Route.useParams()`.
- Head metadata: list `title = "Quota requests - Lara Licensing"`; detail `title = \`Quota request ${ShortId} - Lara Licensing\``; no `og:image`.
- Search params (list, `validateSearch`): `q` (string ≤ 128 chars, matches Reseller name OR short-id prefix, server-side), `Status` (enum from `42-quota-requests.md` closed set: `Pending` / `Approved` / `Denied` / `Adjusted` / `Cancelled`), `Tier` (enum from `43-license-tiers.md`), `ResellerId` (uuid), `PageIndex` (int ≥ 0, default 0), `PageSize` (closed set `{25, 50, 100}`, default 25), `Sort` (closed set `{ SubmittedAtDesc, SubmittedAtAsc, DecidedAtDesc, ResellerAsc }`, default `SubmittedAtDesc`). Default landing filter: `Status=Pending` when no `Status` param present, applied as an implicit-default URL rewrite on first visit so the URL always reflects state per `24-component-table.md` §3.

## 3. Layout

List:

```
Shell (12) > PageHeader (H1 "Quota requests", RightActions: Refresh)
  > Filter bar (SearchInput + FilterChips: Status default Pending, Tier, Reseller)
  > KPI strip (single row): Admin.PendingQuotaRequests + Approved-last-7d + Denied-last-7d per 30-kpi-and-chart-catalog.md §7
  > Table (24) with RecordCard fallback below 720 px container width
  > Pagination footer (URL-bound)
```

Detail:

```
Shell > Breadcrumb (Admin > Quota requests > <ShortId>)
  > PageHeader (H1 "<Reseller name> requests +<Delta> <Tier>", subhead: Status Badge + SubmittedAt + Reseller <Link>,
                RightActions: Approve..., Adjust..., Deny...)
  > Two-column band (container-query `>= 960 px`, single column below):
      Column A: Request card (Reseller, Tier, RequestedDelta, Justification, SubmittedAt, ExpiresAt, Notes)
                Reseller Snapshot card (CurrentQuota, Consumed, Remaining, LastDecidedAt) per 41-reseller-quotas.md
      Column B: Decision Trail Table (compact) + Audit Trail Table (compact)
```

## 4. PageHeader

- List H1: `Quota requests` (single `<h1>`).
- Detail H1: constructed as `<Reseller name> requests +<Delta> <TierLabel>` (Reseller name and Tier label are STATIC labels sourced from the request payload; runtime MUST NOT compute Delta on the client, server envelope is authority).
- Right actions (list): `Refresh` icon Button (invalidates `["Admin.Quotas.List"]` in one call).
- Right actions (detail), ordered `Approve...`, `Adjust...`, `Deny...`:
  - `Approve...`: binds to `Admin.Quotas.Approve` per `32-command-registry.md` §7. Non-destructive but MUST be confirmed via Dialog because it mutates reseller quota balance; single-Button confirmation per `21-component-dialog.md` §4 (no phrase-typing, Delta is server-visible).
  - `Adjust...`: binds to `Admin.Quotas.Adjust` per `32-` §7. Opens Dialog with an `AdjustedDelta` Field (integer, sign preserved, range clamped to `[-CurrentQuota, +MaxTierIncrement]` per `43-license-tiers.md`) and mandatory `Reason` Field (≤ 240 chars). Non-destructive but distinct from Approve.
  - `Deny...`: binds to `Admin.Quotas.Deny` per `32-` §7. Destructive per `21-component-dialog.md` §5 (the reseller loses the request and MUST resubmit); phrase-typing (user types `DENY` exactly) with mandatory `Reason` Field.
  - Actions rendered ONLY when `Status === "Pending"`. When Status is terminal (`Approved`/`Denied`/`Adjusted`/`Cancelled`), the header renders a read-only decision banner citing the decider, decided-at, and reason. Actions Menu on terminal state offers only `View reseller quota` `<Link>`.
- XS/SM collapse: right actions collapse to a MoreHorizontal Menu per `29-responsive-matrix.md`.

## 5. Table contract (list)

- Columns (pinned order): `Request` (short-id + Reseller name stacked), `Status`, `Tier`, `RequestedDelta` (signed integer, right-aligned per `24-component-table.md` §5 numeric-column rule), `SubmittedAt`, `DecidedAt`, `Actions`.
- `Request` cell is a full-cell `<Link>` to `/admin/quotas/:QuotaRequestId`; whole-row click BANNED.
- `Status`, `Tier` render as Badges with glyphs per `25-component-badge-status.md` §5; color NEVER the sole cue.
- `RequestedDelta` renders with explicit `+` or `-` prefix; zero-delta requests are invalid at submission and MUST NOT appear in the list.
- Dates render via `src/lib/format-date.ts` UTC tooltip; relative time BANNED as primary.
- `Actions` cell (Pending rows only): MoreHorizontal Menu with `View`, `Approve...`, `Adjust...`, `Deny...` (permission-hidden filter per `32-` §5). On terminal rows the Menu contains only `View`.
- Selection: none in v1 (bulk approve BANNED for audit reasons, see §12).

## 6. Filtering, search, sort

- SearchInput binds to `q`, 300 ms debounce, server-side match on Reseller name OR short-id prefix. VALUES NEVER logged.
- FilterChips are Select-backed enums per `19-component-select.md`; Status defaults to `Pending` on first visit and is preserved across pagination.
- Empty results render Empty catalog card per [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 (**Filter-reset** variant with `Clear filters`, **First-run** variant when the collection is empty for the caller scope, **Permission-scope** variant when RLS forbids the whole collection; legacy `15-empty-error-loading-catalog.md` §4 kept as pointer) with a `Clear filters` action; on the default `Pending` view when the queue is truly empty, the copy is `No pending quota requests right now.` per `27-content-voice.md` §6 empty-state voice.

## 7. Route states

Identical seven-row state table to `34-route-blueprint-admin-licenses.md` §7 (Loading skeleton, Loaded, Empty, 404 terminal, 403 terminal, Rate limited `RetryAfterBanner`, Error inline Banner with `ErrorCode`+`RequestId`+Retry calling `router.invalidate()` AND `reset()`). Partial failure on detail: if the Reseller Snapshot query fails while the Request card loads, Snapshot renders its own Banner AND a summary Toast; Approve/Adjust/Deny Buttons MUST disable with an explanatory helper text (`Reseller quota snapshot unavailable, retry before deciding.`) because deciding without a snapshot is unsafe. Fallback-to-empty is BANNED.

## 8. Data contract

- Query keys: `["Admin.Quotas.List", <serializedSearchParams>]`, `["Admin.Quotas.Detail", QuotaRequestId]`, `["Admin.Quotas.ResellerSnapshot", ResellerId]`, `["Admin.Quotas.Audit", QuotaRequestId]`. One `invalidateQueries(["Admin.Quotas"])` refreshes the whole area.
- Every decision mutation (Approve, Adjust, Deny) invalidates `["Admin.Quotas"]`, `["Admin.Overview"]` (KPI Slot 6 `Admin.PendingQuotaRequests`), AND `["Reseller.Quota"]` scoped by `ResellerId` in ONE call per mutation.
- `useSuspenseQuery` in components; loader uses `ensureQueryData`. `useQuery`+`isLoading` BANNED.
- Optimistic decision mutations BANNED (server is authority; envelope carries `EffectiveAt` and the new reseller balance).
- All mutations send `Idempotency-Key` per `29-idempotency-lifecycle.md`, generated at Dialog open per `21-component-dialog.md` §5. Concurrent double-decide on the same request MUST resolve as one apply + one `409 QuotaAlreadyDecided`, surfaced via `use-lara-error-toast.ts`.
- Concurrent-decider guard: the detail loader returns a `DecisionEtag` per `26-route-dto-index.md`; every mutation MUST send `If-Match: <DecisionEtag>`; a `412 PreconditionFailed` renders an inline Banner instructing the operator to refresh (Retry calls `router.invalidate()` AND `reset()`).

## 9. Dialogs invoked

- `Approve...` Dialog: single-Button confirmation Dialog per `21-component-dialog.md` §4; body cites Reseller name, Tier label, RequestedDelta signed integer, and NewBalance = CurrentQuota + RequestedDelta as a preview computed by the SERVER (not the client) and rendered from the loader payload. `Idempotency-Key` at Dialog open. Emits `QuotaApproveConfirmed` and `QuotaApproveExecuted`|`Failed`.
- `Adjust...` Dialog: form with `AdjustedDelta` integer Field (signed, sign selector segmented control per `20-component-choice.md`), `Reason` Field ≤ 240 chars mandatory. Server previews the new balance on blur via a debounced preview call keyed `["Admin.Quotas.AdjustPreview", QuotaRequestId, AdjustedDelta]`; preview values are NEVER treated as authoritative. Emits `QuotaAdjustConfirmed` and `QuotaAdjustExecuted`|`Failed` with `AdjustedDelta` and `Reason` fingerprint (Reason VALUE not logged; a SHA-256 8-char fingerprint IS logged per `27-content-voice.md` §9).
- `Deny...` Dialog: destructive per `21-component-dialog.md` §5, phrase-typing (`DENY`), mandatory `Reason` Field. Emits `QuotaDenyConfirmed` and `QuotaDenyExecuted`|`Failed`. Silent deny BANNED.

## 10. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link first tab stop.
- Tab order (list): Sidebar > Breadcrumb > Refresh > SearchInput > FilterChips > KPI strip (Links) > Table headers > rows > Pagination.
- Tab order (detail): Sidebar > Breadcrumb > Approve > Adjust > Deny > Request card > Snapshot card > Decision Trail > Audit Trail.
- Signed-delta cells announce sign explicitly (`plus twelve`, `minus five`) via `aria-label`; screen readers MUST NOT get raw `+12` from the visual glyph.
- Sort direction announced via `aria-sort` on the `<th>` AND a glyph.
- On terminal-status detail, the Approve/Adjust/Deny action cluster is REMOVED from the DOM (not just hidden) so it is out of tab order per `28-a11y-conformance.md` §5.

## 11. Telemetry

Per `22-log-line-contract.md`:
- `RoutePresented` with `RouteId: "Admin.Quotas.List"` or `"Admin.Quotas.Detail"`, `A11yViolations: 0`, `LoadDurationMs`.
- `TableFilterApplied` / `TableSortChanged` with field VALUES NEVER logged.
- `QuotaApprove*` / `QuotaAdjust*` / `QuotaDeny*` with `QuotaRequestId`, `ResellerId`, `Tier`, `RequestedDelta`, `AppliedDelta` (server echo), `IdempotencyKey`, `DecisionEtag`, `ErrorCode`, `RequestId`. `Reason` VALUE NEVER logged; `ReasonFingerprint` IS logged.
- Reseller name, human-readable Tier labels, and free-text Reason strings NEVER logged as values.

## 12. Anti-patterns (BANNED)

1. Client-side computation of `NewBalance` (server is authority; preview payloads render only).
2. Bulk approve / bulk deny in v1 (audit trail requires per-request decision; deferred to v2 with explicit Sheet + per-row phrase-typing).
3. Approve or Adjust rendered when `Reseller.Snapshot` query failed.
4. Optimistic decision mutations.
5. Missing `If-Match: <DecisionEtag>` on mutations.
6. Deny without phrase-typing.
7. Logging `Reason` VALUE.
8. Whole-row click on the list.
9. `useQuery`+`isLoading` initial gate.
10. Silent 403 or silent 404.
11. Rendering signed delta without explicit `+`/`-` prefix.
12. Terminal-status detail keeping Approve/Adjust/Deny in the DOM (must be removed, not just disabled).

## 13. Acceptance criteria

- AC-ROUTE-QUOTAS-001: Both routes render under `_authenticated`; permission denial renders 403 terminal card.
- AC-ROUTE-QUOTAS-002: All list state (filters default Pending, sort, pagination) round-trips through the URL.
- AC-ROUTE-QUOTAS-003: Approve, Adjust, Deny each send `Idempotency-Key` + `If-Match: <DecisionEtag>`; concurrent double-decide resolves as one apply + one `409 QuotaAlreadyDecided`.
- AC-ROUTE-QUOTAS-004: Deny routes through phrase-typing (`DENY`) with mandatory `Reason`; emits Confirmed + Executed|Failed.
- AC-ROUTE-QUOTAS-005: If `Reseller.Snapshot` fails on detail, Approve/Adjust/Deny disable with explanatory helper text.
- AC-ROUTE-QUOTAS-006: One `invalidateQueries(["Admin.Quotas"])` refreshes list, detail, snapshot, audit; `["Admin.Overview"]` and scoped `["Reseller.Quota", ResellerId]` also invalidated on decision.
- AC-ROUTE-QUOTAS-007: Signed delta cells carry explicit `aria-label` with `plus`/`minus` word forms; axe zero `serious`/`critical` at 360/768/1440.
- AC-ROUTE-QUOTAS-008: `Reason` VALUE NEVER logged; `ReasonFingerprint` (SHA-256 8-char) IS logged on every decision mutation.

## 14. Open items (for follow-up commits)

- Bulk decision Sheet deferred to v2 per §12 anti-pattern 2; requires per-row phrase-typing.
- Auto-approve rules by tier deferred to v2; when added, MUST still emit a synthetic `QuotaApproveExecuted` with `AutoApprovedBy: <RuleId>`.
