# Responsive Matrix

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** Single normative source for how the shell, tables, forms, dialogs, cards, menus, toasts, and banners behave at the six anchor viewports (360, 390, 768, 1024, 1440, 1920 CSS px) and how container queries drive component-level layout independent of viewport. Every future per-surface blueprint (`29-per-surface-blueprints/*`) MUST cite the row it targets.
**Related:** [`06-fluid-design-foundations.md`](./06-fluid-design-foundations.md), [`07-css-technique-budget.md`](./07-css-technique-budget.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md), [`12-shell-layout.md`](./12-shell-layout.md), [`13-navigation-ia.md`](./13-navigation-ia.md), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md).

---

## 1. Anchor viewports

| Anchor | CSS px | Represents |
|--------|--------|-----------|
| XS | 360 | Small phone portrait (Android baseline). |
| SM | 390 | iPhone portrait. |
| MD | 768 | Tablet portrait. |
| LG | 1024 | Tablet landscape / small laptop. |
| XL | 1440 | Standard laptop / desktop. |
| 2XL | 1920 | Large desktop / operator workstation. |

Support range is 360..1920. Below 360 the UI MUST NOT crash but visual quality is best-effort; above 1920 the shell caps at `--container-app: 1440px` centered per `12-shell-layout.md`.

## 2. Query strategy

- Viewport `@media` queries are used ONLY for shell chrome (Sidebar collapse, TopBar density, safe-area padding). Content-region layout MUST use container queries against the Main region's inline-size.
- Container-query breakpoints (component-level) are declared once per component and re-cited here for cross-component consistency:
  - `--cq-record-card`: `< 720px` inline-size triggers Table -> RecordCard fallback per `24-component-table.md` §11.
  - `--cq-two-col-form`: `>= 640px` inline-size triggers two-column Field grid per `18-component-input.md`.
  - `--cq-dialog-wide`: `>= 560px` inline-size triggers Dialog inline-content two-column layout.
- Viewport-only Tailwind breakpoints for content are BANNED; a component sized down inside a Sheet must respond to its own container, not the viewport.

## 3. Shell behavior per anchor

| Anchor | Sidebar | TopBar | Main padding-inline | Right rail |
|--------|---------|--------|---------------------|------------|
| XS 360 | Off-canvas Sheet, trigger in TopBar | 48 px, icon-only nav triggers | `--space-4` (16 px) | Hidden |
| SM 390 | Off-canvas Sheet | 48 px | `--space-4` | Hidden |
| MD 768 | Collapsed rail (56 px icon-only, labels on focus/hover) | 56 px | `--space-6` (24 px) | Hidden |
| LG 1024 | Collapsed rail | 56 px | `--space-6` | Hidden |
| XL 1440 | Expanded (240 px, labels visible) | 56 px | `--space-8` (32 px) | 320 px optional per route |
| 2XL 1920 | Expanded, shell centered at max `--container-app` | 56 px | `--space-8` | 320 px optional per route |

Focus order across breakpoints is stable: skip-link -> primary nav -> TopBar utilities -> `<main>` heading -> content. The off-canvas Sheet variant of the Sidebar MUST insert its focus trap on open per `21-component-dialog.md` §4 (Sheet is a Dialog variant).

## 4. Component behavior matrix

Each cell states the observable rule. Runtime implementations MUST match this table, not their own defaults.

| Component | XS 360 | SM 390 | MD 768 | LG 1024 | XL 1440 | 2XL 1920 |
|-----------|--------|--------|--------|---------|---------|----------|
| PageHeader (`14`) | Title wraps to 2 lines max; PrimaryBtn full-width below title | Same as XS | Title on one line; PrimaryBtn inline-end | Same as MD | Same as MD | Same as MD |
| Breadcrumb (`14`) | Collapse to `... > Current` (leading segments in a Menu) | Same as XS | Show up to 3 segments, collapse older to `...` Menu | Show up to 5 segments | Show all | Show all |
| Button (`17`) | Full-width primary in forms; icon-only tolerable in dense toolbars if 24 × 24 min | Same | Auto-width primary | Same as MD | Same | Same |
| Input (`18`) | One column, labels above inputs | Same | Two-column via `--cq-two-col-form` when Main inline-size ≥ 640 | Same | Same | Same |
| Select (`19`) listbox | Bottom-anchored sheet (`role="listbox"` inside `role="dialog"` Sheet), full-width | Same | Popover anchored to trigger, min-inline 240 px | Same | Same | Same |
| Choice (`20`) group | Stack vertically | Same | Stack vertically; use two columns only if `18` two-col rule fires | Same | Same | Same |
| Dialog (`21`) | Bottom Sheet (`role="dialog"`, drag-handle disabled), 100 vw × auto height up to 92 vh | Same as XS | Modal Dialog centered, min-inline 480 px, max-inline 640 px | Same as MD | Same | Same, but shell backdrop remains stable when Main is centered |
| Sheet (`21`) | Bottom Sheet, 92 vh max | Same | Right-anchored Sheet, inline-size min(560px, 100vw - 2 * --space-4) | Same as MD | Same | Same |
| Menu (`22`) | Anchored Popover; if trigger is in a table row, opens as bottom Sheet listing menuitems | Same | Anchored Popover only | Same | Same | Same |
| Popover (`22`) | Anchored inline; if content > 60 vh, promotes to Sheet | Same | Anchored inline, no promotion | Same | Same | Same |
| Toast (`23`) | Full-width top-inline banner (`min(100vw - 2*--space-4, 420px)`), stack downward, max 2 visible | Same | Top-inline-end corner stack, max 3 visible | Same | Same | Same |
| Banner (`23`) | Flush with Main gutters | Same | Same | Same | Same | Same |
| RetryAfterBanner (`23`) | Same as Banner | Same | Same | Same | Same | Same |
| Table (`24`) | RecordCard fallback (container `< 720`) | RecordCard fallback | Table if Main ≥ 720 else RecordCard | Table | Table | Table |
| Badge (`25`) | Height stays `24 px`; wrap policy unchanged | Same | Same | Same | Same | Same |
| Icon (`26`) | Sizes from `--icon-*` scale; no viewport-based resizing | Same | Same | Same | Same | Same |

