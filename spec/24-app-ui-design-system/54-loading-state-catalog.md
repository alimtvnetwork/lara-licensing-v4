# Loading-State Catalog

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Single normative source for skeleton shapes, spinner rules, min-hold and max-wait timings, and the mount / dismount handoff between loading, empty, error, and success states.
**Owner:** Loading governance. Every route, list, form-submit, and mutation MUST cite one rule set here.
**Related:** [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`24-component-table.md`](./24-component-table.md), [`26-component-form-field.md`](./26-component-form-field.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`52-icon-illustration-registry.md`](./52-icon-illustration-registry.md), [`53-empty-state-catalog.md`](./53-empty-state-catalog.md).

---

## 1. Purpose and scope

Defines four loading MODES (Route-shell, List, Inline, Blocking), the skeleton shape catalog, spinner rules, and the timings that prevent skeleton flashes and long silent waits. Every loading surface in the app MUST match one mode.

Out of scope: optimistic UI (BANNED across the app per `24-`, `26-`, `38-` blueprints; every mutation waits for the server); progress bars for long-running exports (deferred with §14).

## 2. Four modes (closed set)

- **Mode A (Route-shell):** the initial route load. Full-page skeleton composed of Sidebar + App bar (rendered from cached shell state per `16-` §2) plus the route body skeleton. Fires when `router.state.isLoading` for the incoming match transitions true.
- **Mode B (List):** a data-list query is `isPending`. Table / Card list skeleton with row placeholders per §5. Fires when `query.isPending` is true AND the query has never resolved (`query.data === undefined`).
- **Mode C (Inline):** a control-level query is `isPending` inside an already-loaded surface (e.g. a Reveal card fetching a secret, a Popover fetching a preview). Small inline spinner + `Loading` label.
- **Mode D (Blocking):** a user-initiated mutation is running (Button click, form submit). Button enters loading state per `17-` §6 (spinner replaces the icon, label unchanged, `aria-busy="true"` on the Button, focus retained). The rest of the surface remains interactive UNLESS the mutation is destructive per `21-component-dialog.md` §7 in which case the confirming Dialog Buttons + Backdrop capture pointer events.

Never mix modes on the same surface at the same time (e.g. a Route-shell skeleton over a Blocking Dialog). Skeleton BANNED during background refetch (`isFetching && !isPending`); the previous data stays visible.

## 3. Timings (closed set)

```
--loading-min-hold-sm:  200ms   # Mode C inline spinners
--loading-min-hold-md:  400ms   # Mode B list skeletons, Mode A shell skeletons
--loading-max-wait-md: 2000ms   # after this, a Banner explains the wait
--loading-max-wait-lg: 8000ms   # after this, the operation is treated as failed
```

- **Min-hold:** once a skeleton is shown, it MUST stay visible for at least the min-hold even if the query resolves earlier. Prevents a jitter where a skeleton flashes for 30 ms then disappears. Implementation: a `useMinHold(query.isPending, 400)` hook returns `true` while `isPending` OR while `Date.now() - firstPendingAt < 400`.
- **Delay-before-show:** the skeleton MUST NOT render for queries that resolve under 100 ms. Implementation: a `useDelayedShow(query.isPending, 100)` hook returns `true` only after `isPending` has been true for 100 ms. Combined with min-hold, the skeleton either does not appear at all (fast queries) or appears for at least 400 ms (slow queries). Sub-100 ms flashes BANNED.
- **Max-wait `md`:** at 2000 ms the loading surface renders an inline Banner `Still loading. Server response is slower than usual.` `aria-live="polite"`. The Banner MUST NOT replace the skeleton; it appears beneath it.
- **Max-wait `lg`:** at 8000 ms the query is cancelled (`AbortController`), the surface renders the error state per `16-` §4 with a `Retry` Button, and a `LoadingTimeoutExceeded` log line fires per `../21-app/22-log-line-contract.md`. This is the ONLY normative place a client-side timeout can fire; ad-hoc timeouts BANNED.

