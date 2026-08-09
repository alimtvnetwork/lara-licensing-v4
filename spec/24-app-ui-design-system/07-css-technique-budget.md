# CSS Technique Budget

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`css-budget` · `native-css` · `tailwind-v4` · `cascade-layers` · `banned-techniques`

## 1. Purpose

Freeze the exact CSS surface every LaraLicensingV1 UI author (human or AI) may use. `06-fluid-design-foundations.md` §6/§7 sets the direction; this file is the normative list every component catalog, per-surface blueprint, and code review MUST cite.

## 2. Stack of Record

- Native CSS (Baseline 2025 + progressive enhancement).
- Tailwind v4 via Lightning CSS, wired through `src/styles.css` `@theme inline` and `@utility` blocks.
- shadcn/ui components as unstyled primitives; theming is enforced by tokens, not by rewriting component internals.
- `motion` (framer-motion) for orchestrated route/dialog transitions only. Component hover/press feedback stays in CSS.

Nothing else is on the shelf. If a design pattern cannot be built from this stack, escalate a spec amendment; do not import a new dependency.

## 3. Allowed Techniques (must be used where applicable)

### 3.1 Custom properties + cascade layers

- Every color, size, radius, motion, and shadow value ships as a `--token` inside `@layer tokens`.
- Layer order is frozen in `06-fluid-design-foundations.md` §4.
- Route-scoped overrides live in `@layer overrides` and carry an inline reason comment.

### 3.2 Color

- `oklch()` for token authoring.
- `color-mix(in oklab, var(--x) <pct>%, transparent)` for tints/hovers.
- `light-dark(<light>, <dark>)` on token definitions, driven by `:root[data-theme]`.
- No hex or rgb literals in components; components consume tokens only.

### 3.3 Fluid + responsive

- `clamp()` for type + space per `06-fluid-design-foundations.md` §3.
- Container queries (`@container`, `container-type: inline-size`, `container-name`) for component-scoped responsiveness.
- Media queries reserved for shell chrome (sidebar collapse, sheet trigger) and print.

### 3.4 Layout

- CSS Grid + `minmax()` + `subgrid` (where supported) for shell and tables.
- Logical properties (`margin-inline`, `padding-block`, `inset-inline-start`).
- `aspect-ratio` for media and chart tiles.
- `gap` for every flex/grid layout; no margin-based rhythm between siblings.

### 3.5 Selectors

- `:is()`, `:where()`, `:has()`, `:not()`.
- `:focus-visible` for every interactive element.
- `:user-invalid` for form validation styling (paired with server-side error state).
- `[data-state="..."]` attribute selectors bound to shadcn primitives and route state.

### 3.6 Motion

- Native CSS `transition` bound to `--motion-*` tokens for control feedback.
- `@starting-style` for popovers, dialogs, tooltips entry animation.
- `motion` (framer-motion) only for route/dialog orchestrations that cannot be expressed in CSS.
- All motion respects `prefers-reduced-motion: reduce` (collapse to 0ms, remove translate/scale keyframes).

### 3.7 User-preference queries

- `prefers-reduced-motion`, `prefers-contrast`, `forced-colors`, `prefers-color-scheme` (only as a fallback when the user has not chosen a theme).

### 3.8 Tailwind v4 usage

- Utility classes only when they resolve to tokens (`bg-background`, `text-foreground`, `border-border`).
- New utilities added via `@utility` inside `src/styles.css`, inside `@layer utilities`.
- Arbitrary values (`text-[13px]`, `bg-[#123456]`) are forbidden in components; if a value is missing, add a token first.

## 4. Banned Techniques (auto-reject in review)

- Runtime CSS-in-JS: styled-components, emotion, stitches runtime, JSS, `css` prop libraries.
- Utility frameworks other than Tailwind v4: UnoCSS, Windi, plain Bootstrap classes.
- Sass, Less, Stylus.
- Global stylesheets outside the frozen `@layer` order.
- Media queries inside component internals (must be container queries).
- Fixed `px` for type or vertical rhythm inside components; tokens only.
- Hardcoded hex/rgb color literals inside components (`text-white`, `bg-black`, `bg-[#111]`).
- Arbitrary Tailwind values in components (`bg-[oklch(...)]`, `p-[13px]`).
- `!important` outside `@layer overrides`, and even there only with an inline reason comment.
- Inline `style={{ color: '...' }}` for anything a token could express.
- Animation libraries other than the pinned `motion` (framer-motion). No GSAP, no anime.js, no Lottie for control feedback.
- Icon fonts. Use the pinned outline icon family only.
- Third-party UI kits beyond shadcn (no Material UI, no Chakra, no Ant Design, no Radix themes preset).

## 5. Third-Party Component Rules

- shadcn/ui primitives are consumed as-is; theme via tokens, not by patching primitive files.
- Any headless library added later (e.g. TanStack Table) must expose token-friendly hooks and never inject its own colors.
- Chart libraries must accept token-driven colors via CSS custom properties or explicit props. No baked-in palettes.

## 6. Progressive Enhancement Rules

- `:has()`, `@starting-style`, `subgrid`, `light-dark()` are used with graceful degradation: baseline layout must still be usable in engines that skip the rule.
- Container queries are baseline required (browsers without support are out of scope for the app; the public verify page still works because it uses `clamp` + grid, not `@container` internals).
- Never gate correctness on a progressive feature; only visual polish.

## 7. Enforcement

Every component review MUST answer:

- [ ] Which tokens does this component consume? List them.
- [ ] Any hardcoded values? If yes, refactor before merge.
- [ ] Any media query inside the component? If yes, convert to container query or move to shell chrome.
- [ ] Any banned library imported? If yes, remove.
- [ ] Reduced-motion path verified? Screenshot or note in the PR.
- [ ] Light + dark parity verified? Screenshots at both themes.

## 8. Verification

- AC-ADS-018: fluid clamps sourced from `06-fluid-design-foundations.md`.
- AC-ADS-019: no CSS-in-JS runtime.
- AC-ADS-020: container queries for component responsiveness.

```bash
python3 linter-scripts/check-spec-cross-links.py
python3 linter-scripts/check-forbidden-strings.py
```

## Cross-References

- [Visual Foundations](./01-visual-foundations.md)
- [Fluid Design Foundations](./06-fluid-design-foundations.md)
- [Core Theme Variable Architecture](../07-design-system/02-theme-variable-architecture.md)
