# Route Blueprint: Admin Features (`/admin/features`, `/admin/features/:FeatureKey`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Sixth route blueprint; extends the template established by `33-`..`37-`. Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for the Admin Features catalog, TierFeatures mapping, and per-license feature overrides.
**Related:** [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`20-component-choice.md`](./20-component-choice.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`32-command-registry.md`](./32-command-registry.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md), [`../21-app/45-license-features.md`](../21-app/45-license-features.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

`/admin/features` is the catalog view of platform Features (list of `FeatureKey` rows with metadata + TierFeatures assignment matrix summary). `/admin/features/:FeatureKey` is the per-feature detail route (metadata, per-tier default enablement matrix, per-license override count with a `<Link>` to the filtered License list).

Out of scope: per-license feature overrides UI (owned by License detail per `34-route-blueprint-admin-licenses.md` §9 future extension); feature-flag runtime evaluation (owned by `45-license-features.md`).

## 2. Route wiring

| Route | File | Permission | Loader |
|---|---|---|---|
| `/admin/features` | `src/routes/_authenticated/admin/features/index.tsx` | `Features.Manage` | `ensureQueryData(featuresListQuery(searchParams))` |
| `/admin/features/:FeatureKey` | `src/routes/_authenticated/admin/features/$FeatureKey.tsx` | `Features.Manage` | `ensureQueryData(featureDetailQuery({ FeatureKey }))` + `ensureQueryData(tierFeaturesMatrixQuery({ FeatureKey }))` + `ensureQueryData(featureOverrideCountQuery({ FeatureKey }))` + `ensureQueryData(featureAuditQuery({ FeatureKey }))` in parallel via `Promise.all` |

- Layout parent: `_authenticated` gate per `12-shell-layout.md`.
- Permission key: `Features.Manage` per `40-permissions.md` §1 (Admin-only by default).
- Permission denial: 403 terminal card per `16-route-shell-states.md` §4, NEVER a silent redirect.
- 404: `notFoundComponent` renders the 404 terminal card citing the `FeatureKey` (validated as `^[A-Z][A-Za-z0-9]{2,63}$` per `45-license-features.md` naming rule) via `Route.useParams()`. An invalid-format `FeatureKey` renders the 404 card, NEVER a 400.
- Head metadata: list `title = "Features - Lara Licensing"`; detail `title = \`Feature ${FeatureKey} - Lara Licensing\``; no `og:image`.
- Search params (list, `validateSearch`): `q` (string ≤ 128 chars, matches FeatureKey OR Label prefix, server-side), `Status` (enum: `Active` / `Deprecated`), `Tier` (enum from `43-license-tiers.md`, filters to features whose TierFeatures row is enabled for that tier), `PageIndex` (int ≥ 0, default 0), `PageSize` (closed set `{25, 50, 100}`, default 25), `Sort` (closed set `{ FeatureKeyAsc, FeatureKeyDesc, CreatedAtDesc, OverrideCountDesc }`, default `FeatureKeyAsc`).

## 3. Layout

List:

```
Shell (12) > PageHeader (H1 "Features", RightActions: Refresh, Create feature...)
  > Filter bar (SearchInput + FilterChips: Status, Tier)
  > Table (24) with RecordCard fallback below 720 px container width
  > Pagination footer (URL-bound)
```

Detail:

```
Shell > Breadcrumb (Admin > Features > <FeatureKey>)
  > PageHeader (H1 "<FeatureKey>", subhead: Status Badge + CreatedAt + DeprecatedAt if any,
                RightActions: Edit metadata..., Deprecate... | Reactivate...)
  > Two-column band (container-query `>= 960 px`, single column below):
      Column A: Metadata card (FeatureKey immutable, Label editable, Description editable, DefaultEnabled Switch (default off)
                              per `20-component-choice.md`, CreatedAt, LastModifiedAt, DeprecatedAt, DeprecationReason)
                TierFeatures matrix card (row per Tier from `43-license-tiers.md`, column per state: Enabled Switch,
                                          Overrideable Checkbox, Notes ≤ 120 chars)
      Column B: Override summary card (OverrideCount total + <Link> "View N overriding licenses" filtered to
                                       `/admin/licenses?FeatureKey=<FeatureKey>&OverrideStatus=Set`)
                Audit Trail Table (compact)
```

## 4. PageHeader

- List H1: `Features` (single `<h1>`).
- Detail H1: `FeatureKey` rendered verbatim (case preserved, MUST NOT be lowercased).
- Right actions (list): `Refresh` icon Button; `Create feature...` primary Button binding to `Admin.Features.Create` per `32-command-registry.md` §7 (opens Create Dialog).
- Right actions (detail):
  - `Edit metadata...`: opens the Edit Dialog (Label + Description + DefaultEnabled Switch). Non-destructive.
  - `Deprecate...` OR `Reactivate...`: mutually exclusive with current `Status`, only one rendered.
    - `Deprecate...` is destructive per `21-component-dialog.md` §5 phrase-typing (user types the `FeatureKey` exactly) with mandatory `Reason` Field. Deprecation DOES NOT disable the feature at runtime; it flags the catalog row and blocks new TierFeatures assignments. Copy MUST state this explicitly per `27-content-voice.md` §5 destructive triad.
    - `Reactivate...` is non-destructive single-Button confirmation.
- XS/SM collapse to MoreHorizontal Menu per `29-responsive-matrix.md`.

## 5. Table contract (list)

- Columns (pinned order): `FeatureKey`, `Label`, `Status`, `DefaultEnabled` (Switch READ-ONLY in list, editable only on detail), `TiersEnabled` (chip cluster with tier tokens, `+N` overflow chip opens Popover per `22-component-menu-popover.md`), `OverrideCount` (right-aligned integer, `<Link>` to `/admin/licenses?FeatureKey=<FeatureKey>&OverrideStatus=Set`), `CreatedAt`, `Actions`.
- `FeatureKey` cell is a full-cell `<Link>` to `/admin/features/:FeatureKey`; whole-row click BANNED per `24-component-table.md` §6.
- `OverrideCount` cell contains a nested `<Link>` (NOT full-cell) to preserve the `FeatureKey` full-cell Link as the single primary target per `35-route-blueprint-admin-serials.md` §12 anti-pattern 3.
- `Status`, `TiersEnabled` chips render with glyph tokens per `25-component-badge-status.md` §5; color NEVER the sole cue.
- Dates via `src/lib/format-date.ts` UTC tooltip; relative time BANNED as primary.
- `Actions` cell: MoreHorizontal Menu with `View`, `Edit metadata...`, `Deprecate...`|`Reactivate...` (permission-hidden filter per `32-` §5).
- Selection: none in v1 (bulk tier-matrix edits BANNED, deferred).

## 6. Filtering, search, sort

- SearchInput binds to `q`, 300 ms debounce, server-side match on `FeatureKey` OR `Label` prefix. Case-insensitive on `Label`, case-sensitive on `FeatureKey` because keys are PascalCase-normalised at write time per `45-license-features.md`.
- Empty results render Empty catalog card per [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 (**Filter-reset** variant with `Clear filters`, **First-run** variant when the collection is empty for the caller scope, **Permission-scope** variant when RLS forbids the whole collection; legacy `15-empty-error-loading-catalog.md` §4 kept as pointer) with a `Clear filters` action.

## 7. Route states

Identical seven-row state table to `34-route-blueprint-admin-licenses.md` §7 (Loading skeleton, Loaded, Empty, 404 terminal, 403 terminal, Rate limited `RetryAfterBanner`, Error inline Banner with `ErrorCode`+`RequestId`+Retry calling `router.invalidate()` AND `reset()`). Partial failure on detail: if the TierFeatures matrix query fails while Metadata loads, the matrix card renders its own Banner AND a summary Toast; DefaultEnabled and Deprecate/Reactivate actions MUST disable with helper text (`Tier assignment state unavailable, retry before editing.`). Fallback-to-empty BANNED.

## 8. Data contract

- Query keys: `["Admin.Features.List", <serializedSearchParams>]`, `["Admin.Features.Detail", FeatureKey]`, `["Admin.Features.TierMatrix", FeatureKey]`, `["Admin.Features.OverrideCount", FeatureKey]`, `["Admin.Features.Audit", FeatureKey]`. One `invalidateQueries(["Admin.Features"])` refreshes the whole area.
- Every mutation (Create, EditMetadata, TierMatrix cell toggle, Deprecate, Reactivate) invalidates `["Admin.Features"]` in ONE call. Deprecate ALSO invalidates `["Admin.Overview"]` (feature-count KPI if added later) and `["Admin.Licenses"]` scoped none (Licenses query only invalidated if the deprecate action ALSO removes overrides, which v1 does NOT do).
- TierMatrix cell toggle (Enabled Switch or Overrideable Checkbox) is a single PATCH per cell, `Idempotency-Key` at Switch commit (debounced 300 ms for rapid toggle, only the FINAL state within debounce window is sent, matching `20-component-choice.md` §5 Switch commit rule). Optimistic UI on Switch BANNED (server is authority; loading state visible via `aria-busy` on the Switch).
- `useSuspenseQuery` in components; loader uses `ensureQueryData`. `useQuery`+`isLoading` BANNED.
- All mutations send `Idempotency-Key` per `29-idempotency-lifecycle.md`, generated at Dialog open or Switch commit.
- Concurrent-editor guard: detail loader returns a `FeatureEtag`; every metadata / matrix mutation MUST send `If-Match: <FeatureEtag>`; `412 PreconditionFailed` renders an inline Banner instructing refresh.

## 9. Dialogs invoked

- `Create feature...` Dialog: form with `FeatureKey` Field (PascalCase regex `^[A-Z][A-Za-z0-9]{2,63}$` validated client + server per `18-component-input.md` §6, IMMUTABLE after create), `Label` Field ≤ 120 chars, `Description` Field ≤ 480 chars, `DefaultEnabled` Switch (default off). Non-destructive. `Idempotency-Key` at Dialog open. Emits `FeatureCreateConfirmed` and `FeatureCreateExecuted`|`Failed`.
- `Edit metadata...` Dialog: form with `Label`, `Description`, `DefaultEnabled`. `FeatureKey` NEVER editable. Emits `FeatureEditConfirmed` and `FeatureEditExecuted`|`Failed`.
- `Deprecate...` Dialog: destructive phrase-typing (user types the `FeatureKey` exactly) with mandatory `Reason` Field ≤ 240 chars. Copy MUST state that deprecation flags the catalog row and blocks new TierFeatures assignments but DOES NOT disable the feature at runtime per §4. Emits `FeatureDeprecateConfirmed` and `FeatureDeprecateExecuted`|`Failed`.
- `Reactivate...` Dialog: single-Button confirmation. Emits `FeatureReactivateExecuted`|`Failed`.

## 10. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link first tab stop.
- Tab order (list): Sidebar > Breadcrumb > Refresh > Create > SearchInput > FilterChips > Table headers > rows > Pagination.
- Tab order (detail): Sidebar > Breadcrumb > Edit metadata > Deprecate|Reactivate > Metadata card Fields > TierMatrix cells (row-major, tier by tier, Enabled Switch then Overrideable Checkbox then Notes Field per row) > Override summary > Audit Trail.
- TierMatrix Switches carry `aria-label="Tier <TierLabel>, feature <FeatureKey>, enabled"` per `20-component-choice.md` §4; screen readers announce the axes explicitly.
- Sort direction announced via `aria-sort` on the `<th>` AND a glyph.
- When Status is `Deprecated`, the TierMatrix Switches are `aria-disabled="true"` with a Popover explaining that new assignments are blocked; existing enabled rows remain readable.

## 11. Telemetry

Per `22-log-line-contract.md`:
- `RoutePresented` with `RouteId: "Admin.Features.List"` or `"Admin.Features.Detail"`, `A11yViolations: 0`, `LoadDurationMs`.
- `TableFilterApplied` / `TableSortChanged` with field VALUES NEVER logged.
- `FeatureCreate*`, `FeatureEdit*`, `FeatureTierMatrixToggle*`, `FeatureDeprecate*`, `FeatureReactivate*` with `FeatureKey`, `TierId` (for matrix toggle), `FromState` and `ToState` booleans, `FeatureEtag`, `IdempotencyKey`, `ErrorCode`, `RequestId`. `Reason` VALUE NEVER logged; `ReasonFingerprint` IS logged on Deprecate.
- Feature `Label` and `Description` values NEVER logged (fingerprint on edit if needed for change tracking, deferred to v2).

## 12. Anti-patterns (BANNED)

1. Editing `FeatureKey` after create.
2. Optimistic Switch toggle on TierMatrix cells.
3. Whole-row click on the list.
4. Two full-cell `<Link>`s in the same list row (FeatureKey full-cell, OverrideCount nested).
5. `useQuery`+`isLoading` initial gate.
6. Silent 403 or silent 404 (invalid-format `FeatureKey` MUST render 404, not 400).
7. Deprecate without phrase-typing.
8. Deprecate copy implying runtime disablement (deprecation is catalog-flag only in v1).
9. Missing `If-Match: <FeatureEtag>` on mutations.
10. Bulk TierMatrix edits in v1.
11. Logging `Label`, `Description`, or `Reason` VALUES.
12. Rendering Deprecated TierMatrix Switches as fully hidden (must render read-only with explanation).

## 13. Acceptance criteria

- AC-ROUTE-FEATURES-001: Both routes render under `_authenticated`; permission denial renders 403 terminal card.
- AC-ROUTE-FEATURES-002: Invalid-format `FeatureKey` in the URL renders 404 terminal card, never 400.
- AC-ROUTE-FEATURES-003: All list state (filters, sort, pagination) round-trips through the URL.
- AC-ROUTE-FEATURES-004: TierMatrix Switch toggles are server-authoritative with `aria-busy` during commit, no optimistic flip.
- AC-ROUTE-FEATURES-005: Deprecate routes through phrase-typing (`FeatureKey`) with mandatory Reason; emits Confirmed + Executed|Failed and `ReasonFingerprint`.
- AC-ROUTE-FEATURES-006: All mutations send `Idempotency-Key` AND `If-Match: <FeatureEtag>`; `412` renders inline refresh Banner.
- AC-ROUTE-FEATURES-007: One `invalidateQueries(["Admin.Features"])` refreshes list, detail, matrix, override count, audit.
- AC-ROUTE-FEATURES-008: Axe zero `serious`/`critical` at 360/768/1440; TierMatrix Switch `aria-label`s announce both tier and feature.

## 14. Open items (for follow-up commits)

- Per-license feature override editing UI deferred to a License-detail Dialog (Step 34 residual per `34-` §14 future extension).
- Bulk TierMatrix edits deferred to v2; when added, MUST route through a Sheet and preserve `If-Match: <FeatureEtag>` per row.
- Feature-flag SDK snippet Popover for developers deferred to v2 (linked from Override summary card).
