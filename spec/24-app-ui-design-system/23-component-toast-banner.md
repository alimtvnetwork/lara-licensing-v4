# Component: Toast, Banner, RetryAfter live region

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for three ephemeral/feedback primitives: Toast (transient corner notification), Banner (in-surface persistent message), and RetryAfter live region (countdown chip owned by rate-limit responses). It formalizes the runtime already shipped in `src/hooks/use-lara-error-toast.ts` and `src/lib/use-retry-after-countdown.ts` (v0.77.0) and reconciles the toast and banner routing in [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md), [`11-shape-and-motion.md`](./11-shape-and-motion.md) §2 (elevation), §3 (attach-and-detach motion), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2 (surface routing), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`20-component-choice.md`](./20-component-choice.md) §7 (Switch failure toast), [`21-component-dialog.md`](./21-component-dialog.md) §5 (destructive Dialog error surface), §9 (toast stacking above Dialog), [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Purpose and non-purpose

| Primitive | Purpose | NOT for |
|-----------|---------|---------|
| Toast | Transient acknowledgement of an action's outcome. Auto-dismisses. Stacks in the viewport top-inline-end corner. | Persistent errors that block a surface (Banner); route-level errors (`/error`); form-level Validation summary (Form banner) |
| Banner | Persistent in-surface message about state that affects the surface as a whole (rate-limited, degraded read, dry-run mode). Does not auto-dismiss. | Field-level errors (Field's error slot); transient success (Toast); route-level errors |
| RetryAfterBanner | Specialization of Banner bound to a `429 RateLimited` response's `Retry-After` header, with a live countdown region and a re-enable event when the countdown reaches zero. | Any other error class; generic wait timers |

Toast, Banner, and RetryAfterBanner have DIFFERENT ARIA roles (§4). Never substitute one for another.

## 2. Toast

### 2.1 Variants

| Variant | ErrorCode families | Auto-dismiss | Icon | Copy tone |
|---------|--------------------|--------------|------|-----------|
| `success` | (none: opt-in for successful mutation outcomes) | 4 s | `CheckCircle2` | Neutral confirmation ("License revoked.") |
| `info` | (none) | 6 s | `Info` | Neutral status ("Signed out.") |
| `warning` | `Conflict`, `IdempotencyReplay` | 8 s | `AlertTriangle` | Actionable ("Another admin already revoked this license.") |
| `error` | `AuthRefreshRaceLost`, `UnknownServerError`, transient network | 10 s | `XCircle` | State-and-remedy ("Could not save. Try again.") |

### 2.2 Geometry

- Inline-size: `min(420px, 100vw - 2 * --space-4)`.
- Padding: `--space-3` block, `--space-4` inline.
- Border radius: `--radius-md`.
- Elevation: elevation-1 per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §2.
- Border-inline-start: 3 px accent color per variant (`--success`, `--info`, `--warning`, `--destructive`).
- Position: viewport top-inline-end, offset `--space-4` from top and inline-end edges, ABOVE any open Dialog/Sheet per [`21-component-dialog.md`](./21-component-dialog.md) §9.
- Stack: up to 3 toasts visible; older toasts collapse into a compact "N earlier" chip below the visible stack (the chip expands on click).

### 2.3 Motion

- Enter: `attach-and-detach` recipe, opacity 0 -> 1, translate inline `+16px` -> 0, 180 ms, `--ease-out-standard`.
- Exit: opacity 1 -> 0, translate inline 0 -> `+16px`, 140 ms.
- Reduced motion: fade only, 80 ms.

### 2.4 Content anatomy

```
<div role={ariaRole} aria-live={ariaLive} aria-atomic="true">
  <span class="toast-icon" aria-hidden />
  <div class="toast-body">
    <p class="toast-title">Title (Body/M, weight 500)</p>
    <p class="toast-description">Optional description (Body/S)</p>
    <p class="toast-request-id">Request ID: <Identifier>abc123</Identifier></p>  {/* error/warning only */}
  </div>
  <div class="toast-actions">
    <button>Action</button>  {/* max 1 */}
    <button aria-label="Dismiss">X</button>
  </div>
</div>
```

- `role` and `aria-live`: `success`/`info` use `role="status"` + `aria-live="polite"`; `warning`/`error` use `role="alert"` + `aria-live="assertive"`.
- Max ONE action Button per toast. If the outcome needs more actions, it belongs in a Banner or a Dialog.
- Action Button label uses the actual verb ("Retry", "Undo", "View details"). Never "OK".
- RequestId chip renders on `warning` and `error` variants only, using the `<Identifier>` component from [`09-typography-scale.md`](./09-typography-scale.md) §6, with `no-request-id` fallback text when the response envelope lacks one.

### 2.5 Behavior

- Auto-dismiss timer PAUSES on hover, on focus-within, and while any Toast in the stack has focus.
- Auto-dismiss timer RESETS on hover exit / focus-out.
- User-driven dismiss (X or Escape while focused): the Toast leaves immediately; siblings do not pause.
- Deduplication: two Toast calls with identical `variant`, `title`, `description`, and `errorCode` within 2 s collapse to one Toast with a "(x2)" suffix on the title; the timer resets on each collapse.
- Retry action semantics: a Toast's Retry Button MUST re-fire the original mutation with the SAME `Idempotency-Key` per [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md). Generating a new key on Retry is BANNED (breaks `IdempotencyReplay` guarantee).

### 2.6 Owner: `use-lara-error-toast.ts`

The hook in `src/hooks/use-lara-error-toast.ts` (shipped v0.77.0) is the ONLY entry point for surfacing an API error as a Toast. Rules:

- The hook receives an `ApiErrorEnvelope` (from `src/lib/lara-api-error.ts`), never a bare `Error`. Callers wrap unknown errors in `toApiErrorEnvelope(err)` upstream.
- The hook chooses variant per `ErrorCode` using the table in [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2 (only Toast-eligible codes: `Conflict`, `IdempotencyReplay`, transient network, `UnknownServerError`, `AuthRefreshRaceLost`). Any other code MUST NOT surface as a Toast; the hook throws (during dev) or logs a `ToastRoutingViolation` warning (in prod) and surfaces the error via the surface's Banner/inline slot instead. Enforced by `tests/toast-routing.test.ts` (Plan 06 Step 45).
- `RateLimited` is BANNED from Toast; it MUST route to a `<RetryAfterBanner>` per §4.
- `Validation`, `AuthzRoleDenied`, `AuthzPermissionDenied`, `NotFound`, `AuthTokenExpired`, `AuthTokenInvalid`, `AuthSaltRotationFailed`, `EnvironmentMismatch`, `QuotaExhausted`, `FeatureUnknown`, `FeatureValueInvalid` are BANNED from Toast; they route to Banner or inline field per taxonomy.

## 3. Banner

### 3.1 Variants

| Variant | Purpose | Icon |
|---------|---------|------|
| `info` | Announce a mode ("You are viewing production data.", "Dry-run mode."). | `Info` |
| `success` | Confirm a persistent state achievement inside a surface (rare; usually Toast is right). | `CheckCircle2` |
| `warning` | Announce a degraded read or a soft policy warning ("Some rows hidden due to permissions."). | `AlertTriangle` |
| `error` | Persistent error affecting the whole surface (`Validation` with no `FieldErrors`, `Conflict` blocking the form, `UnknownServerError` in a Sheet). | `XCircle` |

### 3.2 Geometry

- Inline-size: 100% of the surface content region (respects shell gutters, does not extend into shell chrome).
- Padding: `--space-3` block, `--space-4` inline.
- Border radius: `--radius-md`.
- Border: 1 px solid variant accent color; background `color-mix(in oklab, <accent> 8%, var(--background))`.
- No elevation (banners are flush with the surface).
- Position: inline-start of the surface content, above the primary content region. In a Form, above the field list. In a Dialog/Sheet, top of the body (below the sticky header).

### 3.3 Content anatomy

```
<div role={ariaRole} aria-live={ariaLive}>
  <span class="banner-icon" aria-hidden />
  <div class="banner-body">
    <p class="banner-title">Title (Body/M, weight 500)</p>
    <p class="banner-description">Description (Body/S), including RequestId chip when errorCode present.</p>
  </div>
  <div class="banner-actions">
    <button>Action 1</button>  {/* max 2 */}
    <button>Action 2</button>
    <button aria-label="Dismiss">X</button>  {/* info/warning only, optional */}
  </div>
</div>
```

- `role`/`aria-live`: `info`/`success` use `role="status"` + `aria-live="polite"`; `warning`/`error` use `role="alert"` + `aria-live="assertive"`.
- Max TWO action Buttons.
- Dismiss X: allowed on `info` and `warning`; BANNED on `error` and `success` (an error banner represents a persistent block; a success banner is rare and MUST be dismissed by resolving the state).
- Banners do NOT auto-dismiss.

### 3.4 Persistence

- Route-scoped banners persist while the route is mounted.
- Surface-scoped banners (inside a Dialog/Sheet) persist while the container is open.
- Form-level Validation banners persist until the next successful submit OR until every input value that contributed to the error has changed.
- Dismissed `info`/`warning` banners MAY persist their dismissed state in `sessionStorage` per surface if the banner is a recurring mode announcement (e.g. dry-run); this is opt-in per banner instance, not default.

## 4. RetryAfterBanner (rate-limit specialization)

The runtime lives in `src/lib/use-retry-after-countdown.ts` (v0.77.0). This section defines its contract; the runtime MUST match.

### 4.1 Trigger

Every `429 RateLimited` response with a `Retry-After` header MUST render a `<RetryAfterBanner>` on the surface that issued the request. Toast is BANNED (§2.6). Inline field error is BANNED. Dialog error surface (§5 destructive Dialog) MAY host a `<RetryAfterBanner>` at the top of its body.

### 4.2 Geometry

Inherits Banner geometry (§3.2) with variant `warning` (border and icon color `--warning`).

### 4.3 Live countdown

- Countdown value derives from `Retry-After` seconds (delta-seconds only; HTTP-date form is normalized to delta-seconds at parse).
- Countdown renders as `"Try again in {n} seconds"` where `{n}` decrements once per second.
- The countdown lives in a `<span role="timer" aria-live="off">`. Screen readers announce the initial state via the parent Banner's `aria-live="assertive"`; per-second announcements are BANNED (aria-live off) to prevent screen-reader spam.
- At 5 seconds remaining, the countdown copy switches to `"Try again in {n}s"` (short form) to reduce visual noise.
- At 0, the countdown region emits a `retry-after:ready` event; the surface's retry Button re-enables and MAY optionally auto-focus (per surface).

### 4.4 Retry semantics

- The retry Button uses the SAME `Idempotency-Key` as the original request per [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md).
- Auto-retry on countdown expiry is BANNED. The user MUST click retry (or interact with the surface's primary action) to resend. Rationale: `Retry-After` is a MINIMUM, not a schedule; the user's next attempt is what proves the limit lifted.
- If the retry hits `429` again, a new `<RetryAfterBanner>` replaces the previous one with the fresh `Retry-After` value; the same Idempotency-Key persists across retries.

### 4.5 Cleanup

- Unmounting the surface clears the countdown interval (unit-tested).
- A successful non-429 response on the same surface removes the Banner and clears the interval.

## 5. Log lines

- Toast surfaces emit `ToastPresented` at LogLevel=info on mount with `Variant`, `Title`, `ErrorCode?`, `RequestId?`. Dismissal emits `ToastDismissed` at LogLevel=info with `Cause` (`auto`, `user-x`, `user-escape`, `route-change`, `retry-action`).
- Banner surfaces emit `BannerPresented` at LogLevel=info on mount with `Variant`, `Title`, `ErrorCode?`, `RequestId?`. Dismissal emits `BannerDismissed` with `Cause`.
- RetryAfterBanner emits `RateLimitEncountered` at LogLevel=warn on mount with `ErrorCode="RateLimited"`, `RequestId`, `RetryAfterSeconds`. On countdown expiry, emits `RateLimitReady` at LogLevel=info with `RequestId`. Retry click emits `RetryAttempted` at LogLevel=info with `RequestId` (previous) and `IdempotencyKey`.
- Routing violations (§2.6) emit `ToastRoutingViolation` at LogLevel=warn with `ErrorCode` and stack.

## 6. Content copy rules

- No exclamation points. No "!".
- No "Oops" or "Uh oh".
- State the outcome or state; suggest a next action when one is available.
- Second person for actions.
- Sentence case for titles.
- Hide internal system names (no "PostgreSQL error").
- i18n-ready per [`09-typography-scale.md`](./09-typography-scale.md) §8: no runtime string concatenation; interpolation uses named placeholders.

## 7. Anti-patterns (forbidden)

- Toast for `RateLimited` (must be RetryAfterBanner).
- Toast for `Validation`, `AuthzRoleDenied`, `AuthzPermissionDenied`, `NotFound`, or feature/env/quota errors.
- Toast with more than one action Button.
- Banner that auto-dismisses.
- Dismissible `error` or `success` Banner.
- Retry with a fresh `Idempotency-Key`.
- Auto-retry when the RetryAfter countdown expires.
- Per-second screen-reader announcement of the countdown.
- Toast rendered inside a Dialog/Sheet container (must stack in viewport corner).
- Banner rendered outside the surface content region (must respect shell gutters).
- More than 3 visible Toasts (older ones collapse to a chip).
- Logging `IdempotencyKey` values without the corresponding `RequestId`.

## 8. Acceptance

- AC-TST-001: `use-lara-error-toast.ts` refuses non-Toast-eligible `ErrorCode` values; `tests/toast-routing.test.ts` asserts the eligible set (`Conflict`, `IdempotencyReplay`, transient network, `UnknownServerError`, `AuthRefreshRaceLost`) and asserts every other code throws in dev / logs `ToastRoutingViolation` in prod.
- AC-TST-002: Toast retry re-uses the original `Idempotency-Key`; verified by a unit test with a fake client.
- AC-TST-003: `role`/`aria-live` matches variant per §2.4; snapshot test asserts.
- AC-TST-004: Auto-dismiss timer pauses on hover/focus and resets on exit; verified by a Playwright test.
- AC-TST-005: Deduplication collapses identical Toasts within 2 s with `(xN)` suffix; verified by a unit test.
- AC-TST-006: Max 3 visible Toasts; older ones collapse to a `N earlier` chip. Verified by a component test.
- AC-BAN-001: Error/success Banners are non-dismissible; a dismiss X on either fails a component test.
- AC-BAN-002: Banner respects shell gutters; a Banner rendered outside the surface content region fails a visual regression test.
- AC-BAN-003: Form-level Validation Banner clears when every contributing field value changes; verified by a unit test.
- AC-RAB-001: `RateLimited` responses render `<RetryAfterBanner>` on the issuing surface; a Toast for `RateLimited` fails `tests/toast-routing.test.ts`.
- AC-RAB-002: Countdown region is `role="timer" aria-live="off"`; snapshot asserts.
- AC-RAB-003: Auto-retry on expiry is BANNED; a test that mounts a `<RetryAfterBanner>` with 1 s timeout asserts no mutation fires until the user clicks retry.
- AC-RAB-004: Retry uses the SAME `Idempotency-Key`; a unit test with a fake client asserts header equality across attempts.
- AC-RAB-005: Interval cleared on unmount; a leak test asserts no interval survives unmount.
- AC-LOG-001: Every Toast/Banner/RateLimit event emits its normative log line per §5; verified with a fake logger.
