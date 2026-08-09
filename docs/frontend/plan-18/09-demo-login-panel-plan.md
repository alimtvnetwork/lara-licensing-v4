# Plan 18 · Step 9 · DemoLoginPanel FE Plan

Status: draft (produced by Plan 18 Step 9).

Depends on: `docs/backend/plan-18/08-demo-login-plan.md` (identity list,
claim envelope, env gate), `src/lib/runtime-mode.ts` (`isPreview()`,
`getRuntimeMode()`), `src/lib/demo-identities.ts` (to be created in
Step 46), `src/routes/admin.login.tsx` (mount point).

## 1. Purpose

Give a one-click sign-in surface for the three canonical demo identities
(`admin`, `reseller`, `portal`) when the app is running in `preview` or
`seed` mode. The panel MUST NOT ship into production bundles.

## 2. Component tree

```
<DemoLoginPanel />                        // src/components/auth/demo-login-panel.tsx
├── <DemoLoginPanelHeader />              // title + mode chip ("preview" / "seed")
├── <DemoIdentityList>                    // <ul role="list">
│   └── <DemoIdentityRow identity=… />    // one row per DemoIdentity
│       ├── <DemoIdentityAvatar />        // role glyph, no PII
│       ├── <DemoIdentityMeta />          // display name + role label
│       └── <DemoIdentitySignInButton />  // calls signInWithSeedIdentity
└── <DemoLoginPanelFooter />              // link to docs/testing/test-data.md
```

Files (created in Steps 61-70):

- `src/components/auth/demo-login-panel.tsx` (default export, named
  sub-components co-located to avoid an extra folder).
- `src/components/auth/demo-login-panel.test.tsx` (Vitest + RTL).
- `src/lib/demo-identities.ts` (single source of truth for the three
  identities, mirrored from `backend/app/Support/DemoIdentities.php`).
- `src/lib/sign-in-with-seed-identity.ts` (thin wrapper around
  `POST /Api/Auth/Login` that injects the demo password client-side and
  returns the same `LaraApiError` on failure).

## 3. Mount point

`src/routes/admin.login.tsx` renders `<DemoLoginPanel />` **below** the
credential form, inside the existing `surface-elevated` card, gated by:

```tsx
{shouldRenderDemoLoginPanel() ? <DemoLoginPanel /> : null}
```

`shouldRenderDemoLoginPanel()` lives in
`src/lib/demo-identities.ts` and returns `true` iff
`isPreview()` or `getRuntimeMode().Mode === "seed"`. No other route
imports the panel.

## 4. Mode gate

Two independent gates, both required:

1. **Runtime gate** (`shouldRenderDemoLoginPanel`): reads
   `getRuntimeMode()` and only renders in `preview` or `seed`.
2. **Build gate**: the panel module is imported via
   `React.lazy(() => import("@/components/auth/demo-login-panel"))`
   inside `admin.login.tsx`, so a tree-shaking pass (Rollup
   `sideEffects: false` on the module) drops it when the caller is
   never reached. The Step 15 linter
   (`check-preview-in-prod-bundle.py`) will grep the production Vite
   bundle for the marker string `DEMO_LOGIN_PANEL_MARKER` exported from
   the module and fail CI if it appears in a `prod` build artifact.

Marker constant (added in Step 61):

```ts
// src/components/auth/demo-login-panel.tsx
export const DEMO_LOGIN_PANEL_MARKER = "DEMO_LOGIN_PANEL_MARKER" as const;
```

## 5. Hotkey

`Shift+D Shift+D` (double-tap within 400 ms) focuses the panel and
highlights the first identity row. Implemented with a scoped
`useEffect` in `admin.login.tsx` that only registers the listener when
`shouldRenderDemoLoginPanel()` is `true`. No global hotkey provider.
Hotkey is documented in `docs/testing/test-data.md`.

## 6. Interaction contract

Each `<DemoIdentitySignInButton>`:

1. Calls `signInWithSeedIdentity(identity.email)`.
2. On success: navigates to the identity's landing route
   (`/admin` for admin, `/reseller` for reseller, `/portal` for portal)
   via `useNavigate()` from `@tanstack/react-router`.
3. On failure: surfaces the standard `LaraApiError` toast via the
   existing `toast.error(formatLaraApiError(err))` path. No bespoke
   error UI; the notification-center bridge from Step 12 will capture
   it.
4. Button is disabled while the mutation is in-flight; spinner uses
   the shared `<Spinner size="sm" />` primitive.

## 7. ARIA + keyboard

- Root element: `<section aria-labelledby="demo-login-panel-title">`.
- Heading: `<h2 id="demo-login-panel-title">Demo access</h2>`.
- Identity list: `role="list"`, rows are `role="listitem"`.
- Buttons carry `aria-label={\`Sign in as ${identity.displayName}\`}`
  and are reachable in DOM order (no `tabindex` overrides).
- Focus ring uses the existing `focus-visible:ring-brand-500` token.
- Hotkey does not steal focus from the credential form when the form
  is already focused (checked via `document.activeElement`).

## 8. Persistence policy

The panel writes nothing to `localStorage` / `sessionStorage`. The
auth session persists via the existing `auth-store` after
`signInWithSeedIdentity` resolves. No "remember this identity"
toggle: the goal is a deterministic one-tap re-login, not a picker.

## 9. Test plan (Step 128)

`src/components/auth/demo-login-panel.test.tsx` covers:

1. Renders three rows in `preview` mode.
2. Renders zero rows (returns `null`) in `prod` mode.
3. Clicking the admin row calls `signInWithSeedIdentity("admin@lara.local")`
   and navigates to `/admin` on success.
4. Surfaces `LaraApiError` via `toast.error` on failure and keeps the
   panel mounted.
5. Hotkey `Shift+D Shift+D` moves focus to the first row.
6. `DEMO_LOGIN_PANEL_MARKER` is exported (used by the Step 15 linter).

## 10. Linter rule

Step 15 extends `linter-scripts/check-preview-in-prod-bundle.py` to:

- Run `bun run build --mode production` into a temp dir.
- `rg -n DEMO_LOGIN_PANEL_MARKER dist/` and fail if any match is
  found. The `preview` build MUST still contain the marker (positive
  control) so the linter also fails if the marker is absent from a
  `preview` build, catching accidental deletion of the panel.

## 11. Out of scope

- OAuth / Google / Apple demo identities: not part of Plan 18.
- Custom identity picker with arbitrary emails: rejected in Step 8;
  the three canonical identities are the entire surface.
- Persisting the last-used identity: rejected (see §8).
