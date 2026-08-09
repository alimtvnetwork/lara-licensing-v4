# KPI and Chart Catalog

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** Single normative source for read-only quantitative surfaces (KPI cards, sparklines, line/bar charts, gauges) used in Admin overview, Reseller portal quota widgets, AppBuilder log dashboards, and any future analytics-style route. Every future per-surface blueprint that shows a number in a card, a chart, or a gauge MUST cite this file.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`26-iconography-and-assets.md`](./26-iconography-and-assets.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`../21-app/11-api-contracts/00-envelope-and-pagination.md`](../21-app/11-api-contracts/00-envelope-and-pagination.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Purpose and non-purpose

| Primitive | Purpose | NOT for |
|-----------|---------|---------|
| KPI card | A single scalar aggregate + optional trend indicator, at a glance. | Editable value. Interactive filter (use FilterChip). Comparison across categories (use Bar chart). |
| Sparkline | Micro line chart embedded in a KPI card or table cell showing trend of the KPI's underlying series. | Standalone chart with axes. Precise value reads (there is no axis). |
| Line chart | Continuous series over time, one to four lines. | Categorical comparisons (use Bar). More than four lines (use faceted small multiples, out of v1 scope). |
| Bar chart | Categorical comparison, discrete categories on the primary axis. | Continuous trends (use Line). Nested categories deeper than one level. |
| Gauge | Bounded fill of a known quota against a documented ceiling (RemainingQuota / TotalQuota). | Unbounded counts. Directional trends. |

Pie, donut, radar, treemap, and sunburst charts are BANNED in v1. Small multiples, brush selection, zoom, and animated transitions between datasets are OUT OF SCOPE for v1.

## 2. KPI card

### 2.1 Anatomy

```
+-------------------------------------------------------------+
| [icon] Label                                                |
|                                                             |
| ValueBig      DeltaChip (up 4.2% vs 30d)                    |
|                                                             |
| [------------ sparkline ------------------]                 |
|                                                             |
| Footnote (definition or timestamp)                          |
+-------------------------------------------------------------+
```

Icon and label are required. Delta chip and sparkline are OPTIONAL and MUST be present together or absent together (a delta without a sparkline is BANNED because the delta has no context).

### 2.2 Geometry

- Min inline-size 240 px; max 360 px; height determined by content. Fixed-height KPI cards are BANNED.
- Padding: `--space-4` (16 px) on all sides.
- Border: 1 px `--border`; radius `--radius-md`; no elevation (KPI cards live in the page, not floating).
- Value typography: `Display/S` (`clamp(28px, 3vw, 40px)`, weight 600, `font-variant-numeric: tabular-nums`).
- Label: `Label/Small` (12 px, weight 600, `--fg-muted`); uppercase is BANNED (`27-content-voice.md` §2).
- Footnote: `Body/Small` (12 px, `--fg-muted`).

### 2.3 DeltaChip

- Format: `<ArrowUp> 4.2%` or `<ArrowDown> 1.1%` or `— 0%` (steady). Icon is `ArrowUp` / `ArrowDown` / `Minus` per `26-iconography-and-assets.md` §5; NEVER color-only.
- Sign convention MUST be domain-declared per KPI (see §7). "Higher is better" and "lower is better" KPIs MUST render up/down glyphs identically; only the tone changes.
- Tone tokens: `success` when the direction is favorable, `destructive` when unfavorable, `neutral` when the delta is smaller than the noise floor (§7 threshold). Purely color-encoded direction is BANNED.
- Comparison window MUST be printed inline: `vs previous 30 d`. Undocumented deltas are BANNED.

### 2.4 States

| State | Trigger | Render |
|-------|---------|--------|
| Loading | Query not yet resolved | Skeleton for Value, Label, DeltaChip, sparkline geometry preserved. `aria-busy="true"` on the card. |
| Empty | Data returned but numerator zero (e.g. "0 active licenses") | Real Value `0`, DeltaChip renders when history exists, sparkline renders when at least 2 points exist. |
| Unavailable | Data source returned a valid "no data" signal or the caller lacks permission for the underlying series | Value replaced with `<Identifier>` placeholder `--`, DeltaChip and sparkline hidden, Footnote states the reason (`Not available in your role.` or `No data yet.`). |
| Error | 4xx / 5xx on the KPI query | Card renders an in-card Banner (`23-component-toast-banner.md`) with `ErrorCode` + `RequestId`; Value hidden. |
| Rate-limited | `429 RateLimited` | `RetryAfterBanner` inside the card. |

Silently rendering `0` for "Unavailable" is BANNED (misleads the operator).

## 3. Sparkline

- Rendered inside a KPI card OR a table cell.
- Height 32 px; inline-size flexes to fill container; no axes, no gridlines, no legend.
- Single line, 1.5 px stroke, `currentColor` inheriting the KPI's semantic tone; last-point dot 3 px radius; no area fill.
- Requires at least 2 data points. Below 2 points, sparkline is hidden (see §2.4 Unavailable).
- MUST NOT be the only carrier of a value. Screen readers announce the KPI Value; the sparkline is `role="img"` with `aria-label` describing the trend in words (`"trend: rising over 30 days"`).

## 4. Line chart

### 4.1 Anatomy

- Title (H2 inside route), optional subtitle, series legend, plot area, x-axis (temporal), y-axis (numeric), footer with data source + timestamp.
- Max four series per plot. More than four requires faceted small multiples (out of v1 scope) or a Table view.

### 4.2 Encoding rules

- Each series distinguished by BOTH color AND stroke pattern (`solid`, `dashed 6/2`, `dotted 2/2`, `dash-dot 6/2/2/2`). Color-only distinction is BANNED (`28-a11y-conformance.md` §2.2).
- Line stroke 2 px; series dots at 3 px radius on hover/focus of the nearest x-tick only; no persistent per-point markers.
- Y-axis MUST include zero unless a spec-declared reason to truncate is documented in the surface blueprint AND a break-axis glyph is rendered.
- X-axis ticks use ISO-8601 dates in the shortest unambiguous form; localized month names are OUT OF SCOPE for v1.
- Colors MUST come from `--chart-1` .. `--chart-4` tokens declared in `08-token-registry.md`. Ad hoc chart colors are BANNED.

### 4.3 Interactivity

- Hover / focus over the plot MUST snap to the nearest x-tick and reveal a tooltip listing every series' value at that tick, in the series legend's order.
- Keyboard: left/right arrows advance the focused x-tick; Home/End jump to first/last; Escape clears focus. Focus is a visible vertical guide line, NOT a per-point marker.
- Legend items are focusable Buttons that toggle series visibility. Hidden series MUST persist across route navigation via URL params (`hide=<SeriesId>,<SeriesId>`).
- Tooltip is a Popover per `22-component-menu-popover.md` (non-modal `role="dialog"`), NOT the native `title` attribute.

### 4.4 Textual summary (mandatory)

Every chart MUST render a `<figcaption>` beneath it summarizing what the chart shows in one sentence, plus a screen-reader-only paragraph naming the extremes (max, min, direction of change). Charts without textual summaries are BANNED (WCAG 1.1.1 non-text content).

## 5. Bar chart

### 5.1 Encoding rules

- Categorical axis is discrete; category labels wrap at 20 characters and rotate 0° up to 8 bars, 45° from 9 to 16 bars, and above 16 bars the chart MUST be replaced by a Table (`24-component-table.md`).
- Bars use `--chart-1` for single-series and `--chart-1` / `--chart-2` for two-series grouped. Stacked bars are permitted only when the total has meaning (sum of the parts); if any part can be negative, stacked bars are BANNED.
- Value labels above the bar are OPTIONAL; when omitted, the tooltip is REQUIRED to reveal the exact value.
- Y-axis MUST include zero. No exceptions.
- Bar width flexes with container inline-size; gap between bars is 25% of the bar width.

### 5.2 Sort

- Default sort is domain-defined (declared in the surface's blueprint): usually value-descending. Alphabetical, chronological, and rank sorts are permitted; user-selectable sort MUST persist in the URL.

## 6. Gauge

- Fills left-to-right (or top-to-bottom on narrow containers) from 0 to a documented ceiling. The ceiling MUST come from the API response, NOT a hard-coded constant.
- Fill tone thresholds are domain-declared per KPI in §7. Default: `success` up to 60%, `warning` 60..85%, `destructive` above 85%.
- Track height 8 px; radius `--radius-full`; fill animates only on initial mount (200 ms ease-out); no continuous animation; disabled under `prefers-reduced-motion: reduce`.
- Accessible name via `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, `aria-valuemax`, and `aria-valuetext` naming the ceiling in the same units as the Value.

## 7. KPI registry (normative)

Every KPI shipped in v1 MUST live in this registry. Ad hoc KPI cards without a registry row are BANNED. Adding a new KPI REQUIRES a same-commit update.

| KpiKey | Value | Unit | Direction | Noise floor | Delta window | Source (operationId) | Route |
|--------|-------|------|-----------|-------------|--------------|----------------------|-------|
| `Admin.ActiveLicenses` | count | licenses | higher-better | ± 1 | 30 d | `Admin.Metrics.Licenses.Active` | `/admin` |
| `Admin.NearExpiryLicenses` | count | licenses | lower-better | 0 | 7 d | `Admin.Metrics.Licenses.NearExpiry` | `/admin` |
| `Admin.RevokedLicenses` | count | licenses | lower-better | 0 | 30 d | `Admin.Metrics.Licenses.Revoked` | `/admin` |
| `Admin.SerialsBound` | count | serials | higher-better | ± 1 | 30 d | `Admin.Metrics.Serials.Bound` | `/admin` |
| `Admin.RateLimit429s` | count | events | lower-better | 0 | 24 h | `Admin.Metrics.RateLimit.Events` | `/admin/abuse` |
| `Admin.PendingQuotaRequests` | count | requests | (neutral) | 0 | 7 d | `Admin.QuotaRequests.Pending` | `/admin` |
| `Reseller.RemainingQuota` | count | licenses | higher-better | 0 | (no delta; gauge only) | `Reseller.Quota.Current` | `/reseller` |
| `Reseller.PendingQuotaRequests` | count | requests | (neutral) | 0 | 7 d | `Reseller.QuotaRequests.Pending` | `/reseller` |
| `Builder.KeysActive` | count | keys | higher-better | 0 | (no delta) | `Builder.Metrics.Keys.Active` | `/builder` |
| `Builder.Logs24h` | count | log lines | (neutral) | 0 | 24 h | `Builder.Metrics.Logs.Recent` | `/builder` |
| `EndUser.ActiveDevices` | count | devices | (neutral) | 0 | (no delta) | `EndUser.Metrics.Devices.Active` | `/me` |

`operationId` values are provisional and MUST be reconciled with `../21-app/26-route-dto-index.md` and the future `spec/26-app-api-swagger/` folder.

## 8. Data fetching contract

- Each KPI binds to exactly one query. Composite KPIs computing across two queries are BANNED (the aggregation lives on the server).
- Queries use `useSuspenseQuery` with `queryKey` including `KpiKey` and the delta window; loaders `ensureQueryData`.
- Refetch is triggered by a route-level Refresh Button (`24-component-table.md` §4 pattern); per-card refresh Buttons are BANNED (invites double-fetch).
- Optimistic KPI updates are BANNED. Mutations invalidate the KPI query.

## 9. Precision, formatting, units

- Integer counts MUST NOT be pluralized in the Value ("42"); pluralize only in the Label ("licenses"). Locale is `en-US` per `27-content-voice.md` §7.
- Thousands separator: comma (`1,234`). No apostrophe, no space. Handled by a shared `formatCount()` helper (v1 name; runtime implementation deferred).
- Percentages: one decimal place unless the underlying value is an integer percent (then zero decimals). Percent sign has no space (`4.2%`).
- Bytes and durations: use IEC and ISO units respectively (KiB / MiB / GiB; s / min / h). Localized long forms are OUT OF SCOPE for v1.
- Timestamps in Footnote render as absolute ISO-8601 UTC with the letter `Z` suffix.

## 10. Accessibility

- KPI card is a `<section aria-labelledby="<id>">` with the Label carrying that id; icon is `aria-hidden="true"`.
- DeltaChip includes an off-screen expansion of the sign (`<span class="sr-only"> increased by</span>`) so the trend is announced correctly.
- Every chart is `<figure role="figure">` with `<figcaption>` per §4.4.
- Gauge uses `role="progressbar"` and `aria-valuetext` (§6).
- No chart or KPI relies on color alone (`28-a11y-conformance.md` §2.2).
- Focus indicators on legend Buttons and gauge follow `28-a11y-conformance.md` §3.2.

## 11. Logging

Emit via `logger.info` per `../21-app/22-log-line-contract.md`:
- `KpiPresented` on query resolution: `KpiKey`, `Value`, `DeltaPercent`, `Window`, `RequestId`. Field VALUES underlying the KPI (individual license identifiers, serials) are NEVER logged.
- `ChartPresented` on chart mount: `ChartId`, `SeriesCount`, `RangeFrom`, `RangeTo`, `RequestId`.
- `ChartLegendToggled`: `ChartId`, `SeriesId`, `Visible`.
- `KpiUnavailable` at info when a KPI resolves to §2.4 Unavailable with a reason token (`RolePermission`, `NoData`, `SourceOffline`).

## 12. Anti-patterns

1. Pie, donut, radar, treemap, sunburst charts.
2. Color-only direction indicator on DeltaChip.
3. Y-axis truncation without a break-axis glyph and a blueprint-declared reason.
4. Silent `0` rendering when the underlying data is unavailable.
5. Composite KPIs computed by joining two client queries.
6. Ad hoc chart colors outside `--chart-*` tokens.
7. Chart without `<figcaption>` and screen-reader summary.
8. Native `title` tooltip on chart plot.
9. Per-card refresh Buttons.
10. Sparkline as the sole carrier of a value.
11. Stacked bars including a negative component.
12. More than four series in a Line chart.
13. Fixed-height KPI card.
14. Hard-coded gauge ceiling in the client.
15. Uppercase KPI Label.

## 13. Acceptance criteria

- AC-KPI-001: Every KPI card renders Label + Value; DeltaChip and sparkline appear together or not at all.
- AC-KPI-002: Every registered KPI in §7 resolves to exactly one runtime query with a matching `KpiKey`.
- AC-KPI-003: Line and Bar charts render a `<figcaption>` and a screen-reader summary; verified by future `tests/chart-a11y.test.tsx`.
- AC-KPI-004: DeltaChip direction glyphs (`ArrowUp`, `ArrowDown`, `Minus`) match the sign; verified by unit test.
- AC-KPI-005: `KpiPresented` fires exactly once per successful KPI query resolution and never includes underlying field values.
- AC-KPI-006: `KpiUnavailable` fires with a reason token when §2.4 Unavailable renders; the card MUST NOT render `0`.
- AC-KPI-007: Line chart legend visibility persists in URL params across route navigation.
- AC-KPI-008: Gauge uses `role="progressbar"` with `aria-valuetext` naming the ceiling.

## 14. Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- Manual: §7 registry rows are a strict subset of the operationIds that will appear in `spec/26-app-api-swagger/` (future); parity check is deferred to that folder's AC.