## 5. Reflow and zoom (SC 1.4.4, 1.4.10, per `28-a11y-conformance.md` §2.3)

- At 320 CSS px width every surface reflows without horizontal scroll except `<table>` primitives inside a scroll container. The Table's RecordCard fallback satisfies this above 320 and below 720.
- 400% page zoom on XL 1440 MUST behave like the XS 360 breakpoint (viewport CSS px scales, container queries follow suit); no additional rules needed if §3 and §4 hold.
- 200% text-only zoom MUST NOT clip labels; the text-spacing tolerance in `28-a11y-conformance.md` §2.4 covers this.

## 6. Safe-area, notches, on-screen keyboards

- Root `<body>` sets `padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left)` on XS / SM.
- Bottom-sheet Dialog and Sheet MUST reserve `env(safe-area-inset-bottom)` in their footer padding so the primary Button is never obscured by the home indicator.
- On-screen keyboard: forms in a bottom-Sheet Dialog MUST scroll the focused Field into view (`scroll-margin-block: var(--space-8)`). Fixed Dialog footers MUST re-anchor to `visualViewport.height` on `visualViewport` `resize` events (progressive enhancement; the CSS reserve above is the baseline).

## 7. Orientation

- Portrait and landscape are both supported at every anchor. No orientation-locked surfaces.
- Landscape phones (viewport height < 480 px, width ≥ 640 px) MUST use the MD layout for content and the XS Sidebar (off-canvas) for chrome; the Table remains a Table (not RecordCard) provided Main inline-size ≥ 720.

## 8. Density (v1 default: comfortable)

v1 ships ONE density (comfortable). Compact and spacious variants are OUT OF SCOPE for v1. Density-switching UI is BANNED; if a route requires denser data, use the RecordCard fallback or paginate.

## 9. Performance budgets per anchor

| Metric | XS/SM | MD/LG | XL/2XL |
|--------|-------|-------|--------|
| Route JS transfer (compressed) | ≤ 180 KB | ≤ 220 KB | ≤ 260 KB |
| Route CSS transfer (compressed) | ≤ 40 KB | ≤ 60 KB | ≤ 80 KB |
| Time to interactive on cable | ≤ 2.5 s | ≤ 2.0 s | ≤ 1.8 s |
| Largest Contentful Paint | ≤ 2.5 s | ≤ 2.0 s | ≤ 1.8 s |

Budgets are per-route, measured on a fresh cold load with an authenticated session. Breaching the budget REQUIRES a spec amendment; silent regression is BANNED.

## 10. Testing

- Playwright viewport matrix (future `tests/e2e/responsive.spec.ts`) MUST snapshot each authenticated route at the six anchors.
- Container-query behavior MUST be tested by resizing a Main-region wrapper independent of the viewport (jsdom cannot express container queries; a Playwright-driven browser is required).
- Screenshots MUST be stored under `/tmp/browser/responsive/<route>/<anchor>.png` during CI and diffed against baselines committed under `tests/e2e/__snapshots__/responsive/`.

## 11. Anti-patterns

1. Viewport `@media` queries used to lay out content inside Main.
2. `width: 100vw` (ignores scrollbars; use `100%`).
3. Hiding a primary action below a fold at any anchor.
4. Different navigation IA on mobile vs desktop (nav labels / order MUST match `13-navigation-ia.md`).
5. Density toggle in v1.
6. Orientation lock.
7. Rendering a Table below 720 px inline-size instead of the RecordCard fallback.
8. Fixed pixel widths on Dialog / Sheet content (breaks reflow).
9. Toast stack blocking the TopBar at XS/SM.
10. Bottom-Sheet footer without safe-area reserve.
11. Route JS transfer above the anchor budget without an amendment.
12. Different focus order across anchors for the same route.

## 12. Acceptance criteria

- AC-RES-001: Every route reflows at 320 CSS px width with no horizontal scroll outside a Table's scroll container.
- AC-RES-002: Table renders RecordCard fallback whenever its container inline-size is `< 720 px`, regardless of viewport width.
- AC-RES-003: Sidebar is off-canvas Sheet at XS/SM, collapsed rail at MD/LG, expanded at XL/2XL, matching §3.
- AC-RES-004: Dialog is a bottom Sheet at XS/SM and a centered modal at ≥ MD, matching §4.
- AC-RES-005: Toast stack renders at most 2 items on XS/SM and at most 3 on ≥ MD, matching §4 and `23-component-toast-banner.md` §2.
- AC-RES-006: Bottom-Sheet Dialog and Sheet reserve `env(safe-area-inset-bottom)` in the footer.
- AC-RES-007: Focus order across the six anchors is identical for the same route.
- AC-RES-008: Route JS transfer stays within the §9 budget per anchor; enforced by a future `check-bundle-budget.py` reading `dist/` output.

## 13. Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- Manual: each row of §4 has a matching rule in the component's own spec file, and every future blueprint cites this file's row it targets.
