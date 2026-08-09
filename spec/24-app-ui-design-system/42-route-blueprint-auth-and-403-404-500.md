# Route Blueprint: Auth and Terminal States (`/auth/*`, `/403`, `/404`, `/500`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Tenth route blueprint; extends the template established by `33-`..`41-`. Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for authentication routes (sign-in, sign-out landing, OAuth callback, error) and application-wide terminal-state routes (403 / 404 / 500) that compose `16-route-shell-states.md`.
**Related:** [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`21-component-dialog.md`](./21-component-dialog.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`32-command-registry.md`](./32-command-registry.md), [`41-route-blueprint-enduser-me.md`](./41-route-blueprint-enduser-me.md), [`../21-app/02-authentication-jwt.md`](../21-app/02-authentication-jwt.md), [`../21-app/03-authentication-oauth.md`](../21-app/03-authentication-oauth.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/14-rate-limiting.md`](../21-app/14-rate-limiting.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/31-auth-session-family.md`](../21-app/31-auth-session-family.md), [`../21-app/32-auth-session-retention.md`](../21-app/32-auth-session-retention.md), [`../21-app/35-security-events.md`](../21-app/35-security-events.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

Authentication surface and application-wide terminal-state routes. These are the ONLY routes in the app that render OUTSIDE the `_authenticated` gate (except a shared skeleton in `__root.tsx`, classified Mode A route-shell per [`54-loading-state-catalog.md`](./54-loading-state-catalog.md) §2).

Routes:

- `/auth/sign-in` primary sign-in form (email + password, OAuth-provider Buttons per `03-authentication-oauth.md`).
- `/auth/signed-out` post-sign-out landing (referenced by `41-` §4 `Sign out everywhere...`).
- `/auth/callback` OAuth callback (server-only; server route not client route, see §2 note).
- `/auth/error` OAuth or session error surface with a specific ErrorCode from `12-error-taxonomy.md`.
- `/403` shared 403 terminal card.
- `/404` shared 404 terminal card.
- `/500` shared 500 terminal card.

Out of scope: password reset, magic-link enrolment, and passkey enrolment (deferred to a future `/auth/reset/*` sub-tree, §14). MFA challenge is IN scope but rendered as a Dialog on `/auth/sign-in` (not a separate route) to keep the flow linear.

## 2. Route wiring

| Route | File | Auth requirement | Loader |
|---|---|---|---|
| `/auth/sign-in` | `src/routes/auth/sign-in.tsx` | Unauthenticated only (redirect to `/` when a valid session exists) | `ensureQueryData(authProvidersQuery())` |
| `/auth/signed-out` | `src/routes/auth/signed-out.tsx` | None (terminal landing) | no loader |
| `/auth/callback` | `src/routes/api/public/auth/callback.ts` (server route) | None (verifies OAuth state param) | n/a (server route) |
| `/auth/error` | `src/routes/auth/error.tsx` | None | no loader; ErrorCode read from `validateSearch` |
| `/403` | `src/routes/403.tsx` | None | no loader |
| `/404` | `src/routes/404.tsx` | None (also mounted as `__root.tsx` `notFoundComponent`) | no loader |
| `/500` | `src/routes/500.tsx` | None (never linked from within the app; rendered by `router.defaultErrorComponent`) | no loader |

- `/auth/callback` is a SERVER route under `src/routes/api/public/*` per TanStack Start server-route conventions and the `Public API Endpoints` rule; the handler verifies the OAuth `state` param signature per `03-authentication-oauth.md` before setting the session cookie and redirecting to `/` OR to the URL from the pre-sign-in `RedirectTo` cookie (validated as a same-origin path, NEVER a full URL, to prevent open-redirect).
- `/auth/sign-in` MUST redirect to `/` when a valid session exists at load time (prevents re-sign-in as an oracle for session validity); loader checks session before rendering.
- `/403`, `/404`, `/500` compose the terminal cards from `16-route-shell-states.md` verbatim; the difference is that these are addressable routes (usable as `Link` targets from anywhere) whereas `16-`'s cards are inline states rendered inside route shells. A route that hits a permission failure MUST render the 403 card INLINE per `16-` §4, NOT redirect to `/403` (redirect BANNED because it hides the URL context and breaks back-navigation).
- Head metadata: sign-in `title = "Sign in - Lara Licensing"` + `<meta name="robots" content="noindex">`; signed-out `title = "Signed out - Lara Licensing"` + robots noindex; error `title = "Sign in error - Lara Licensing"` + robots noindex; 403 `title = "Access denied - Lara Licensing"` + robots noindex; 404 `title = "Not found - Lara Licensing"` + robots noindex; 500 `title = "Something went wrong - Lara Licensing"` + robots noindex. No `og:image` on any of these.

## 3. Layout

Sign-in:

```
Public shell (no Sidebar, no user chip, no Command Palette) > Centered card (max-width 420 px):
  Logo, H1 "Sign in", form (email Field, password Field, Sign in Button primary),
  divider "or continue with",
  OAuth provider Button list (server-driven from authProvidersQuery, no hard-coded provider list),
  footer Links "Need help?" (points to /auth/error with ErrorCode omitted rendering the generic help card).
```

Signed-out, error, 403, 404, 500 all render the public shell + a single centered terminal card composed from `16-route-shell-states.md` §3 with route-specific H1 and body copy.

## 4. Sign-in form contract

- Fields: `Email` (type=email, autocomplete=username, `aria-describedby` cites `/auth/error?ErrorCode=UnknownAccount` if applicable), `Password` (type=password, autocomplete=current-password, mandatory `Show password` Toggle per `18-component-input.md` §8).
- Sign-in Button submits via a `createServerFn` per `spec/21-app/02-authentication-jwt.md`; MUST use `Idempotency-Key` per `29-idempotency-lifecycle.md` (rapid double-submit resolves as one attempt).
- MFA challenge: if the server response includes `MfaRequired: true`, the same route swaps into an MFA Dialog per `21-component-dialog.md` (Dialog, NOT a separate route, to keep the flow linear). MFA code Field carries `autocomplete="one-time-code"` and `inputmode="numeric"` per `18-` §7.
- Rate limit: 5 attempts per email per 15 min per `14-rate-limiting.md`; on `429`, render inline `RetryAfterBanner` per `23-component-toast-banner.md` §5 with `aria-live="polite"` countdown and disable the Sign in Button until the countdown expires.
- Generic denial: on invalid credentials the server MUST respond with the SAME generic `AuthFailed` error message regardless of whether the email exists (per `03-authentication-oauth.md` non-enumeration rule); UI MUST NOT distinguish "email not found" from "wrong password". Different UX for those two cases BANNED per §12 anti-pattern 3.
- OAuth Buttons: each Button initiates a redirect to the provider with a server-generated signed `state` param per `03-` §4; the pre-sign-in URL (if the user was redirected to sign-in by `_authenticated`) is stored in a same-origin `RedirectTo` cookie set by the pre-sign-in redirect, NEVER in the `state` param (state param is signature material only; overloading it with a URL is BANNED).

## 5. Signed-out landing

- Copy H1: `Signed out.` Body: `You have been signed out on this device.` per `27-content-voice.md` §5 declarative-past voice.
- Primary action: `Sign in again` Link to `/auth/sign-in`.
- Secondary link: `Learn about session security` pointing to a docs URL (out-of-app link; opens in the SAME tab per `27-` §7 default-target rule; `_blank` opens only when the link is explicitly external content or downloadable).
- Route MUST clear the client-side session cache on mount (`queryClient.clear()` in a `useEffect` gated by `useHydrated()` per the TanStack execution model); this is the ONE place where `queryClient.clear()` is normative.

## 6. Error surface (`/auth/error`)

- Search params (`validateSearch`): `ErrorCode` (enum from `12-error-taxonomy.md` closed set; unknown code renders the generic `AuthFailed` card), `RequestId` (opaque short string, rendered on the card for the user to quote).
- Copy: H1 `We could not sign you in.` Body cites the specific ErrorCode phrase from `27-content-voice.md` §11 auth-error registry. `RequestId` rendered as a small monospaced string with a Copy Button.
- Primary action: `Try again` Link to `/auth/sign-in`. Secondary: `Contact support` if the ErrorCode is in the `RequiresSupport` subset per `27-` §11.
- ErrorCode VALUE from the URL MUST be sanitised (must match the closed-set enum) before rendering; free-text VALUES BANNED (XSS surface).

## 7. Terminal-state cards (403 / 404 / 500)

Compose `16-route-shell-states.md` §3 terminal card verbatim. Differences:

- 403: reads a `Reason` search param from the URL (matched to the closed-set registry in `12-error-taxonomy.md` §5) and cites the specific reason. Unknown Reason renders the generic `You do not have access to this resource.` per `27-` §5.
- 404: reads no params. Copy `We could not find that resource.` per `27-` §5. Renders a Search Command Link (`Ctrl K`) to help the user re-find their target.
- 500: reads a `RequestId` param and renders it monospaced with a Copy Button. Copy `Something went wrong on our side. We are looking into it.` per `27-` §5. The route file MUST include a component-level `ErrorBoundary` fallback in case the 500 route itself errors (defense-in-depth per `28-a11y-conformance.md` §7).

## 8. Data contract

- Query keys: `["Auth.Providers"]`. Everything else on these routes is component-local or read from URL params. NO `Me.*` or `Admin.*` queries fire on auth or terminal routes.
- Sign-in mutation invalidates `["Me.Overview"]` and `["Me.Sessions"]` (see `41-` §8) on success, then redirects.
- Sign-out landing mount calls `queryClient.clear()` per §5.
- `useSuspenseQuery` + `ensureQueryData` for `Auth.Providers`; `useQuery`+`isLoading` BANNED.

## 9. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link first tab stop.
- Sign-in form initial focus lands on the Email Field (not the Sign in Button) per `28-` §5 form-focus rule.
- MFA Dialog initial focus on the OTP Field.
- 403 / 404 / 500 cards initial focus on the primary action Button (e.g. `Try again` or `Go to home`) so keyboard users can recover immediately.
- `RetryAfterBanner` countdown `aria-live="polite"` per `23-` §5.
- All auth routes MUST NOT render the app Sidebar or Command Palette (public shell); tab order is Logo > Card content > Footer Links.

## 10. Telemetry

Per `22-log-line-contract.md`:
- `RoutePresented` with `RouteId: "Auth.SignIn" | "Auth.SignedOut" | "Auth.Error" | "Terminal.403" | "Terminal.404" | "Terminal.500"`, `A11yViolations: 0`, `LoadDurationMs`.
- `AuthSignInAttempted` with `EmailFingerprint` (SHA-256 8-char), `ProviderId` (empty for password), `IdempotencyKey`, `ErrorCode` (or `null` on success), `RequestId`, `MfaRequired` boolean. Email VALUE NEVER logged.
- `AuthSignInMfaSubmitted` with `EmailFingerprint`, `Result` (`Success`/`Failed`), `AttemptCount`. OTP VALUE NEVER logged.
- `AuthCallbackHandled` (server route) with `ProviderId`, `ErrorCode`, `RequestId`, `StateSignatureValid` boolean. State param VALUE NEVER logged.
- `AuthErrorViewed` with `ErrorCode`, `RequestId`.
- Terminal routes log `RoutePresented` only; no user action telemetry required.

## 11. Anti-patterns (BANNED)

1. Redirecting to `/403` on inline permission failures inside authenticated routes (must render the 403 card INLINE per `16-` §4 to preserve URL and back-navigation).
2. Distinguishing `email not found` from `wrong password` in the UI (must use the same generic `AuthFailed` message).
3. Storing the pre-sign-in `RedirectTo` URL inside the OAuth `state` param (state is signature material; RedirectTo lives in a same-origin cookie).
4. Accepting a full URL (not a same-origin path) in `RedirectTo` (open-redirect surface; validate as same-origin path only).
5. Rendering a distinct 403 vs 404 for cross-scope resource lookups (see `39-`, `40-`, `41-` scope-hiding rules; always `404` at scope boundary).
6. Optimistic sign-in mutation.
7. Missing `Idempotency-Key` on sign-in or MFA submit.
8. Free-text ErrorCode from URL rendered without closed-set match (XSS surface).
9. `queryClient.clear()` called anywhere except the signed-out landing mount.
10. Sidebar or Command Palette rendered on auth or terminal routes.
11. Logging Email / OTP / State param / RedirectTo VALUES.
12. Password Field WITHOUT a `Show password` Toggle (a11y requirement per `18-` §8).

## 12. Acceptance criteria

- AC-ROUTE-AUTH-001: `/auth/sign-in` redirects to `/` when a valid session exists at load time.
- AC-ROUTE-AUTH-002: Invalid credentials render the generic `AuthFailed` message regardless of whether the email exists (email VALUE not echoed).
- AC-ROUTE-AUTH-003: `RedirectTo` is a same-origin path only; a full URL in the cookie MUST be discarded server-side with a `RedirectToInvalid` warning log line and the caller sent to `/`.
- AC-ROUTE-AUTH-004: `/auth/callback` verifies the OAuth `state` param signature per `03-authentication-oauth.md` before setting the session cookie; invalid state logs `AuthCallbackHandled` with `StateSignatureValid: false` and redirects to `/auth/error?ErrorCode=OAuthStateInvalid`.
- AC-ROUTE-AUTH-005: 5-attempts-per-15-min rate limit surfaces `RetryAfterBanner` on `429` with `aria-live="polite"` countdown; Sign in Button disabled until expiry.
- AC-ROUTE-TERMINAL-001: `/403`, `/404`, `/500` render terminal cards with initial focus on the primary action Button; `noindex` meta present; no Sidebar or Command Palette rendered.
- AC-ROUTE-TERMINAL-002: 500 route file includes a component-level `ErrorBoundary` fallback for the case the 500 route itself errors.
- AC-ROUTE-TERMINAL-003: Free-text ErrorCode / Reason values from the URL are sanitised against the closed-set enum before rendering; unknown codes render the generic card.

## 13. Cross-route contracts

- Every `_authenticated` route that hits a permission failure MUST render the 403 card INLINE (composing `16-route-shell-states.md` §4), never redirect to `/403`; the addressable `/403` route is for direct links (email, support docs) only.
- Every route that throws an uncaught error surfaces via `router.defaultErrorComponent` per `tanstack-errors-notfound` rules; the default error component MUST cite `RequestId` and offer a Retry Button calling `router.invalidate()` AND `reset()` per `16-` §5.
- Sign-out from anywhere in the app routes through `POST /Auth/SignOut` server function then redirects to `/auth/signed-out`; direct `<Link to="/auth/signed-out">` without invalidating the server session BANNED.

## 14. Open items (for follow-up commits)

- Password reset / magic-link enrolment sub-tree (`/auth/reset/*`) deferred.
- Passkey enrolment surface deferred (see `41-` §14).
- Post-sign-in onboarding flow (accept TOS, choose default org when caller has multiple memberships) deferred; when built, MUST be a Dialog on `/`, not a separate route, to keep the sign-in path linear.
- SSO auto-provisioning surface deferred; when built, MUST route through the Admin queue pattern per `37-`.
