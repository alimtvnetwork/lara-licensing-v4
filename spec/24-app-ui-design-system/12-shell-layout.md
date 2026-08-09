# Shell Layout

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`shell` · `grid-layout` · `sidebar` · `topbar` · `named-grid-regions` · `breakpoints`

## 1. Purpose

Freeze the CSS Grid contract for every app shell (public, authenticated, verify). Per-actor navigation IA (Step 9) and per-surface blueprints (Steps 21-28) slot into the named regions defined here. `02-shell-and-navigation.md` remains the intent doc; this file is the geometry contract.

## 2. Shell Variants

| Shell | Route prefix | Uses grid |
|-------|--------------|-----------|
| Public | `/`, `/about`, `/pricing`, `/verify`, `/auth/*` | `shell-public` |
| Authenticated | `/_authenticated/*` (Admin, Reseller, AppBuilder, EndUser portals) | `shell-app` |
| Verify (end-user) | `/verify` and embed | `shell-verify` |
| Error | `/forbidden`, `/not-found` | `shell-error` |

Each variant is realized as a single top-level Grid; nested layouts never re-declare the shell grid.

## 3. Authenticated Shell (`shell-app`)

### 3.1 Named regions

```
grid-template-areas:
  "sidebar topbar"
  "sidebar main";
grid-template-columns: var(--shell-sidebar) 1fr;
grid-template-rows: var(--shell-topbar) 1fr;
min-block-size: 100dvh;
```

### 3.2 Fixed measures

| Custom property | Desktop (>=1024px) | Tablet (768-1023px) | Mobile (<768px) |
|-----------------|--------------------|---------------------|------------------|
| `--shell-sidebar` | `260px` | `260px` (as sheet trigger) | not rendered |
| `--shell-topbar` | `56px` | `56px` | `56px` |
| `--shell-content-max` | `1440px` | `1440px` | `100%` |
| `--shell-gutter-inline` | `--space-6` (24px) | `--space-4` (16px) | `--space-4` (16px) |
| `--shell-gutter-block` | `--space-6` (24px) | `--space-4` (16px) | `--space-4` (16px) |

Below `768px`:

- The `sidebar` grid column collapses; grid becomes single-column `"topbar" "main"`.
- Sidebar contents move into a `Sheet` opened from a `MenuIcon` button in `topbar`. Recipe D from `11-shape-and-motion.md` §4.4 owns the animation.
- Focus moves into the sheet on open; Escape closes; focus returns to the trigger.

### 3.3 Sidebar (`grid-area: sidebar`)

- Background: `var(--surface)`.
- Border inline-end: `1px solid var(--border)`.
- Padding-block: `--space-4`.
- Padding-inline: `--space-3`.
- Position: `sticky; inset-block-start: 0; block-size: 100dvh`; internal scroll `overflow-y: auto`.
- Contains: brand mark (top), primary nav list, secondary nav (settings, sign out) pinned to inline-end-bottom via `margin-block-start: auto`.
- No shadow. Elevation 0.

### 3.4 Topbar (`grid-area: topbar`)

- Background: `var(--background)`.
- Border block-end: `1px solid var(--border)`.
- Padding-inline: `--space-6` desktop, `--space-4` tablet/mobile.
- Height: `--shell-topbar` (56px).
- Contains, in order: mobile menu trigger (visible below 768px), breadcrumb region, spacer (`margin-inline-start: auto`), global search when the route enables it, theme toggle, account menu.
- No shadow. Elevation 0. Sticky: `position: sticky; inset-block-start: 0; z-index: 40`.

### 3.5 Main (`grid-area: main`)

Sub-grid contract:

```
display: grid;
grid-template-rows: auto auto 1fr;
grid-template-areas: "page-header" "page-actions" "page-content";
padding-inline: var(--shell-gutter-inline);
padding-block: var(--shell-gutter-block);
max-inline-size: var(--shell-content-max);
margin-inline: auto;
gap: var(--space-8);
```

- `page-header`: breadcrumb (visible on detail routes only), H1, optional one-sentence description. See `09-typography-scale.md` §4 for typography.
- `page-actions`: primary action inline-end (desktop) or below title (mobile), secondary via overflow menu. Height reserved even when empty to prevent layout shift.
- `page-content`: route body. Route sub-layouts create their own grid inside this region; they never re-open the shell grid.