## 4. Handoff rules

- `isPending` true, no cached data: Mode A or B skeleton (per surface).
- `isPending` true, cached data present (TanStack Query background refetch or `keepPreviousData`): render the cached data with a subtle `RefreshCw` spinner in the App bar per `52-` §5; NEVER a skeleton.
- `isSuccess` with `data.length === 0`: hand off to the empty-state per `53-` §2 selecting First-run / Filter-reset / Permission-scope by query state.
- `isError`: hand off to the error surface per `16-` §4 with `Retry` (retry re-runs the query, NOT `queryClient.clear()`).
- `isSuccess` with rows: render the surface content. Fade-in follows `51-` §6 registry (opacity only, `--motion-duration-md` + `--motion-easing-decelerate`, Strategy B under reduced-motion).

Handoff between modes MUST be atomic within one animation frame. A "loading -> empty" or "loading -> error" flicker is BANNED.

## 5. Skeleton shape catalog

Every list / table / detail surface has a skeleton row here. Skeleton rows use a single background tint token `--color-skeleton` at `opacity: 0.6..1.0` pulsing via `51-` §6 `Skeleton pulse` row.

> **Timing exception vs `51-motion-and-reduced-motion.md`:** the delay-before-show `100 ms` and min-hold `400 ms` values pinned in §3 are LOADING-SPECIFIC thresholds, NOT motion durations. They are deliberately outside `51-` §5's motion closed set (`80 / 120 / 240 / 320 / 480 ms`) because they gate skeleton visibility, not property animation. Motion tokens govern how a skeleton pulses; timing tokens here govern whether it appears at all. No conflict; the exception is normative (per audit CR-03 F17).


| Surface | Skeleton shape | Row count | Notes |
|---|---|---|---|
| Sidebar (Mode A) | 8 rectangles at Sidebar-tab height (32 px), full Sidebar width | 8 | Persistent across route changes; drawn from cached shell state per `16-` §2 |
| App bar (Mode A) | Search Field rectangle + user-avatar circle | 1 | Persistent |
| Overview KPI Cards | 8 Card-height rectangles in a 4-column grid on `lg` viewport | 8 | Matches `33-` §5 KPI grid |
| Overview activity feed | 6 alternating rectangles (avatar + label + timestamp) | 6 | Matches `33-` §6 feed |
| Table (Mode B, `24-`) | Header row (real) + N body rows each rendering per-column shapes: text-cell = 60% wide rectangle, badge-cell = pill, action-cell = 3 icon squares | `PageSize` | Matches active PageSize; do not default to 10 rows if the URL says 25 |
| Card list (Mode B) | N Card-height rectangles (title 40% + body-line 100% + body-line 60%) | 6 | Independent of PageSize |
| Detail hero (Mode B on a `/:Id` route) | Title 45% + subtitle 25% + 3 stat-block squares | 1 | Matches `34-`, `35-`, `36-`, `38-` detail headers |
| Reveal card (Mode C) | Full-width Field rectangle + Copy Button | 1 | Renders while the reveal endpoint mints the secret |
| Popover preview (Mode C) | 3 body-lines at 100% / 80% / 60% widths | 1 | Renders while `queryFn` for the popover resolves |
| Command Palette results (Mode C) | 5 result-row rectangles (icon + label + shortcut hint) | 5 | Per `32-` |
| Sessions Table on `/me` | 4 body rows (region + browser + timestamp) | 4 | Per `41-` |
| Quota approvals list (`/admin/quotas`) | 6 rows (reseller-avatar + label + delta-chip + Etag-token badge) | 6 | Per `37-` |
| Tier matrix (`/admin/features/:FeatureKey`) | Header row (real) + 5 body rows each with 4 Switch skeletons | 5 | Per `38-` |
| Feature-override chip list (`/admin/licenses/:Id/features`) | 8 pill rectangles | 8 | Per `34-` |

