# Component: Table and List

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for tabular data surfaces (Admin.Licenses, Admin.Serials, Admin.Resellers, Admin.Users, Admin.Audit, Reseller.QuotaRequests, Builder.Clients, Builder.Keys, Builder.Logs, EndUser.Products, EndUser.Devices) and their record-card fallback under narrow viewports. It binds the runtime already shipped in `src/routes/_authenticated/*` (v0.141.0) to a single, testable contract.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md), [`13-navigation-ia.md`](./13-navigation-ia.md), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`19-component-select.md`](./19-component-select.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`../21-app/11-api-contracts/00-envelope-and-pagination.md`](../21-app/11-api-contracts/00-envelope-and-pagination.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md).

---

## 1. Purpose and non-purpose

| Primitive | Purpose | NOT for |
|-----------|---------|---------|
| Table | Server-paginated, server-sorted, server-filtered record view. One row = one resource. | Editable spreadsheets. Free-typing into cells is BANNED; open a Dialog/Sheet. |
| RecordCard list | The same rows re-laid-out as vertical cards when the container is narrower than `--container-table-min` (720 px). | A different data shape. Card and Row MUST be sourced from the same query result. |
| DefinitionList | Read-only key/value display inside a detail Sheet or Card. | Row-per-record data (use Table). |

Multi-select bulk mutation, inline editing, and drag-reordering are OUT OF SCOPE for v1. When required later, add a §12 amendment; do not overload cells.

## 2. Anatomy

```
+-- TableToolbar -------------------------------------------------+
| SearchInput | FilterChips | ColumnMenu   RefreshBtn PrimaryBtn |
+-- TableHeader --------------------------------------------------+
| Col1 (sortable) | Col2 | Col3 (sortable) | ... | RowActionsCol  |
+-- TableBody ----------------------------------------------------+
| Row: cell cell cell ...                          [...] menu    |
| ...                                                             |
+-- TableFooter --------------------------------------------------+
| Range: "1 to 25 of 342"  |  PageSizeSelect  |  Prev  Next       |
+-----------------------------------------------------------------+
```

Every Table renders all four regions or none. Half-tables (header without footer, body without toolbar) are BANNED.

## 3. Query and URL state

State that MUST live in the URL (search params, not client-only state):

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `page` | integer `>= 1` | `1` | 1-indexed. |
| `pageSize` | one of `10`, `25`, `50`, `100` | `25` | Reject other values server-side. |
| `sort` | `Field:Asc` or `Field:Desc` | route-defined | Only one sort key. Compound sort v2. |
| `q` | string | empty | Free-text search; server decides fields. |
| `filter.<Field>` | scalar or `,`-joined list | empty | Field name in PascalCase matching DTO. |

Rules:
- Back/forward navigation MUST restore the exact grid state; nothing about the visible rows may depend on unshared client state.
- Changing any param resets `page` to `1` UNLESS the param IS `page`.
- Empty filter values MUST be removed from the URL, not sent as `filter.X=`.

## 4. Data fetching contract

- Every Table binds to exactly one paginated envelope (`{ Items, Page, PageSize, Total }`) as defined in [`../21-app/11-api-contracts/00-envelope-and-pagination.md`](../21-app/11-api-contracts/00-envelope-and-pagination.md).
- Fetch is `useSuspenseQuery` with `queryKey` including every URL param above; loader must `ensureQueryData`.
- Refresh Button calls `queryClient.invalidateQueries` for that key; it does NOT bump `page`.
- Optimistic mutation is BANNED at the row level; mutations return the updated row, and the query is invalidated on success.

## 5. Columns