## 4. Public Shell (`shell-public`)

### 4.1 Named regions

```
grid-template-areas:
  "header"
  "main"
  "footer";
grid-template-rows: var(--shell-topbar) 1fr auto;
min-block-size: 100dvh;
```

- `header`: wordmark, Verify link, Sign in, Create account.
- `main`: single centered column with `max-inline-size: 1080px` for landing, `480px` for auth forms.
- `footer`: legal + support links. Height `auto`.

Auth routes and `/verify` override `main` to a centered single-column form area with `max-inline-size: 480px`.

## 5. Verify Shell (`shell-verify`)

Same as `shell-public` with two changes:

- `main` uses `max-inline-size: 640px` to accommodate the serial input + result panel.
- Recipe C (dialog) enter is skipped; the result panel appears via Recipe B (attach-and-detach) so the layout does not jump.

## 6. Error Shell (`shell-error`)

- Same grid as `shell-public`.
- `main` is centered vertically with `place-items: center`.
- Content: canonical `ErrorCode`, human message, `RequestId` chip, single retry command, and a sign-in link when the underlying failure is auth-related.

## 7. z-index Register

Only the values below are permitted; no component uses `z-index` values outside this ladder.

| Layer | z-index |
|-------|---------|
| Content | `auto` |
| Sticky page toolbar | `10` |
| Sidebar (sticky) | `20` |
| Topbar (sticky) | `40` |
| Dropdown menus, popovers | `50` |
| Sheet | `60` |
| Dialog overlay | `70` |
| Dialog | `71` |
| Toast region | `90` |
| Command palette (future) | `100` |

## 8. Scroll Behavior

- The `main` region is the primary scroll container; `<html>` and `<body>` do not scroll.
- Sidebar has its own scroll container.
- Sticky topbar and page toolbars use `position: sticky` on `main`, not `fixed`.
- `scroll-margin-block-start: calc(var(--shell-topbar) + var(--space-4))` on route sections so anchor links do not slip under the topbar.

## 9. Container Queries

Route content declares one container:

```css
[data-page-content] {
  container-type: inline-size;
  container-name: lla-page;
}
```

Components inside opt into responsiveness via `@container lla-page (...)`. Media queries inside route content are banned per `07-css-technique-budget.md` §4.

## 10. Route-State Rendering (inside the shell)

- Loading: skeletons render inside `page-content` at the density of the destination component. Sidebar and topbar remain fully rendered.
- Error: replace `page-content` with the canonical error panel; keep sidebar and topbar intact so the user can navigate away.
- Forbidden: navigate to `/forbidden` and render `shell-error`; do NOT leave stale protected content visible.
- Empty: replace `page-content` with the empty-state block from `10-spacing-and-rhythm.md` §11.

## 11. Non-Goals

- No fixed footers in the authenticated shell.
- No full-bleed sections inside `page-content`; the `--shell-content-max` ceiling is absolute.
- No route-owned topbar; every route uses the shell topbar and injects breadcrumb/search via the shell's slots.
- No client-side layout persistence (collapsed sidebar state, etc.) until a follow-up doc defines it.

## 12. Verification

- AC-ADS-036: authenticated shell uses the `shell-app` grid with the five named regions in §3.
- AC-ADS-037: sidebar collapses to Sheet (Recipe D) below `768px`.
- AC-ADS-038: only `main` scrolls; `<html>`/`<body>` do not.
- AC-ADS-039: z-index values are drawn from §7.
- AC-ADS-040: page content declares the `lla-page` container.

```bash
python3 linter-scripts/check-spec-cross-links.py
```

## Cross-References

- [Shell and Navigation](./02-shell-and-navigation.md)
- [Token Registry](./08-token-registry.md)
- [Spacing and Rhythm](./10-spacing-and-rhythm.md)
- [Shape and Motion](./11-shape-and-motion.md)
- [Responsive and Accessibility](./04-responsive-and-accessibility.md)
- [UI Surfaces](../21-app/16-ui-surfaces.md)