- 13 skeleton rows; adding a new list / detail surface requires adding a row.
- Skeleton MUST match the real content's layout (columns, row height, badge placement). A generic 6-row grey stack is BANNED because layout shift on data arrival breaks the content-continuity contract per `28-` §7.

## 6. Spinner rules

- Single spinner icon per `52-` §5 registry (`Loader2`).
- Sizes: inline spinner uses `--icon-size-md` (16 px); Button spinner uses `--icon-size-sm` (14 px) per `17-` §6; App bar refresh uses `--icon-size-md`.
- Colour: `currentColor` (inherits from parent). Status spinners (error retry) MUST NOT tint the spinner semantic red; the SURROUNDING text carries the semantic tint.
- Animation: rotates 360deg per cycle at `--motion-duration-md` (per `51-` §6 registry row `Spinner`). Under reduced-motion: paused static circle + visible `Loading` text with `aria-live="polite"`.
- Global page spinners BANNED. Route-shell loading uses a skeleton, never a spinner overlay.

## 7. Announcements + aria

- Every skeleton container carries `role="status"` and `aria-live="polite"` with a screen-reader-only child announcing `Loading <resource name>.` on mount (e.g. `Loading licenses.`).
- The `aria-live` region MUST NOT re-announce during min-hold; the message fires once on mount and is silent for subsequent state changes within the same loading cycle.
- On handoff to success, the surface's real content becomes the new live target; the skeleton's `aria-live` region unmounts and does NOT announce completion. Announcing `Done` on every list load is chatty and BANNED.
- On handoff to error, the error surface's `role="alert"` region fires the error copy; the skeleton region unmounts silently.
- On handoff to empty, the empty-state's `role="status"` region announces per `53-` §3.

## 8. Blocking mutation (Mode D)

- Button enters loading state per `17-` §6: label unchanged, icon replaced by `Loader2`, `aria-busy="true"` on the Button, `disabled` NOT set (a11y contract; disabled removes the Button from the accessibility tree and the aria-busy signal is lost); Button pointer events are blocked via `pointer-events: none` in the loading variant CSS instead.
- Dialog primary Buttons in Mode D: the Dialog Backdrop blocks pointer events on the rest of the surface; Escape MUST NOT close a Dialog mid-mutation (a partial-write followed by a hidden Dialog is worse than the wait). Escape re-focuses the Button and shows an inline warning `Please wait for the operation to finish.`.
- Optimistic UI BANNED (per Plan 06 blueprints); the surface remains stale until the mutation resolves, then TanStack Query invalidation refetches the source of truth.
- On mutation error: Button exits loading state, error is surfaced via a Toast per `23-` §5 tied to the mutation's `RequestId`, and the surface state is unchanged (no phantom row insertion / deletion).

## 9. Timeouts and cancellation

- Every client query and mutation MUST run under an `AbortController` scoped to the query key / mutation key; unmount cancels the request.
- The `--loading-max-wait-lg` (8000 ms) hard cap aborts the request and logs `LoadingTimeoutExceeded` with the route path, query key, and RequestId. The user sees the standard error surface with `Retry`. Retry MUST start a fresh AbortController; retrying with a stale signal BANNED.
- Retry uses exponential backoff for RATE-LIMITED responses (429): `Retry-After` header from the server, plus jitter of ±25%. All other 4xx errors surface the error and STOP; automatic retry on 4xx BANNED.
- 5xx retries: the client MAY retry up to twice with 500 ms / 1500 ms delays before surfacing the error; the retry policy is documented once here and referenced from `../21-app/14-rate-limiting.md`.

## 10. Motion

