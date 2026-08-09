# Route Blueprint: Admin Serials (`/admin/serials`, `/admin/serials/:SerialId`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Third route blueprint; extends the template established by [`33-route-blueprint-admin-overview.md`](./33-route-blueprint-admin-overview.md) and [`34-route-blueprint-admin-licenses.md`](./34-route-blueprint-admin-licenses.md). Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for the Admin Serials list and detail routes.
**Related:** [`13-navigation-ia.md`](./13-navigation-ia.md), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`21-component-dialog.md`](./21-component-dialog.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`32-command-registry.md`](./32-command-registry.md), [`34-route-blueprint-admin-licenses.md`](./34-route-blueprint-admin-licenses.md), [`../21-app/07-serial-generation.md`](../21-app/07-serial-generation.md), [`../21-app/08-hash-key.md`](../21-app/08-hash-key.md), [`../21-app/09-verify-key.md`](../21-app/09-verify-key.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/44-environments.md`](../21-app/44-environments.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`55-print-export-stylesheet.md`](./55-print-export-stylesheet.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

`/admin/serials` is the operational Table for issued serials (list, filter, deep-link) and `/admin/serials/:SerialId` is the canonical detail route (view identity, rebind to a different `EnvironmentId`, inspect the hash-key/verify-key trail and bound-devices Table).

Out of scope: serial issuance itself (called from a License detail Dialog per `34-route-blueprint-admin-licenses.md` §9, not from this route); end-user serial verification (owned by `spec/21-app/09-verify-key.md`).

## 2. Route wiring

| Route | File | Permission | Loader |
|---|---|---|---|
| `/admin/serials` | `src/routes/_authenticated/admin/serials/index.tsx` | `Admin.Serials.Read` | `ensureQueryData(serialsListQuery(searchParams))` |
| `/admin/serials/:SerialId` | `src/routes/_authenticated/admin/serials/$SerialId.tsx` | `Admin.Serials.Read` | `ensureQueryData(serialDetailQuery({ SerialId }))` + `ensureQueryData(serialDevicesQuery({ SerialId }))` + `ensureQueryData(serialAuditQuery({ SerialId }))` in parallel via `Promise.all` |

- Layout parent: `_authenticated` gate per `12-shell-layout.md`.
- Permission denial: 403 terminal card per [`16-route-shell-states.md`](./16-route-shell-states.md) §4, NEVER a silent redirect.
- 404: `notFoundComponent` renders the 404 terminal card citing the `SerialId` in the copy per `27-content-voice.md` §5 error triad; `Route.useParams()` is the only source for that value (never `useLoaderData`).
- Head metadata: list `title = "Serials - Lara Licensing"`; detail `title = \`Serial ${SerialString} - Lara Licensing\``; no `og:image` on either (internal).
- Search params (list, TanStack `validateSearch`): `q` (string, `≤ 128` chars, matches serial-string prefix OR bound-device-fingerprint prefix), `Status` (enum from `25-component-badge-status.md` §5: `Issued` / `Bound` / `Rebound` / `Revoked`), `EnvironmentId` (uuid), `LicenseId` (uuid, for licence-scoped drill-down from `34-` detail), `PageIndex` (int `≥ 0`, default 0), `PageSize` (closed set `{25, 50, 100}`, default 25), `Sort` (closed set `{ IssuedAtDesc, IssuedAtAsc, BoundAtDesc, BoundAtAsc, StatusAsc }`, default `IssuedAtDesc`). URL is the single source of truth per `24-component-table.md` §3.

## 3. Layout

List:

```
Shell (12) > PageHeader (H1 "Serials", RightActions: Refresh)
  > Filter bar (SearchInput + FilterChips: Status, Environment, License)
  > Table (24) with RecordCard fallback below 720 px container width
  > Pagination footer (URL-bound)
```

Detail:

```
Shell > Breadcrumb (Admin > Serials > <SerialString>)
  > PageHeader (H1 "<SerialString>", subhead: Status Badge + Environment Badge + LicenseId <Link>, RightActions: Rebind...)
  > Two-column band (container-query `>= 960 px`, single column below):
      Column A: Identity card (License <Link>, Environment, IssuedAt, BoundAt, LastVerifiedAt, HashKey redacted, VerifyKey redacted)
      Column B: Bound Devices Table (compact) + Audit Trail Table (compact)
```

## 4. PageHeader

- List H1: `Serials` (single `<h1>` per `28-a11y-conformance.md` §5).
- Detail H1: canonical serial string exactly as returned by the API per `07-serial-generation.md` (Base32/prefix format); MUST NOT be lowercased or truncated.
- Right actions (list): `Refresh` icon Button (invalidates `["Admin.Serials.List"]` in one call, motion disabled under `prefers-reduced-motion`). No `Issue serial...` on this route: issuance is called from License detail per `34-` §9.
- Right actions (detail): `Rebind...` destructive Button binding to `Admin.Serials.Rebind` command per `32-command-registry.md` §7 (MUST route through phrase-typing Dialog per `21-component-dialog.md` §5, `Idempotency-Key` bound `OnDialogOpen`).
- XS/SM collapse: right actions collapse to a MoreHorizontal Menu per `29-responsive-matrix.md`.

## 5. Table contract (list)

- Columns (pinned order): `Serial`, `Status`, `Environment`, `License`, `IssuedAt`, `BoundAt`, `LastVerifiedAt`, `Actions`.
- `Serial` cell is a full-cell `<Link>` to `/admin/serials/:SerialId`, NEVER a Button; whole-row click BANNED per `24-component-table.md` §6.
- `License` cell is a full-cell `<Link>` to `/admin/licenses/:LicenseId`, styled as an inline Link inside the cell (not the whole cell) to avoid two competing link targets in the same row per `24-component-table.md` §6.
- `Status`, `Environment` render as Badges using the tone map in `25-component-badge-status.md` §5; glyph mandatory, color NEVER the sole cue.
- Date cells render via `src/lib/format-date.ts` (UTC tooltip on hover per `27-content-voice.md` §7); relative time BANNED as the primary rendering.
- `Actions` cell: MoreHorizontal Menu with `View`, `Rebind...` (destructive, filtered by permission per `32-` §5 permission-hidden rule).
- Selection: none in v1.

## 6. Filtering, search, sort

- SearchInput binds to `q`, 300 ms debounce per `18-component-input.md` §7; polite live-region announces result count on settle NEVER per keystroke.
- Search matches either full-serial prefix OR bound-device fingerprint prefix per `07-serial-generation.md` §4; matching is server-side, never client-only.
- FilterChips are Select-backed enums (`19-component-select.md`), each binding to one search param. Clear-all preserves `PageSize` and `Sort`.
- Empty results render the [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 **Filter-reset** variant with a `Clear filters` action (BANNED to render a primary create CTA); when the collection itself is empty for the caller's scope with no filter set, render the **First-run** variant; when RLS forbids the whole collection, render the **Permission-scope** variant per `53-` §3; `15-empty-error-loading-catalog.md` §4 kept as legacy pointer. Loading skeleton rows are Mode B (List) per [`54-loading-state-catalog.md`](./54-loading-state-catalog.md) §2.

## 7. Route states (list AND detail)

Identical seven-row state table to `34-route-blueprint-admin-licenses.md` §7 (Loading skeleton, Loaded, Empty, 404 terminal, 403 terminal, Rate limited inline `RetryAfterBanner`, Error inline Banner with `ErrorCode`+`RequestId`+Retry calling `router.invalidate()` AND `reset()`). Partial failure on detail (bound-devices OR audit sub-query fails while identity loads): the failing sub-Table renders its own error Banner AND a summary Toast; fallback-to-empty is BANNED.

## 8. Data contract

- Query keys: `["Admin.Serials.List", <serializedSearchParams>]`, `["Admin.Serials.Detail", SerialId]`, `["Admin.Serials.Devices", SerialId]`, `["Admin.Serials.Audit", SerialId]`. One `invalidateQueries(["Admin.Serials"])` refreshes the whole area.
- Rebind mutation MUST invalidate `["Admin.Serials"]` AND `["Admin.Licenses", { LicenseId }]` AND `["Admin.Overview"]` (KPI Slots) in ONE call.
- `useSuspenseQuery` in components; loader uses `ensureQueryData`. `useQuery`+`isLoading` BANNED per `33-` §11.
- Optimistic Rebind BANNED (server is authority; envelope carries the new `EnvironmentId` and `ReboundAt`).
- All mutations send `Idempotency-Key` per `29-idempotency-lifecycle.md`, generated at Dialog open per `21-component-dialog.md` §5.
- HashKey and VerifyKey MUST be rendered redacted by default (last 4 chars only per `27-content-voice.md` §9); a `Reveal` Button per Field is behind an additional Dialog confirmation gate. Reveal action emits `SerialSecretRevealed` per `22-log-line-contract.md` with `SerialId`+`RequestId`; the VALUES are NEVER logged.

## 9. Dialogs invoked

- `Rebind...` Dialog: destructive per `21-component-dialog.md` §5 phrase-typing (user types the serial exactly). Body fields: target `EnvironmentId` Select from `44-environments.md` closed set, `Reason` Field (`≤ 240` chars, per `18-component-input.md` §5). Emits `SerialRebindConfirmed` and `SerialRebindExecuted`|`SerialRebindFailed` log lines with `SerialId`, `FromEnvironmentId`, `ToEnvironmentId`, `IdempotencyKey`, `ErrorCode`, `RequestId`. Silent rebind BANNED.
- `Reveal HashKey` / `Reveal VerifyKey` Dialogs: non-destructive but gated per §8; the revealed VALUE renders only in-Dialog and is cleared from DOM on close; clipboard copy uses `writeText` and NEVER logs the value.

## 10. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link first tab stop.
- Tab order (list): Sidebar > Breadcrumb > Refresh > SearchInput > FilterChips > Table headers > Table rows > Pagination.
- Table row focus reveals a full-row 2 px `--ring` outline per `28-a11y-conformance.md` §4.
- Sort direction announced via `aria-sort` on the `<th>` AND a glyph.
- Redacted Fields carry `aria-label="Hash key, redacted"` per `27-content-voice.md` §9 so screen readers never announce the value.

## 11. Telemetry

Per `22-log-line-contract.md`:
- `RoutePresented` with `RouteId: "Admin.Serials.List"` or `"Admin.Serials.Detail"`, `A11yViolations: 0`, `LoadDurationMs`.
- `TableFilterApplied` and `TableSortChanged` with `FilterField` / `Column` + `Direction` (VALUES NEVER logged).
- `SerialRebindConfirmed` / `SerialRebindExecuted`|`SerialRebindFailed` and `SerialSecretRevealed`.
- Serial strings, hash keys, verify keys NEVER logged as values.

## 12. Anti-patterns (BANNED)

1. Component-local pagination or filter state.
2. Row-level click (whole `<tr>` as a link).
3. Two full-cell links inside the same row (Serial and License both being full-cell).
4. `useQuery`+`isLoading` initial gate.
5. Silent 403 or silent 404.
6. Optimistic Rebind.
7. Rendering hash key / verify key un-redacted in list or default detail view.
8. Rebind without phrase-typing Dialog.
9. Logging serial / hash / verify VALUES.
10. Relative time as primary rendering.
11. Client-only search matching (must be server-side).
12. Per-card refresh Buttons.

## 13. Acceptance criteria

- AC-ROUTE-SERIALS-001: Both routes render under `_authenticated`; permission denial renders 403 terminal card.
- AC-ROUTE-SERIALS-002: All list state (filters, sort, pagination) round-trips through the URL.
- AC-ROUTE-SERIALS-003: Below 720 px container width, the list renders as RecordCards.
- AC-ROUTE-SERIALS-004: Rebind routes through phrase-typing Dialog and emits Confirmed + Executed|Failed logs.
- AC-ROUTE-SERIALS-005: Hash keys and verify keys are redacted by default; reveal is gated by a second Dialog and emits `SerialSecretRevealed`.
- AC-ROUTE-SERIALS-006: One `invalidateQueries(["Admin.Serials"])` refreshes list, detail, devices, audit; License and Overview are also invalidated on mutation.
- AC-ROUTE-SERIALS-007: Axe zero `serious`/`critical` on both routes at 360/768/1440.
- AC-ROUTE-SERIALS-008: `RoutePresented` fires with `A11yViolations: 0` on Loaded and Empty states.

## 14. Open items (for follow-up commits)

- Bulk rebind to a new environment (Admin.Serials.BulkRebind) deferred to v2; when added, MUST route through Sheet (not Dialog) per `29-responsive-matrix.md` and preserve per-serial phrase-typing.
