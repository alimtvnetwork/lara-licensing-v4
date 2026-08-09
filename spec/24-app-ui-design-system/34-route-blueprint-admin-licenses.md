# Route Blueprint: Admin Licenses (`/admin/licenses`, `/admin/licenses/:LicenseId`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Second route blueprint; extends the template established by [`33-route-blueprint-admin-overview.md`](./33-route-blueprint-admin-overview.md). Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for the Admin Licenses list and detail routes.
**Related:** [`13-navigation-ia.md`](./13-navigation-ia.md), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`21-component-dialog.md`](./21-component-dialog.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`32-command-registry.md`](./32-command-registry.md), [`../21-app/02-license-contracts.md`](../21-app/02-license-contracts.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md), [`../21-app/44-environments.md`](../21-app/44-environments.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

`/admin/licenses` is the primary operational Table for Licenses (list, filter, deep-link) and `/admin/licenses/:LicenseId` is the canonical detail route (view, renew, revoke, inspect serials + audit). This blueprint owns both.

Out of scope: reseller quota approvals (own route), serials issuance dialogs called from detail (own the dialog contract in `21-component-dialog.md` §5, this file only pins invocation).

## 2. Route wiring

| Route | File | Permission | Loader |
|---|---|---|---|
| `/admin/licenses` | `src/routes/_authenticated/admin/licenses/index.tsx` | `Admin.Licenses.Read` | `ensureQueryData(licensesListQuery(searchParams))` |
| `/admin/licenses/:LicenseId` | `src/routes/_authenticated/admin/licenses/$LicenseId.tsx` | `Admin.Licenses.Read` | `ensureQueryData(licenseDetailQuery({ LicenseId }))` + `ensureQueryData(licenseAuditQuery({ LicenseId }))` in parallel via `Promise.all` |

- Layout parent: `_authenticated` gate per `12-shell-layout.md`; no unauthenticated preview.
- Permission denial: renders the 403 terminal card per [`16-route-shell-states.md`](./16-route-shell-states.md) §4, NEVER a silent redirect.
- 404: `notFoundComponent` renders the 404 terminal card citing the `LicenseId` in the copy per `27-content-voice.md` §5 error triad; `Route.useParams()` is the only source for that value.
- Head metadata: list route `title = "Licenses - Lara Licensing"`; detail route derives from loader (`title = \`License ${SerialPrefix}-${SerialTail} - Lara Licensing\``); NEITHER route sets `og:image` (internal).
- Search params (list only, bound via TanStack Router `validateSearch`): `q` (string, `≤ 128` chars), `Status` (enum from `25-component-badge-status.md` §5), `Tier` (enum from `43-license-tiers.md`), `EnvironmentId` (uuid), `ResellerId` (uuid), `PageIndex` (int `≥ 0`, default 0), `PageSize` (int, closed set `{25, 50, 100}`, default 25), `Sort` (enum from §6). URL is the single source of truth per `24-component-table.md` §3; component-local pagination state BANNED.

## 3. Layout (list)

```
Shell (12) > PageHeader (H1 "Licenses", RightActions: Refresh, Issue license...)
  > Filter bar (SearchInput + FilterChips: Status, Tier, Environment, Reseller)
  > Table (24) with RecordCard fallback below 720 px container width
  > Pagination footer (URL-bound PageIndex/PageSize)
```

Detail route layout:

```
Shell > Breadcrumb (Admin > Licenses > <Serial>)
  > PageHeader (H1 "<Serial>", subhead: Status Badge + Tier Badge + Environment Badge, RightActions: Renew..., Revoke...)
  > Two-column section band (container-query `>= 960 px`, single column below):
      Column A: Identity card (Reseller, Prefix, Product, Category, Tier, Environment, IssuedAt, ExpiresAt, Notes)
      Column B: Serials Table (compact) + Audit Trail Table (compact)
```

## 4. PageHeader (both routes)

- List H1: `Licenses` (single `<h1>` per `28-a11y-conformance.md` §5).
- Detail H1: canonical serial string exactly as returned by the API (`<Prefix>-<Tail>` per `02-license-contracts.md`); MUST NOT be lowercased or truncated.
- Right actions (list): `Refresh` icon Button (invalidates `["Admin.Licenses.List"]` in one call, disabled under `prefers-reduced-motion` motion), `Issue license...` primary Button binding to `Admin.Licenses.Issue` command per `32-command-registry.md` §7 (opens the issue Dialog, NEVER navigates).
- Right actions (detail): `Renew...` secondary Button (opens Renew Dialog), `Revoke...` destructive Button binding to `Admin.Licenses.Revoke` command per `32-command-registry.md` §7 (MUST route through phrase-typing Dialog per `21-component-dialog.md` §5, `Idempotency-Key` bound `OnDialogOpen`).
- XS/SM collapse: right actions collapse to a MoreHorizontal Menu per `29-responsive-matrix.md`.

## 5. Table contract (list)

- Columns (in order, pinned): `Serial`, `Status`, `Tier`, `Environment`, `Reseller`, `Product`, `IssuedAt`, `ExpiresAt`, `Actions`.
- `Serial` cell is a full-cell `<Link>` to `/admin/licenses/:LicenseId`, NEVER a Button; the whole row is not clickable per `24-component-table.md` §6.
- `Status`, `Tier`, `Environment` render as Badges using the tone map in `25-component-badge-status.md` §5; color is NEVER the sole cue, glyph mandatory.
- `IssuedAt`, `ExpiresAt` render via `src/lib/format-date.ts` (UTC ISO tooltip on hover per `27-content-voice.md` §7); relative time BANNED as the primary rendering.
- `Actions` cell renders a MoreHorizontal Menu with `View`, `Renew...`, `Revoke...` (destructive rows filtered by permission per `32-command-registry.md` §5; missing permission HIDDEN not disabled).
- Selection: none in v1 (bulk actions deferred). Row-level `Actions` only.

## 6. Filtering, search, sort

- SearchInput binds to `q`, 300 ms debounce per `18-component-input.md` §7 matching `24-component-table.md` §3; polite live-region announces result count on settle NEVER per keystroke.
- FilterChips are Select-backed enums (`19-component-select.md`), each binding to one search param. Clear-all chip clears every filter param but PRESERVES `PageSize` and `Sort`.
- Sort: closed set `{ IssuedAtDesc, IssuedAtAsc, ExpiresAtAsc, ExpiresAtDesc, StatusAsc }`; default `IssuedAtDesc`. Column-header click toggles between two sorts per column and updates `Sort` in the URL.
- Empty search results render the Empty catalog card per [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 (**Filter-reset** variant with `Clear filters`, **First-run** variant when the collection is empty for the caller scope, **Permission-scope** variant when RLS forbids the whole collection; legacy `15-empty-error-loading-catalog.md` §4 kept as pointer) with a `Clear filters` action, NEVER a blank Table body.

## 7. Route states (list AND detail)

| State | Rendering | Source |
|---|---|---|
| Loading | Skeleton Table (list, Mode B) or Skeleton section band (detail, Mode A) per [`54-loading-state-catalog.md`](./54-loading-state-catalog.md) §2 | Loader in flight |
| Loaded | Table / section band | 200 OK |
| Empty (list) | Empty card citing active filters | 200 OK, 0 rows |
| Not found (detail) | 404 terminal card | 404 |
| Forbidden | 403 terminal card | 403 |
| Rate limited | Inline `RetryAfterBanner` above the Table / band per `23-component-toast-banner.md` §6 | 429 with `Retry-After` |
| Error | Inline Banner citing `ErrorCode` + `RequestId` + Retry action (Retry runs `router.invalidate()` AND boundary `reset()` per errorComponent rules) | 5xx |

Partial failure on detail (audit query fails but detail succeeds): detail band renders; Audit sub-table renders its own per-section error Banner AND a summary Toast per `33-route-blueprint-admin-overview.md` §7 partial-failure contract; fallback-to-empty is BANNED.

## 8. Data contract

- Query keys: `["Admin.Licenses.List", <serializedSearchParams>]`, `["Admin.Licenses.Detail", LicenseId]`, `["Admin.Licenses.Audit", LicenseId]`. One `invalidateQueries(["Admin.Licenses"])` call refreshes the whole area per `30-kpi-and-chart-catalog.md` §8.
- Fetch shape: `useSuspenseQuery(queryOptions)` in components; loaders use `ensureQueryData`. `useQuery` + `isLoading` BANNED per `33-route-blueprint-admin-overview.md` §11.
- Mutations (`Issue`, `Renew`, `Revoke`) invalidate `["Admin.Licenses"]` AND `["Admin.Overview"]` (KPI cards) in ONE call per mutation. Optimistic updates BANNED (server is authority; envelope may include `EffectiveAt`).
- All mutations send `Idempotency-Key` per `02-license-contracts.md` §4; key generated at Dialog open per `21-component-dialog.md` §5.
- Server functions: `getLicensesList`, `getLicenseDetail`, `getLicenseAudit`, `issueLicense`, `renewLicense`, `revokeLicense` per `26-route-dto-index.md`. All are `requireSupabaseAuth`-protected and called via `useServerFn` per protected-server-functions rule.

## 9. Dialogs invoked

- `Issue license...` Dialog: form per `18-component-input.md` + `19-component-select.md`; fields = `Reseller`, `Prefix`, `Product`, `Category`, `Tier`, `Environment`, `ExpiresAt`, `Notes`. Closed-set fields use Select bound to Zod-validated enums per `19-component-select.md` §6.
- `Renew...` Dialog: single `ExpiresAt` Field with pre-fill from detail; NON-destructive; `Idempotency-Key` bound on Dialog open.
- `Revoke...` Dialog: destructive per `21-component-dialog.md` §5 phrase-typing (user types the serial exactly); BOTH `LicenseRevokeConfirmed` and `LicenseRevokeExecuted`|`LicenseRevokeFailed` log lines emitted per `22-log-line-contract.md`. Silent revoke BANNED.

## 10. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link is first tab stop.
- Tab order (list): Sidebar > Breadcrumb > Refresh > Issue > SearchInput > FilterChips > Table headers > Table rows > Pagination.
- Table row focus reveals a full-row 2 px `--ring` outline per `28-a11y-conformance.md` §4 with `scroll-margin-block: var(--space-8)`.
- Sort direction announced via `aria-sort` on the `<th>` AND a glyph, per `28-a11y-conformance.md` §3.
- Destructive Revoke Dialog: focus trapped, initial focus on the phrase Field, Escape confirms only when the phrase is empty per `21-component-dialog.md` §5.

## 11. Telemetry

Emit per `22-log-line-contract.md`:
- `RoutePresented` on first paint with `RouteId: "Admin.Licenses.List"` or `"Admin.Licenses.Detail"`, `A11yViolations: 0`, `LoadDurationMs`.
- `TableFilterApplied` with `FilterField` + `Direction` (values NEVER logged).
- `TableSortChanged` with `Column` + `Direction`.
- `LicenseIssueConfirmed` / `LicenseIssueExecuted` | `LicenseIssueFailed`, same for Renew and Revoke, with `LicenseId`, `IdempotencyKey`, `ErrorCode`, `RequestId`.
- Field VALUES (serials, tier, environment) NEVER logged, per `27-content-voice.md` §9.

## 12. Anti-patterns (BANNED)

1. Component-local pagination or filter state (URL is the single source).
2. Row-level click (whole `<tr>` as a link).
3. `useQuery` + `isLoading` gating of the initial render.
4. Silent 403 or silent 404 (must be terminal cards).
5. Optimistic Issue/Renew/Revoke.
6. Sending mutations without `Idempotency-Key`.
7. Destructive Revoke without phrase-typing Dialog.
8. Bulk-selection UI in v1.
9. Colored Status pill without a glyph.
10. Relative time as the primary date rendering.
11. Fallback-to-zero on partial failure (must Banner + Toast).
12. Per-card / per-column refresh Buttons.

## 13. Acceptance criteria

- AC-ROUTE-LICENSES-001: Both routes render under `_authenticated`; permission denial renders 403 terminal card, not a redirect.
- AC-ROUTE-LICENSES-002: All list state (filters, sort, pagination) round-trips through the URL.
- AC-ROUTE-LICENSES-003: List Loading uses Skeleton Table; detail Loading uses Skeleton section band; NO layout shift on load.
- AC-ROUTE-LICENSES-004: Below 720 px container width, the list renders as RecordCards per `24-component-table.md` §11.
- AC-ROUTE-LICENSES-005: Revoke routes through phrase-typing Dialog and emits Confirmed + Executed|Failed log lines.
- AC-ROUTE-LICENSES-006: One `invalidateQueries(["Admin.Licenses"])` refreshes list, detail, and Audit; `["Admin.Overview"]` is also invalidated on mutation.
- AC-ROUTE-LICENSES-007: Axe zero `serious`/`critical` on both routes at 360/768/1440.
- AC-ROUTE-LICENSES-008: `RoutePresented` fires with `A11yViolations: 0` on Loaded and Empty states.

## 14. Open items (for follow-up commits)

- `Admin.Licenses.Bulk*` commands (deferred to v2 per §12 anti-pattern 8) MUST be added to `32-command-registry.md` §7 with `Since` bumped when introduced.
- Export CSV Button is intentionally out of v1; when added, MUST route through a Sheet per `29-responsive-matrix.md` and stream via server function.