Column config MUST live in a typed const per route (`const columns: LaraColumn<Row>[] = [...]`) with:
- `Field`: PascalCase, MUST match the DTO field.
- `Header`: short label, sentence case, no trailing colon.
- `Align`: `start` (text), `end` (numeric/monetary/counts), `center` (icon/status only). Numeric columns MUST tabular-align (`font-variant-numeric: tabular-nums`).
- `Sortable`: boolean; MUST match server capability.
- `Width`: one of `auto`, `min-content`, `<n>ch`, `<n>fr`. Percentage widths are BANNED.
- `Truncate`: `true` collapses to single line with `text-overflow: ellipsis`; the full value MUST be reachable via row detail (Sheet), NOT via a hover tooltip.

Column visibility toggling via `ColumnMenu` persists in `localStorage` under `lara.table.<route>.columns` and MUST be a subset (never a superset) of the route's declared columns.

## 6. Sorting

- Sortable headers are Buttons with `aria-sort` set to `ascending`, `descending`, or `none`.
- Only one column is sorted at a time; activating a new sortable header replaces the sort key.
- Third click on the same header REMOVES sort and returns to route-defined default (does NOT cycle indefinitely).
- Sort indicator icon (`ArrowUp` / `ArrowDown`) is REQUIRED; color-only sort indication is BANNED.

## 7. Filtering

- Filters live in a `FilterBar` above the header, rendered as removable Chips.
- Enum filters MUST use `19-component-select.md` closed-set-parity registry; free-typing enum values is BANNED.
- Applying a filter is explicit: pressing Enter, blurring the field, or clicking Apply. Filter-as-you-type is BANNED for anything but `q` (which is debounced 300 ms).
- Clearing all filters is a single Button `Clear filters`, only visible when at least one filter is active.

## 8. Row actions

- Primary row action (e.g. Open) is invoked by clicking the row's identifier cell (rendered as a `<Link>`) or by pressing Enter with the row focused. The whole row is NOT clickable; only the identifier cell.
- Secondary actions live in a per-row overflow Menu (`22-component-menu-popover.md`) reached via a trailing `...` Button with `aria-label="Actions for <Identifier>"`.
- Destructive row actions route through the confirmation Dialog (`21-component-dialog.md` §5) and generate `Idempotency-Key` on Dialog open.
- Actions the caller lacks permission for are HIDDEN from the Menu (`13-navigation-ia.md` §3), not disabled with a tooltip.

## 9. States

| State | Trigger | Render |
|-------|---------|--------|
| Loading (initial) | Suspense not yet resolved | Skeleton rows matching `pageSize`, no toolbar interactivity. |
| Loading (refetch) | Query in flight after initial | Keep existing rows, dim body 60 %, disable Prev/Next and PrimaryBtn. |
| Empty (no data) | `Total === 0` and no filters | Empty state per `15-empty-error-loading-catalog.md` §3; PrimaryBtn stays enabled. |
| Empty (filtered) | `Total === 0` and at least one filter | Empty state with `Clear filters` Button; PrimaryBtn stays enabled. |
| Forbidden | `403` on the paginated query | Route shell 403 per `16-route-shell-states.md`, NOT an in-table banner. |
| Error | 4xx (except 403) or 5xx | In-table Banner (`23-component-toast-banner.md`) with `ErrorCode`, `RequestId`, and a Retry Button; body hidden. |
| Rate-limited | `429 RateLimited` | `RetryAfterBanner`; Refresh Button disabled until countdown reaches zero. |

Partial-fetch (some rows, some error) is BANNED; the whole page is one atomic result.

## 10. Pagination footer

- Renders `<lower> to <upper> of <total>` in `Body/Small` type, `tabular-nums`, no localized-number injection at v1 (locale is `en-US`).
- Prev/Next are icon+label Buttons; disabled at the boundaries; Enter/Space activates.
- Jump-to-page and last-page shortcuts are OUT OF SCOPE.
- PageSizeSelect is a Select from the closed set in §3; changing it resets `page` to `1`.

## 11. RecordCard fallback

