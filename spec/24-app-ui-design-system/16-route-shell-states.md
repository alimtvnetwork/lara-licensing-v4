# Route Shell States

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for the content, focus behavior, telemetry, and exit vectors of the four route-shell-state pages: `/forbidden`, `/not-found`, `/error` (route-level boundary), and `/loading` (route pending boundary). Per-surface blueprints (Plan 06 Steps 21-28) MUST cite this file rather than draft their own shell-state page bodies. TanStack `errorComponent`, `notFoundComponent`, `pendingComponent`, and root-level `defaultErrorComponent` implementations MUST render the components defined here.
**Related:** [`12-shell-layout.md`](./12-shell-layout.md) §2 (shell variants), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md).

---

## 1. Scope

Four page-level states exist as their own routable surfaces or route boundaries:

| State | Trigger | Shell variant | Route |
|-------|---------|---------------|-------|
| Forbidden | `AuthzRoleDenied` or `AuthzPermissionDenied` from a page loader, or client-side detection of `me.Role` mismatch per v0.141.0 identity gate | `shell-app` if authenticated, `shell-public` otherwise | `/forbidden` |
| NotFound | Unmatched URL under any prefix, OR row-scope 404 from a page loader per [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §4 | `shell-app` if authenticated, `shell-public` otherwise | `/not-found` (also root `notFoundComponent`) |
| Error | Any thrown error from a route loader or `useSuspenseQuery` that reaches `errorComponent` | `shell-error` | `errorComponent` at route + `defaultErrorComponent` at router |
| Loading | Route resolution pending (loader running with no cached data) | Whatever shell the target route uses | `pendingComponent` at route |

Toast-level and inline error surfaces are out of scope here; they are owned by [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2.

## 2. Common contract

All four states share the following contract; deviations require a normative amendment.

### 2.1 Layout

- Rendered as a single centered card in the `main` region of the applicable shell. Card `max-inline-size: 480px`, block-padding `--space-8`, inline-padding `--space-6`.
- The card is vertically centered within `main` via `place-content: center` on `main`.
- The card carries `role="region"` with `aria-labelledby` bound to the headline `<h1>`.
- Background is `var(--background)`; card surface is `var(--card)` with border `1px solid var(--border)` and `--radius-lg` per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §1.
- No shadow (elevation-0) per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §2.

### 2.2 Composition (top to bottom)

1. **Icon** (24x24, colored per state, see §3-§6).
2. **Headline**: exactly one `<h1>` per [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md) §5. Typography role `Heading/2` per [`09-typography-scale.md`](./09-typography-scale.md) §3.
3. **Body**: one paragraph, Body role, max 200 chars.
4. **Action row**: primary action first, optional secondary. Buttons follow the Button contract (Plan 06 Step 13). Primary action MUST be reachable via `Enter` on mount (see §2.4 focus).
5. **RequestId chip** (Error and Forbidden only): monospace pill at the card footer, `text-align: end`, showing the last 12 chars of `X-Request-Id` or `no-request-id` per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.5. Click copies the full value.

### 2.3 Sidebar and topbar visibility

- Forbidden and NotFound render **inside** `shell-app` when the caller is authenticated; sidebar and topbar remain interactive so the user can navigate elsewhere. Route-active state falls to no item (no `aria-current="page"` on any sidebar item).
- Error uses `shell-error` (no sidebar, no topbar) because the shell chrome depends on data (`me.Role`, active portal, badges) that may itself be the failure. Rendering the shell would risk secondary errors.
- Loading inherits the target shell but skeleton-fills sidebar badges per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3.1 rather than blanking them.

### 2.4 Focus and keyboard

- On mount, focus MUST move to the headline `<h1>` (which carries `tabindex="-1"`). This announces the state to screen readers and provides a keyboard-reachable anchor.
- Primary action is the second tab stop; secondary action the third; RequestId chip the fourth.
- `Escape` on Forbidden and NotFound performs the primary action if it is a navigation (returns focus to the landed route's first focusable element). `Escape` on Error retries.

### 2.5 Telemetry (always fires, never swallowed)

Every state emits a client log line via the client logger at the level below with these fields (per [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md)):

| State | LogLevel | Event | Additional fields |
|-------|:--------:|-------|-------------------|
| Forbidden | warn | `RouteForbidden` | `Route`, `AttemptedPermissionKey` (if known), `UserId`, `RequestId` |
| NotFound | info | `RouteNotFound` | `Route`, `AttemptedPath`, `UserId` (nullable), `RequestId` (nullable) |
| Error | error | `RouteError` | `Route`, `ErrorCode` (from envelope; `UnknownServerError` if none), `RequestId`, `Message` (sanitized), `Stack` (dev builds only) |
| Loading | debug | `RoutePending` | `Route`, `ElapsedMs` (fired only if >600 ms per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3.1) |

`RouteError` MUST NOT include headers, cookies, or request bodies. Sanitized `Message` strips any string longer than 256 chars.

## 3. Forbidden (`/forbidden`)

### 3.1 Trigger classification

- **Role denied**: caller's `me.Role` set is disjoint from the route's allowed roles (e.g. Reseller hitting `/admin/*`). `AttemptedPermissionKey` is absent; log the endpoint-level role gate failure only.
- **Permission denied**: caller has the role but lacks the specific `PermissionKey`. `AttemptedPermissionKey` MUST be set.
- **Identity gate**: caller navigated to a per-tenant route (`/reseller/$resellerId/*`) with a foreign `$resellerId` per v0.141.0. Treat as permission denied with `AttemptedPermissionKey = Reseller.Overview.Read` and a distinct body copy noting "This portal belongs to another reseller."

### 3.2 Content

- **Icon**: `ShieldOff` colored `var(--muted-foreground)` (NOT destructive: forbidden is expected, not a failure).
- **Headline**: "You do not have access to this page."
- **Body**: "Your account does not include the permission required for this section. If you believe this is a mistake, contact your admin."
- **Primary action**: "Return to overview" -> the caller's portal landing route resolved from `me.Role` per [`13-navigation-ia.md`](./13-navigation-ia.md) §§5-8. Unauthenticated callers get "Sign in" -> `/auth/sign-in`.
- **Secondary action**: "Contact admin" -> `mailto:` link populated from `import.meta.env.VITE_SUPPORT_EMAIL`; hidden if the env var is empty.
- **RequestId chip**: shown.

### 3.3 Copy variants

The identity-gate case swaps body to: "This portal belongs to another reseller. Return to your own overview to continue." Everything else unchanged.

## 4. NotFound (`/not-found`)

### 4.1 Trigger classification

- **Unmatched URL**: TanStack Router root `notFoundComponent` fires. `RequestId` is `null`.
- **Row-scope 404**: a page loader returned `NotFound` because RLS filtered the row (per [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §4). `RequestId` is populated from the failed response.

### 4.2 Content

- **Icon**: `SearchX` colored `var(--muted-foreground)` per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.4 (calm, not destructive).
- **Headline**: "We could not find that page."
- **Body**: "The link may be outdated, or the record no longer exists. Head back to your overview to continue."
- **Primary action**: "Return to overview" -> caller's portal landing per §3.2, or `/` for anonymous.
- **Secondary action**: none.
- **RequestId chip**: shown only when `RequestId` is non-null; row-scope 404s therefore always show the chip, unmatched URLs never do.

### 4.3 Existence leak prevention

The body copy MUST be identical whether the record does not exist or exists but RLS hides it. Never render "You do not have access" copy on a 404 path: that would leak existence per [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §4.

## 5. Error (`errorComponent` and `defaultErrorComponent`)

### 5.1 Scope

Fires when any code path inside a route loader, `useSuspenseQuery`, or a rendered component throws. The nearest `errorComponent` handles it; `defaultErrorComponent` on the router catches everything else. Both MUST render the component defined here.

### 5.2 Content

- **Icon**: `AlertOctagon` colored `var(--destructive)`.
- **Headline**: "Something went wrong on our side."
- **Body**: "We could not complete this request. Try again in a moment. If it keeps happening, share the request ID with support."
- **Primary action**: "Try again" -> calls both `reset()` from the `errorComponent` props AND `router.invalidate()` per the TanStack error-boundary rule from `tanstack-errors-notfound`. Never `reset()` alone.
- **Secondary action**: "Return to overview" -> caller's portal landing or `/`.
- **RequestId chip**: shown; renders `no-request-id` for pure client-side throws.

### 5.3 Error taxonomy branching

If the caught error is an `ApiError` (per `src/lib/lara-api-error.ts`) and its `ErrorCode` maps to a non-server family (Auth, Authz, Validation, RateLimited, Conflict, etc.), the `errorComponent` MUST redirect rather than render this page:

| `ErrorCode` family | Redirect target |
|--------------------|-----------------|
| `AuthTokenInvalid`, `AuthTokenExpired`, `AuthRefreshRaceLost` | `/auth/sign-in` (2 s interstitial per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2) |
| `AuthzRoleDenied`, `AuthzPermissionDenied` | `/forbidden` |
| `NotFound` | `/not-found` |
| `RateLimited` | stay on target route with panel banner (`<RetryAfterBanner>`); do NOT hit the error boundary. Loaders MUST catch `RateLimited` and return a data shape carrying the countdown |
| `Validation`, `FeatureUnknown`, `FeatureValueInvalid`, `EnvironmentMismatch`, `Conflict`, `IdempotencyReplay`, `QuotaExhausted` | stay on target route; these are form or panel errors, never route errors. Loaders MUST NOT throw them |
| everything else (`UnknownServerError`, `InternalError`, `AuthSaltRotationFailed`, network) | render this page |

The classification decision is a switch on `error.code` inside `errorComponent`; the switch MUST be exhaustive and its default branch renders this page (fail-safe).

### 5.4 Boundary hygiene

- `errorComponent` MUST NOT call `useQuery` or any hook that can itself throw; the boundary must not re-throw into itself.
- `errorComponent` MUST NOT read `me` from a suspense query; a broken auth session would loop. Read `me` from a non-suspense hook that returns `null` on failure.
- The retry button MUST be `aria-busy="true"` while `router.invalidate()` is pending, per Button contract (Plan 06 Step 13).

## 6. Loading (`pendingComponent`)

### 6.1 When to render

Only when a route loader has no cached prior data AND resolution exceeds 150 ms per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3.2. Below 150 ms, `pendingComponent` MUST NOT render; TanStack Router's `pendingMs: 150` config on the router (or per-route override) enforces this. Setting `pendingMs: 0` is a spec violation.

### 6.2 Content

Loading is the only shell-state page that does NOT use the centered-card composition. It renders the target shell (`shell-app` or `shell-public`) with the target route's skeleton per the surface class in [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §6. Sidebar and topbar remain interactive.

The card composition is reserved for terminal states (Forbidden, NotFound, Error). Loading is transient and MUST NOT feel terminal.

### 6.3 Route-level vs component-level loading

- Route-level loading: `pendingComponent` renders the surface skeleton.
- Component-level loading (a specific `useSuspenseQuery` inside an already-rendered route): the component wraps itself in `<Suspense fallback={<SurfaceSkeleton />}>` per the same skeleton contract. This is not a route-shell state; it is documented here only to disambiguate.

## 7. Accessibility

- Every state uses one `<h1>` and moves focus to it on mount per §2.4.
- The centered card carries `role="region"` and `aria-labelledby={headlineId}`.
- Icons are decorative (`aria-hidden="true"`); the headline carries the semantic meaning.
- Color contrast for headline, body, and RequestId chip MUST meet WCAG AA (>=4.5:1) verified by axe-core (Plan 06 Step 45).
- Buttons follow the Button contract (Plan 06 Step 13) for size, focus ring, and disabled semantics.

## 8. Motion

- The centered card fades in with the route-transition recipe from [`11-shape-and-motion.md`](./11-shape-and-motion.md) §5 (opacity 0 -> 1, 200 ms `ease-out`). Skipped under `prefers-reduced-motion`.
- Focus movement to the headline uses a scroll-margin-block-start of `--space-8` to avoid the topbar clipping.
- No additional entrance animation on icon, body, or actions.

## 9. Acceptance

- AC-RSS-001: Every route file with a `loader` OR that renders a `useSuspenseQuery` sets both `errorComponent` and `notFoundComponent`; the router config sets `defaultErrorComponent` and `defaultNotFoundComponent`. Enforced by `linter-scripts/check-route-boundaries.py` (Plan 06 Step 43).
- AC-RSS-002: Every `errorComponent` renders the component defined in §5; drift fails a snapshot test in the visual regression suite (Plan 06 Step 44).
- AC-RSS-003: The switch in §5.3 is exhaustive over `ApiErrorCodeType`; the parity test at `tests/error-taxonomy-parity.test.ts` MUST assert that every `ErrorCode` maps to either a redirect target or the render-this-page default branch.
- AC-RSS-004: Focus lands on `<h1>` within one animation frame of mount; verified by the a11y test suite (Plan 06 Step 45).
- AC-RSS-005: The RequestId chip renders `no-request-id` (not empty, not "unknown", not "N/A") when `X-Request-Id` is absent; click still copies the literal string `no-request-id` to allow operators to grep client logs.
- AC-RSS-006: NotFound copy is identical for unmatched URLs and row-scope 404s; no branch of the notFound component reads authorization state to alter copy.
- AC-RSS-007: Error boundary MUST NOT call any hook that can throw; ESLint rule `no-throwing-hooks-in-error-boundary` (Plan 06 Step 46) enforces this.
- AC-RSS-008: `pendingMs` on the router config is >=150 for every route; `pendingMs: 0` fails `linter-scripts/check-router-config.py` (Plan 06 Step 43).
- AC-RSS-009: Every state emits its telemetry log line exactly once per mount; a client-side test asserts the log-line count with a fake logger.
- AC-RSS-010: `RouteError` log lines MUST NOT contain header, cookie, or body values; `linter-scripts/check-log-line-secrets.py` (Plan 06 Step 46) enforces this by scanning the client logger call sites.
