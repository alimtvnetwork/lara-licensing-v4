# Route Blueprint: Admin Overview (`/admin`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. First route blueprint; template for every subsequent route blueprint in the `33-`..`39-` range.
**Owner:** Single normative source for the Admin landing route (`/admin`). Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`12-shell-layout.md`](./12-shell-layout.md), [`13-navigation-ia.md`](./13-navigation-ia.md), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md), [`31-search-and-command-palette.md`](./31-search-and-command-palette.md), [`32-command-registry.md`](./32-command-registry.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

`/admin` is the landing route for Admin-role callers after sign-in. Purpose: at-a-glance operational state of the licensing platform, plus one click to the most likely next action.

`/admin` is NOT: a Table page (Licenses/Serials/Users own theirs), a chart-heavy analytics page (v1 has no time-series depth beyond `30-kpi-and-chart-catalog.md` §7 registry), or a settings page.

## 2. Route wiring

- Path: `/admin` (no trailing slash per TanStack Router).
- File: `src/routes/_authenticated/admin/index.tsx` (route file to be created; this blueprint owns the shape).
- Layout parent: `_authenticated` layout gate per shell rules; unauthenticated access redirects to `/auth` before this route's loader runs.
- Permission: `Admin.Overview.Read` per [`../21-app/40-permissions.md`](../21-app/40-permissions.md). Missing permission renders the 403 terminal card per [`16-route-shell-states.md`](./16-route-shell-states.md) §4, NOT a silent redirect.
- Loader: `context.queryClient.ensureQueryData(<each KPI queryOption>)` for the six KPIs in §4; loader runs in parallel via `Promise.all`. The loader MUST NOT call any protected server function that could 401 on prerender; this route is under `_authenticated` so the bearer token is present.
- Head metadata: `title = "Admin overview - Lara Licensing"`, `description = "Operational state of licenses, serials, users, and rate limits."`. No `og:image` on this route (internal, non-shareable).

## 3. Layout

```
+------------------- App Shell (12-shell-layout) --------------------+
|  Sidebar  |  TopBar (breadcrumb: Admin > Overview, right: Palette) |
|           +----------------------------------------------------+   |
|           |  PageHeader (14-breadcrumbs-and-page-header)       |   |
|           |    H1 "Overview"    RightActions: Refresh, Issue... |   |
|           +----------------------------------------------------+   |
|           |  KPI Grid (KPI cards, per 30-kpi-and-chart-catalog) |   |
|           |    +------+  +------+  +------+                    |   |
|           |    | KPI1 |  | KPI2 |  | KPI3 |                    |   |
|           |    +------+  +------+  +------+                    |   |
|           |    | KPI4 |  | KPI5 |  | KPI6 |                    |   |
|           |    +------+  +------+  +------+                    |   |
|           +----------------------------------------------------+   |
|           |  Attention Panel (near-expiry + 429 spikes)         |   |
|           +----------------------------------------------------+   |
|           |  Recent Activity Table (compact, last 10 rows)     |   |
|           +----------------------------------------------------+   |
+--------------------------------------------------------------------+
```

Responsive per [`29-responsive-matrix.md`](./29-responsive-matrix.md):
- XS (360) / SM (390): single column, KPI cards stack, Sidebar off-canvas.
- MD (768): two-column KPI grid, Attention Panel + Recent Activity stack.
- LG (1024): three-column KPI grid, Attention Panel and Recent Activity side-by-side above 1280 px container width, stacked below.
- XL (1440) / 2XL (1920): three-column KPI grid, side-by-side attention + activity, Sidebar expanded to 240 px.

Grid uses CSS container queries anchored to `main`, NOT viewport media queries, per [`29-responsive-matrix.md`](./29-responsive-matrix.md) §3.

## 4. KPI slots

Six KPI cards, in this fixed order. Each row cites a `KpiKey` from [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §7. Reordering requires a same-commit registry update.

| Slot | KpiKey | Icon | Group heading (SR-only) |
|------|--------|------|-------------------------|
| 1 | `Admin.ActiveLicenses` | `KeyRound` | Licenses |
| 2 | `Admin.NearExpiryLicenses` | `Clock` | Licenses |
| 3 | `Admin.RevokedLicenses` | `Ban` | Licenses |
| 4 | `Admin.SerialsBound` | `Barcode` | Serials |
| 5 | `Admin.RateLimit429s` | `ShieldAlert` | Abuse |
| 6 | `Admin.PendingQuotaRequests` | `Gauge` | Quota (aggregate across all resellers; MUST be added to `30-kpi-and-chart-catalog.md` §7 in the same commit) |

Each card follows the KPI card contract in [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §2:
- Label + Value + optional DeltaChip paired with Sparkline.
- Loading -> skeleton with `aria-busy="true"` (Mode B (List) per [`54-loading-state-catalog.md`](./54-loading-state-catalog.md) §2; outer chrome is Mode A route-shell).
- Empty -> real `0` (never `--`).
- Unavailable -> `--` with reason footnote, NEVER silent `0`.
- Error -> inline Banner with `ErrorCode` + `RequestId`.
- 429 -> `RetryAfterBanner`.

Clicking a KPI card navigates to the linked list route (Slot 1 -> `/admin/licenses?state=Active`, Slot 2 -> `/admin/licenses?nearExpiry=7d`, etc.); the card MUST be wrapped in a full-area `<Link>` per [`17-component-button.md`](./17-component-button.md) §4 with the visible label doubling as the accessible name.

## 5. PageHeader

- H1: `"Overview"`. Single `<h1>` per [`28-a11y-conformance.md`](./28-a11y-conformance.md) §5.
- Breadcrumb per [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md): `Home > Admin > Overview`. `Home` links to caller's role-scoped root.
- Right actions (in order): `Refresh` (icon-only Button with `aria-label="Refresh overview"`, disabled while any KPI query is fetching, motion per [`26-iconography-and-assets.md`](./26-iconography-and-assets.md) §6), `Issue license...` (primary Button triggering `Admin.Licenses.Issue` per [`32-command-registry.md`](./32-command-registry.md) §7).
- Refresh triggers `queryClient.invalidateQueries({ queryKey: ["Admin.Overview"] })` (route-level, NOT per-card per [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §8).
- Right actions collapse into a `MoreHorizontal` Menu at XS/SM per [`29-responsive-matrix.md`](./29-responsive-matrix.md) §5; Refresh remains outside the Menu (primary utility).

## 6. Attention Panel

A card-styled `<section aria-labelledby="attention-heading">` with H2 `"Needs attention"`. Contents (in order, hidden when empty):

1. **Expiring within 7 days**: count from `Admin.NearExpiryLicenses` KPI plus a `View` Link to `/admin/licenses?nearExpiry=7d`. Rendered ONLY when count > 0.
2. **Rate-limit spikes in last 24 h**: count from `Admin.RateLimit429s` plus a `View` Link to `/admin/abuse`. Rendered ONLY when count > `Admin.RateLimit429s` noise floor per [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §7.
3. **Pending quota requests**: count from Slot 6 plus a `View` Link to `/admin/quotas`. Rendered ONLY when count > 0.

Empty Attention Panel: render `"Nothing needs attention right now."` in body voice per [`27-content-voice.md`](./27-content-voice.md) §3, do NOT hide the panel entirely (a hidden panel confuses "is the check running or is everything fine").

## 7. Recent Activity Table

Compact Table per [`24-component-table.md`](./24-component-table.md) rendering the last 10 `AuditLogs` rows the caller can read (permission `AuditEvents.Read`).

Columns (fixed for this compact view):

| Column | Data | Renderer |
|--------|------|----------|
| When | `AuditLogs.CreatedAt` | ISO-8601 UTC, `Z` suffix, relative-time badge in tooltip |
| Who | `AuditLogs.ActorUserId` -> User `DisplayName` | Link to `/admin/users/$userId` |
| Action | `AuditLogs.Action` enum | Badge per [`25-component-badge-status.md`](./25-component-badge-status.md) §4 |
| Subject | `AuditLogs.SubjectType` + `SubjectId` | Link when a route exists |
| Result | `AuditLogs.Result` (`Success` / `Failed`) | Badge |

- Toolbar: no SearchInput, no FilterChip (compact view). One trailing `View all` Link to `/admin/audit`.
- URL binding: NONE for this compact Table (the route is landing, not a Table page; sorting/filtering lives on `/admin/audit`).
- Refresh: piggybacks on PageHeader Refresh (`Admin.Overview` query key includes the audit page 1).
- Empty state: `"No recent activity yet."` per [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 variant **First-run** (no filters on this compact Table); [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3 legacy pointer retained for continuity.
- Below 720 px container width: RecordCard fallback per [`24-component-table.md`](./24-component-table.md) §11.

## 8. States

Per [`16-route-shell-states.md`](./16-route-shell-states.md) and [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md):

| State | Trigger | Render |
|-------|---------|--------|
| Loading | Any KPI query pending | KPI cards render skeletons (Mode B (List) per [`54-loading-state-catalog.md`](./54-loading-state-catalog.md) §2); Attention Panel and Recent Activity render Mode B skeletons; PageHeader Refresh is disabled and rotates. Route-shell (Mode A) covers the surrounding Sidebar and App bar per `54-` §2. |
| Loaded | All KPI queries resolved | Live cards + Attention Panel + Recent Activity per §4-§7. |
| Partial failure | Some KPIs failed, others succeeded | Failed KPI cards render inline Banner with `ErrorCode` + `RequestId`; the rest render normally; PageHeader shows a Toast summarizing failure count per [`23-component-toast-banner.md`](./23-component-toast-banner.md). |
| Total failure | All KPI queries failed | Terminal 500 card per [`16-route-shell-states.md`](./16-route-shell-states.md) §4 with retry Button invalidating the route query group. |
| Forbidden | Caller lacks `Admin.Overview.Read` | Terminal 403 card per [`16-route-shell-states.md`](./16-route-shell-states.md) §4; NOT a silent redirect. |
| Rate-limited | Any KPI returned 429 | Card-level `RetryAfterBanner` on the affected KPI(s); other KPIs unaffected. |

Partial failure MUST NOT fall back to `0` for the failed card (that would misinform); MUST render the Banner per [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §2.4.

## 9. Accessibility

Per [`28-a11y-conformance.md`](./28-a11y-conformance.md):

- Single `<h1>` = `"Overview"`. KPI Slots' group headings render as visually-hidden `<h2>` for landmark structure.
- `<main>` landmark contains PageHeader, KPI grid, Attention Panel, Recent Activity.
- Skip-link to `<main>` is the first tab stop (owned by `_authenticated` layout).
- Tab order: Sidebar (if focused) -> Breadcrumb -> Refresh Button -> Issue license Button -> KPI card 1 Link -> ... -> KPI card 6 Link -> Attention Panel Links (top-to-bottom) -> Recent Activity Table (Toolbar first, then rows).
- Focus ring per [`28-a11y-conformance.md`](./28-a11y-conformance.md) §3.2; KPI card Link focus MUST reveal the full card outline (not just the H2 text).
- Refresh Button spinner uses `RefreshCw` motion per [`26-iconography-and-assets.md`](./26-iconography-and-assets.md) §6 and is disabled under `prefers-reduced-motion: reduce`.
- No color-only status conveyance; every Badge includes a glyph per [`25-component-badge-status.md`](./25-component-badge-status.md) §3.

## 10. Data contract

All six KPI queries plus the Recent Activity query bind to server functions with the operationIds registered in [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md). Placeholder mapping (parity checked by future linter):

| Slot / section | operationId |
|----------------|-------------|
| KPI 1 | `Admin.Metrics.Licenses.Active` |
| KPI 2 | `Admin.Metrics.Licenses.NearExpiry` |
| KPI 3 | `Admin.Metrics.Licenses.Revoked` |
| KPI 4 | `Admin.Metrics.Serials.Bound` |
| KPI 5 | `Admin.Metrics.RateLimit.Events` |
| KPI 6 | `Admin.Metrics.QuotaRequests.Pending` |
| Recent Activity | `Admin.Audit.List` with `Page=1&PageSize=10` |

Each query key: `["Admin.Overview", <slot>, <window>]`. `queryClient.invalidateQueries({ queryKey: ["Admin.Overview"] })` refetches the whole route in one call.

`useSuspenseQuery` in the component; loader `ensureQueryData` in parallel. `useQuery` + `isLoading` for initial render is BANNED per TanStack default read shape.

## 11. Telemetry

Emit via `logger.info` per [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md):

- `RoutePresented` at first successful render: `Route: "/admin"`, `A11yViolations: <axe-core count>` (0 in v1), `RequestId`, `DurationMs`.
- One `KpiPresented` per successful KPI resolution per [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §11.
- `RouteRefreshInvoked` on Refresh click: `Route`, `TriggerKind` (`Button` / `KeyboardShortcut`), `RequestId`.
- `RouteAttentionPanelPresented`: `ExpiringCount`, `RateLimitCount`, `QuotaCount`.

No field VALUES (license identifiers, user emails, serial values) are logged from this route.

## 12. Anti-patterns

1. Rendering `0` on a failed KPI query instead of the inline Banner.
2. Silent redirect to `/auth` on `Admin.Overview.Read` denial (must be 403 terminal card).
3. Per-card refresh Buttons (violates [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §8).
4. Hiding the Attention Panel when empty (removes evidence the check ran).
5. Adding URL params on the Recent Activity Table (out of scope for the compact view).
6. Loading KPIs sequentially (defeats the parallel `Promise.all`).
7. Reordering §4 KPI Slots without a registry update.
8. `og:image` on this internal route.
9. Wrapping the whole KPI card in a Button (must be a Link per [`17-component-button.md`](./17-component-button.md) §4).
10. Optimistic KPI updates after `Admin.Licenses.Issue` (KPI query invalidates on mutation settle).
11. Viewport-media-query layout for the KPI grid (must use container queries).
12. Fetching Recent Activity from a separate route-owned loader (belongs to `Admin.Overview` query group).

## 13. Acceptance criteria

- AC-ROUTE-ADMIN-001: `/admin` renders the six KPI Slots in §4 order at every breakpoint in [`29-responsive-matrix.md`](./29-responsive-matrix.md).
- AC-ROUTE-ADMIN-002: Loader `ensureQueryData` runs all six KPI queries in parallel and completes before the component mounts; verified by future `tests/route-admin-overview.test.tsx` measuring loader duration >= max single KPI duration and << sum.
- AC-ROUTE-ADMIN-003: `Admin.Overview.Read` denial renders the 403 terminal card; NEVER a silent redirect.
- AC-ROUTE-ADMIN-004: Partial KPI failure renders per-card Banner AND a Toast; other KPIs render live values; verified by future test.
- AC-ROUTE-ADMIN-005: PageHeader Refresh invalidates the whole `Admin.Overview` query group in one call; per-card refresh is absent.
- AC-ROUTE-ADMIN-006: `RoutePresented` fires exactly once per successful mount with `A11yViolations: 0` (axe-core clean).
- AC-ROUTE-ADMIN-007: Recent Activity Table below 720 px container width promotes to RecordCard.
- AC-ROUTE-ADMIN-008: Every route-scoped log line respects the no-field-VALUE rule in §11.
- AC-ROUTE-ADMIN-009: Adding, removing, or reordering KPI Slots without a same-commit update to [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §7 fails CI (parity linter, future).

## 14. Verification

- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- `python3 linter-scripts/check-spec-cross-links.py` exits 0 (this file's own links).
- Manual: §4 registry rows (KPI 6 `Admin.PendingQuotaRequests`) MUST be reflected in [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §7 within the SAME commit; open item noted below.

## 15. Open items (in-commit follow-ups)

- Slot 6 (`Admin.PendingQuotaRequests`) is a new KPI introduced by this blueprint. It MUST be added to [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md) §7 registry in the SAME commit or the parity check in §14 fails. Deferred to Step 30 (route blueprint `/admin/licenses`) if that step consolidates KPI-registry additions across all Admin routes.
