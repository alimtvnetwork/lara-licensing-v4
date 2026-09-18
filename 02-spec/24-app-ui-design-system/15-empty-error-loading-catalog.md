# Empty, Error, and Loading State Catalog

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for how the UI renders **absence of data** (empty), **failure to load or act** (error), and **work in progress** (loading). Component primitives ([`13-navigation-ia.md`](./13-navigation-ia.md), the forthcoming component catalog in Plan 06 Steps 13-20) and per-surface blueprints (Steps 21-28) MUST cite this file; they MUST NOT invent alternate anatomies.
**Related:** [`05-team-mood-and-ux-north-star.md`](./05-team-mood-and-ux-north-star.md), [`06-fluid-design-foundations.md`](./06-fluid-design-foundations.md), [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md), [`11-shape-and-motion.md`](./11-shape-and-motion.md), [`12-shell-layout.md`](./12-shell-layout.md), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Scope and principles

This catalog covers three orthogonal UI states:

- **Loading**: the UI is fetching or mutating; the user is waiting for a first paint of real data.
- **Empty**: the request succeeded and returned zero rows (or a null resource), and this is a legitimate, non-error outcome.
- **Error**: the request failed at the transport, protocol, or domain layer, or a mutation was rejected. Every error has a canonical `ErrorCode` from [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md).

Principles inherited from [`05-team-mood-and-ux-north-star.md`](./05-team-mood-and-ux-north-star.md):

- **No silent failure.** Every error state MUST render a user-visible message AND emit the log line defined in [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md). A caught exception with no visible surface is a spec violation.
- **Calm and precise.** Loading states MUST NOT flicker; empty states MUST NOT read as errors; error states MUST NOT read as loading. Copy is neutral, not apologetic.
- **Explainable.** Every error surface MUST show the `RequestId` chip so operators can cross-reference logs. See [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md) §RequestId propagation.

## 2. State classification decision tree

Consumers (loaders, mutations, component branches) MUST classify a state in this exact order:

1. Is the request in flight and there is no cached prior result? -> **Loading**.
2. Did the request throw or return a non-2xx envelope? -> **Error** (subclassify per §4).
3. Did the request return a 2xx envelope whose `Data` is `null`, `[]`, or a collection with zero rows AND this outcome is not a domain error? -> **Empty**.
4. Otherwise -> render the data.

A stale-while-revalidate refetch is **not** a loading state; the previous data remains rendered and a subtle inline "Updating..." indicator (per §3.4) MAY appear. Never blank the surface on refetch.

## 3. Loading contracts

### 3.1 Skeleton anatomy

Skeletons match the shape and rhythm of the eventual content. They are **not** spinners. Anatomy tokens come from [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) and [`11-shape-and-motion.md`](./11-shape-and-motion.md) §Skeleton pulse.

| Surface class | Skeleton shape | Row count | Duration budget before considered slow |
|---------------|----------------|-----------|:--------------------------------------:|
| KPI card grid | 4 cards, each 1 title bar + 1 numeric bar + 1 delta bar | fixed | 400 ms |
| Data table (Regular density) | header row + 8 body rows | 8 | 600 ms |
| Data table (Compact density) | header row + 12 body rows | 12 | 600 ms |
| Detail record | title bar + 3 field pairs + 1 action row | fixed | 400 ms |
| Timeline / audit list | 6 stacked rows with icon + 2 text bars | 6 | 600 ms |
| Form (create/edit) | 4 label+input pairs + submit bar | fixed | 300 ms |
| Dialog body | 2 text bars + 1 input | fixed | 200 ms |
| Toast placeholder | not applicable; toasts appear only on resolution | 0 | not applicable |

Row counts are fixed to prevent layout shift when data arrives. Skeleton bars use `background: color-mix(in oklab, var(--muted) 60%, transparent)` with the `skeleton-pulse` recipe from [`11-shape-and-motion.md`](./11-shape-and-motion.md) §7. Under `prefers-reduced-motion`, the pulse animation is replaced with a static tint at 40% opacity.

### 3.2 Deferred paint rule

Do not render a skeleton for requests expected to resolve under 150 ms. Use the pattern:

- 0-150 ms: render the previous frame (or an empty container of the target size to reserve layout).
- 150 ms and later: swap to skeleton.
- On resolution before 150 ms: skip the skeleton entirely.

This eliminates the "flash of skeleton" on fast networks and cached loaders. Timers MUST use `setTimeout`; scheduling via animation frames drifts under throttling.

### 3.3 Blocking vs non-blocking