When the Table's container inline-size is `< 720 px` (measured via container query, NOT viewport width):
- Body switches from `<table>` to a `role="list"` of `<article role="listitem">` cards.
- Each card renders: identifier (title link), primary status Badge, up to three key/value pairs from the same columns config (`ShowInCard: true`), and the same overflow Menu.
- Toolbar filters collapse into a single Sheet trigger `Filters (<n>)`.
- Header is hidden; sort moves into the toolbar Sheet.
- Pagination footer keeps the same contract.

The Table and RecordCard views MUST be driven by ONE component; conditionally rendering a separate mobile file is BANNED (drift risk).

## 12. Keyboard

| Key | Action |
|-----|--------|
| Tab | Move focus through Toolbar, Header sortables, Rows (as a group), Row actions Button, Footer. |
| ArrowDown / ArrowUp | With focus inside the body, move row focus; wraps at page boundaries only when Prev/Next is enabled. |
| Enter | On focused row: open primary action. On sortable header: cycle sort. |
| `.` | With row focus: open the overflow Menu at the row (progressive enhancement; MUST NOT be the only path). |
| `/` | Focus `SearchInput`. |
| Escape | Close overflow Menu, clear focus from row group. |

Row focus is a roving `tabindex`, not one tab stop per row.

## 13. Semantics and ARIA

- Root: `<table role="table">` with `<thead role="rowgroup">`, `<tbody role="rowgroup">`, `<tr role="row">`, `<th scope="col">`, `<td>`.
- Sortable header: `<button aria-sort="...">` inside `<th>`; `aria-sort` on the `<th>`, not the button.
- Row action Button: `aria-label` includes the row's identifier so screen readers can disambiguate.
- Loading rows: `aria-busy="true"` on `<tbody>`; skeleton cells have `aria-hidden="true"`.
- `SearchInput` uses `role="searchbox"` and `aria-controls` referencing the table's `id`.

## 14. Logging

Emit (via `logger.info` per `../21-app/22-log-line-contract.md`):
- `TablePageViewed` on query resolution: `Route`, `Page`, `PageSize`, `Sort`, `FilterCount`, `Total`, `RequestId`.
- `TableSortChanged` on sort mutation: `Route`, `From`, `To`.
- `TableFilterApplied` / `TableFilterCleared`.
- `TableRowActionInvoked` on overflow-Menu selection: `Route`, `Action`, `RowIdentifier`.

Row field VALUES MUST NOT be logged (privacy); only identifiers and enums.

## 15. Anti-patterns

1. Inline-editable cells.
2. Client-side sort / filter / pagination over a fetched page.
3. Whole-row `onClick` navigation.
4. Row hover to reveal actions (must be keyboard-reachable at all times).
5. Tooltip on truncated cells as the primary reveal path.
6. Separate mobile route or component file.
7. Optimistic row updates.
8. Percentage column widths.
9. Free-text enum filter inputs.
10. Multi-column sort in v1.
11. Auto-refresh polling shorter than 30 s.
12. Empty state that hides the toolbar's PrimaryBtn.

## 16. Acceptance criteria

- AC-TBL-001: URL params `page`, `pageSize`, `sort`, `q`, `filter.*` fully reproduce the visible grid across a fresh navigation.
- AC-TBL-002: Third click on an active sortable header removes sort; `aria-sort="none"` on the `<th>`.
- AC-TBL-003: Refetch state dims the body and disables Prev/Next; skeleton is NOT re-rendered.
- AC-TBL-004: `403` on the paginated query renders the route shell 403, not an in-table Banner.
- AC-TBL-005: RecordCard fallback activates at container inline-size `< 720 px` via container query, verified in `tests/table-record-card.test.tsx`.
- AC-TBL-006: Destructive row actions from the overflow Menu open the confirmation Dialog per `21-component-dialog.md` §5 and generate `Idempotency-Key` on Dialog open, not on Menu selection.
- AC-TBL-007: `TablePageViewed` fires exactly once per successful query resolution with `RequestId` present.

## 17. Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- Future `tests/table-record-card.test.tsx` asserts AC-TBL-005; tracked in `.lovable/pending-tasks/`.