- Skeleton pulse: `--motion-duration-md` + `--motion-easing-linear`, opacity 0.6..1.0 loop, per `51-` §6 registry row.
- Spinner: `--motion-duration-md` per full rotation, `--motion-easing-linear`, per `51-` §6 registry row.
- Skeleton -> content transition: fade-only, `--motion-duration-md` + `--motion-easing-decelerate`, opacity 0 -> 1 on the content, opacity 1 -> 0 on the skeleton. NO cross-slide, NO scale, NO layout shift.
- Under reduced-motion: skeleton pulse paused (static tinted rectangles), spinner paused (static circle + `Loading` text), transition instant (Strategy A).

## 11. Observability

Every loading cycle MUST emit these log lines per `../21-app/22-log-line-contract.md`:

- `LoadingStarted` on mount, with route path, mode (A/B/C/D), query key or mutation key, PrefersReducedMotion boolean.
- `LoadingTimeoutExceeded` if `--loading-max-wait-lg` fires.
- `LoadingResolved` on unmount, with duration in ms, terminal state (Success / Empty / Error / Aborted), and `SkeletonHeldForMinHold` boolean.

Log lines MUST NOT include the query's response body, user identifiers, or the resource IDs beyond the route path (which is already redacted per `../21-app/22-log-line-contract.md` §5).

## 12. Anti-patterns (BANNED)

1. Skeleton flash under 100 ms (must use `useDelayedShow`).
2. Skeleton dismissal under 400 ms after first render (must use `useMinHold`).
3. Skeleton shown during background refetch (`isFetching && data !== undefined`).
4. Generic grey rectangle stack that does not match content layout.
5. Global page spinner overlay.
6. Optimistic UI (across the entire app).
7. `disabled` on a Button in loading state (breaks `aria-busy`; use `pointer-events: none`).
8. Escape closes a Dialog mid-mutation.
9. `queryClient.clear()` on retry (must scope to the failing query key).
10. Automatic retry on 4xx errors other than 429.
11. Ad-hoc client-side timeouts outside the `--loading-max-wait-lg` contract.
12. Announcing `Done` on every list load.
13. Skeleton container without `role="status"` + `aria-live="polite"`.
14. Spinner animation continuing under reduced-motion.
15. Cross-slide or scale motion between skeleton and content.

## 13. Acceptance criteria

- AC-LOADING-001: Every list / detail / mutation surface cites one Mode (A / B / C / D) and uses the corresponding skeleton shape from §5 or spinner rule from §6.
- AC-LOADING-002: `useDelayedShow(100)` and `useMinHold(400)` gate every skeleton; sub-100 ms flashes and sub-400 ms holds are absent from the built app.
- AC-LOADING-003: Skeletons carry `role="status"` + `aria-live="polite"` with a resource-specific announcement fired once on mount.
- AC-LOADING-004: `--loading-max-wait-md` (2000 ms) renders the still-loading Banner; `--loading-max-wait-lg` (8000 ms) aborts and hands off to the error surface with `LoadingTimeoutExceeded` in the logs.
- AC-LOADING-005: Every query and mutation runs under an `AbortController` cancelled on unmount.
- AC-LOADING-006: Retry policy: 429 uses `Retry-After` header + jitter; 5xx retries up to twice with 500 / 1500 ms delays; 4xx (other than 429) surfaces the error and stops.
- AC-LOADING-007: `LoadingStarted` and `LoadingResolved` log lines fire on every loading cycle with the fields in §11.
- AC-LOADING-008: `check-loading-states.py` (new linter §14) passes.

## 14. Linter and open items

- New linter `linter-scripts/check-loading-states.py`: scans components using `useQuery` / `useSuspenseQuery` / `useMutation` and verifies (a) a skeleton citation via `data-loading-mode="A|B|C|D"` prop, (b) `useDelayedShow` + `useMinHold` wrappers on Mode A / B, (c) `AbortController` presence in the query / mutation factory. Runs in CI.
- Progress bars for long-running exports deferred (paired with §5 export stylesheet coming in Step 45).
- File-upload progress deferred (not in v1; Builder update publishing uses a single-shot POST per `40-` §5).
- Streaming responses (SSE / chunked) deferred (v2).