- **Blocking loading**: the whole surface is unusable (initial navigation, no cached data). Render skeletons for the primary region only; keep the shell (sidebar, topbar, breadcrumb) responsive.
- **Non-blocking loading**: a subregion is refreshing. Render the previous data with a `role="status"` inline indicator: text "Updating..." with `aria-live="polite"`. Never disable interactive controls in a non-blocking load unless the mutation targets that control.

### 3.4 Mutation loading

- The triggering control (button, menu item) shows a spinner glyph replacing its icon; label text stays. Control is `aria-busy="true"` and `disabled`.
- Sibling controls on the same form stay enabled unless the mutation is destructive; destructive mutations disable the whole action row.
- If the mutation takes longer than 4 seconds, replace the button spinner with an inline "Still working..." caption below the action row. Do not add a modal.

### 3.5 SSR and hydration

Loaders that prefetch on the server per [`../tanstack-query-integration`](https://tanstack.com) render **data**, not skeletons. Skeletons appear only when data was not prefetched (client-only routes) or on client-triggered refetch. A skeleton visible on first paint of a prefetched route is a bug.

## 4. Error contracts

### 4.1 Error surface anatomy

Every error surface (panel, dialog, toast, page) contains, in order:

1. **Icon**: role-appropriate glyph from the icon set defined in the component catalog (Plan 06 Steps 13-20). Colored with `var(--destructive)` for hard failures, `var(--warning)` for retriable, `var(--muted-foreground)` for expected-outcome errors (e.g. `NotFound`).
2. **Headline**: one sentence, plain English, no exclamation, present tense. Example: "We could not load this reseller."
3. **Body**: one to three sentences. States what happened and what the user can do. Never shows raw stack traces or backend error strings.
4. **Actions**: primary action first ("Try again" for retriable; "Return to overview" for terminal). Secondary action optional ("Copy request ID").
5. **RequestId chip**: monospace pill in the surface's footer showing the last 12 chars of `X-Request-Id`. Copyable via click. Renders even if the value is `unknown` (in which case the chip reads `no-request-id` and log-line contract §4 requires the client to have logged that fact).

### 4.2 Error surface taxonomy

| `ErrorCode` family | Surface | Actions | Log level | Reference |
|--------------------|---------|---------|:---------:|-----------|
| `AuthTokenInvalid`, `AuthTokenExpired`, `AuthRefreshRaceLost` | Full-page interstitial redirecting to `/auth/sign-in` after 2 s countdown | Sign in (primary) | info | [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md) §Auth |
| `AuthzRoleDenied`, `AuthzPermissionDenied` | Full-page `/forbidden` route | Return to overview (primary), Contact admin (secondary, mailto) | warn | [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §4 |
| `NotFound` (row scope enforced) | Full-page `/not-found` route | Return to overview (primary) | info | §4.4 below |
| `Validation`, `FeatureUnknown`, `FeatureValueInvalid`, `EnvironmentMismatch` | Inline: form field errors + form-level banner OR panel-level banner if not form-bound | Fix and retry (inherent) | info | [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md) §Validation |
| `RateLimited` | Panel-level banner with live `<RetryAfterBanner>` countdown | Retry (disabled until countdown ends) | warn | [`../21-app/13-rate-limit-abuse.md`](../21-app/13-rate-limit-abuse.md) |
| `QuotaExhausted` | Panel-level banner in the issue-license form; sidebar Quota badge turns red | Request more (primary, opens quota-request form) | info | [`../21-app/42-quota-requests.md`](../21-app/42-quota-requests.md) |
| `Conflict`, `IdempotencyReplay` | Toast (non-blocking) with copy explaining the conflict; prior action's result is not re-applied | Refresh (primary) | warn | [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md) |
| `AuthSaltRotationFailed`, `UnknownServerError`, `InternalError` | Panel-level failure card OR full-page `/error` route (route-level errors only) | Try again (primary), Copy request ID (secondary) | error | [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md) §Server |
| Network (fetch threw) | Panel-level failure card; copy names the network failure without exposing URL | Try again (primary) | error | §4.5 below |

Any new `ErrorCode` added to [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md) MUST add a row here in the same PR. Drift fails `linter-scripts/check-error-surface-parity.py` (Plan 06 Step 47).

### 4.3 Copy rules

- No exclamation marks. No apologies ("Oops", "Sorry"). No blame ("You did X wrong").
- Second person for what the user can do ("Try again"). Third person for what happened ("The server did not respond in time").
- Names of internal systems are hidden. "The server" replaces "the API", "Supabase", "Cloudflare", "the worker", etc.
- Copy MUST be ready for i18n even though v1 ships English only: no concatenation, one string per surface, ICU-compatible.

### 4.4 NotFound is not an error

Row-scope RLS returns `404 NotFound` intentionally to prevent existence-leak per [`../21-app/40-permissions.md`](./40-permissions.md) §4. The UI renders this as a calm "This record does not exist or you do not have access" page, not a red failure surface. The log line remains `LogLevel = info` per [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

### 4.5 Network vs protocol failures

- **Network failure**: `fetch` rejected (offline, DNS, TLS). No `RequestId` is available; the chip shows `no-request-id` and the client MUST synthesize a client-side correlation id `client-<uuid>` and log it.
- **Protocol failure**: the response arrived but was malformed (invalid JSON, envelope schema violation). The client emits `UnknownServerError` per [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md) and includes the actual `X-Request-Id` from the response headers if present.

### 4.6 Retry semantics

- Retriable errors (`RateLimited`, `Network`, `UnknownServerError`, `InternalError`, `AuthSaltRotationFailed`): the "Try again" action is enabled; for `RateLimited` it is gated by the countdown.
- Non-retriable errors (`Validation`, `AuthzRoleDenied`, `AuthzPermissionDenied`, `NotFound`, `Conflict`, `IdempotencyReplay`, `QuotaExhausted`, `EnvironmentMismatch`, `FeatureUnknown`, `FeatureValueInvalid`): the primary action is remedy-shaped ("Fix and submit", "Return to overview", "Request more quota"), never a naked "Try again".

## 5. Empty contracts

### 5.1 Empty state anatomy

Every empty surface contains, in order:

1. **Illustration slot** (optional): a small pictogram, 96x96 max, using `var(--muted-foreground)` at 60% opacity. No stock imagery, no gradients, no color. When omitted, the surface leans on typography alone.
2. **Headline**: one sentence stating what is not here. Example: "No licenses yet."
3. **Body**: one sentence explaining why the state is legitimate and (when applicable) how to populate it. Example: "Issue the first license to get started."
4. **Primary action** (optional): the canonical creation action for this surface, permission-gated per [`../21-app/40-permissions.md`](../21-app/40-permissions.md). Hidden (not disabled) when the caller lacks the permission.
5. **Secondary action** (optional): a doc link or a filter reset.

### 5.2 Empty state taxonomy

| Surface class | Trigger | Illustration | Primary action | Notes |
|---------------|---------|:------------:|----------------|-------|
| List table (unfiltered) | Zero rows, no filters applied | Yes | Canonical create (permission-gated) | Uses the "first-run" copy from the per-surface blueprint |
| List table (filtered) | Zero rows, at least one filter active | No | "Clear filters" (secondary) | Distinct copy: "No matches for your filters." |
| Search results | Query returned zero rows | No | "Clear search" | Distinct copy: "No results for `<query>`." |
| Detail sub-tab (e.g. license -> serials) | Parent exists, child collection empty | No | Canonical create (permission-gated) | Never blocks the parent from rendering |
| KPI card | Metric is legitimately zero (not stale, not error) | No | None | Renders "0" with `--muted-foreground`; does not swap to an empty-state card |
| Timeline / audit | Zero events in range | No | Widen range (secondary) | |
| Sidebar section (Ops group with no items) | Role's Ops group is empty per [`13-navigation-ia.md`](./13-navigation-ia.md) §11 | No | None | Section header is not rendered; do not leave a labeled but empty group |

### 5.3 Zero versus null

A KPI reading "0 licenses issued this week" is **data**, not an empty state. Render the numeral. Only swap to empty-state anatomy when the entire surface (a table, a detail panel) has no meaningful shape to render.

### 5.4 Permission-gated empty states

When a surface is empty because the caller lacks read permission for **any** row, render `AuthzPermissionDenied` per §4.2, not an empty state. The distinction is decided server-side: RLS returning zero rows is empty; the endpoint returning `403` is authorization. The client MUST NOT infer authorization from row count.

## 6. Surface-to-state matrix

Each per-surface blueprint (Plan 06 Steps 21-28) MUST reference this matrix when it declares its state contracts:

| Surface (from [`12-shell-layout.md`](./12-shell-layout.md) §4) | Loading class (§3.1) | Empty class (§5.2) | Error classes (§4.2) |
|---------------------------------------------------------------|----------------------|--------------------|----------------------|
| Admin Overview | KPI card grid | KPI (zeros) | Auth, Server, Network |
| Admin Resellers list | Data table Regular | List unfiltered / filtered / search | Auth, Server, Network, RateLimited |
| Admin Reseller detail | Detail record + sub-tabs | Detail sub-tab | Auth, NotFound, Server, Network |
| Admin Licenses list | Data table Regular | List unfiltered / filtered / search | Auth, Server, Network, RateLimited |
| Admin License detail | Detail record | Detail sub-tab (serials) | Auth, NotFound, Server, Conflict |
| Admin Users | Data table Regular | List unfiltered / filtered | Auth, Server |
| Admin Categories, Features | Data table Compact | List unfiltered | Auth, Server, Validation |
| Admin App updates | Timeline | Timeline empty | Auth, Server, Validation |
| Admin Audit | Timeline | Timeline empty | Auth, Server, RateLimited |
| Admin Abuse | Data table Compact | List unfiltered | Auth, Server, RateLimited |
| Reseller Overview | KPI card grid | KPI (zeros) | Auth, Server, Network, QuotaExhausted (badge) |
| Reseller Licenses / Serials | Data table Regular | List unfiltered / filtered / search | Auth, Server, Network, RateLimited, QuotaExhausted (on create) |
| Reseller Quota requests | Data table Compact | List unfiltered | Auth, Server, RateLimited, Conflict, IdempotencyReplay |
| Reseller Activity | Timeline | Timeline empty | Auth, Server |
| Builder Clients / Keys / Updates | Data table Regular | List unfiltered | Auth, Server, Validation |
| EndUser Products / Devices | KPI card grid + list | List unfiltered | Auth, NotFound, Network |
| EndUser Update | Detail record | none (always populated) | Auth, Network, Server |
| Verify handshake (public) | Form spinner (§3.4) | none | EnvironmentMismatch, Validation, RateLimited, Network |
| Auth (sign-in) | Form spinner (§3.4) | none | AuthTokenInvalid (banner), Validation, RateLimited, Network |

Any new authenticated route MUST add its row here in the same PR that introduces it. `linter-scripts/check-surface-state-matrix.py` (Plan 06 Step 47) enforces parity with [`../21-app/16-ui-surfaces.md`](../21-app/16-ui-surfaces.md).

## 7. Accessibility

- Loading regions carry `aria-busy="true"` on the region root, with `role="status"` for non-blocking inline indicators. Blocking skeletons do not need `role="status"` because focus stays on the last user-triggered control.
- Error surfaces carry `role="alert"` for toasts and `role="status"` with `aria-live="polite"` for inline banners; the full-page error route uses a landmark `main` with the headline as `<h1>` and focus moved to it on mount.
- Empty surfaces are plain content; they carry no ARIA role. The primary action, when present, is a normal `<button>` reachable in tab order.
- Every state MUST be reachable and dismissable with keyboard alone. Focus rings per [`08-token-registry.md`](./08-token-registry.md) §9 are always visible.

## 8. Motion

- Loading skeletons use `skeleton-pulse` per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §7. Duration 1600 ms, `ease-in-out`. Suppressed under `prefers-reduced-motion`.
- Error and empty surfaces do not animate on mount by default. When wrapped in a page transition, they inherit the route-transition recipe from [`11-shape-and-motion.md`](./11-shape-and-motion.md) §5.
- Toasts use the `toast` recipe from [`11-shape-and-motion.md`](./11-shape-and-motion.md) §4.

## 9. Acceptance

- AC-EEL-001: Every `ErrorCode` in [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md) appears in the §4.2 taxonomy. Drift fails `linter-scripts/check-error-surface-parity.py` (Plan 06 Step 47).
- AC-EEL-002: Every authenticated route in [`13-navigation-ia.md`](./13-navigation-ia.md) §§5-8 appears as a row in §6. Drift fails `linter-scripts/check-surface-state-matrix.py`.
- AC-EEL-003: A component that catches an exception and returns `null` (silent failure) is a spec violation. Detected by ESLint rule `no-silent-catch` (Plan 06 Step 44).
- AC-EEL-004: Every error surface renders a RequestId chip; the chip renders `no-request-id` when absent per §4.5.
- AC-EEL-005: Skeleton row counts match §3.1 exactly; deviations require a normative amendment here.
- AC-EEL-006: Empty states never use the `--destructive` color role and error states never use the primary CTA color for their icon.
- AC-EEL-007: A non-blocking refetch never blanks the surface (no skeleton swap on stale-while-revalidate). Verified by the visual regression suite (Plan 06 Step 48).
- AC-EEL-008: Loading, empty, and error surfaces all pass axe-core with zero critical violations. Verified by the a11y test suite (Plan 06 Step 45).
- AC-EEL-009: The `EndUser.Update` surface has no empty state row; it is always populated because the endpoint returns a manifest even when no update is available (per [`../21-app/17-self-update-endpoint.md`](../21-app/17-self-update-endpoint.md) §NoUpdate response).
- AC-EEL-010: `NotFound` renders a calm interstitial per §4.4, not a red failure surface; the surface's icon color is `var(--muted-foreground)`, not `var(--destructive)`.
