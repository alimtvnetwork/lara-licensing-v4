# Fluid Design Foundations

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`fluid-design` · `clamp` · `container-queries` · `cascade-layers` · `native-css`

## 1. Purpose

Freeze the fluid-design contract so every downstream file (tokens, typography, spacing, per-surface blueprints) inherits the same anchors and CSS technique budget. Fluid means: type and space interpolate smoothly across viewports via `clamp()`, and component layout adapts via container queries rather than media queries where possible.

## 2. Viewport Anchors

Fluid interpolation MUST use these anchors verbatim. No route may introduce new anchors without amending this file.

| Anchor | Width (px) | Role |
|--------|------------|------|
| `--vw-min` | 360 | Smallest supported phone (iPhone SE 3rd gen equivalent). |
| `--vw-sm` | 390 | Verification viewport (matches `04-responsive-and-accessibility.md` §7). |
| `--vw-md` | 768 | Tablet breakpoint, sidebar becomes sheet below. |
| `--vw-lg` | 1024 | Small desktop, sidebar collapsible. |
| `--vw-xl` | 1440 | Content max width, verification viewport. |
| `--vw-2xl` | 1920 | Upper clamp ceiling, no growth above. |

Clamp expressions interpolate between `--vw-min` (360) and `--vw-xl` (1440). Above 1440 values freeze at their max; below 360 values freeze at their min.

## 3. Fluid Formula

Canonical form for every fluid value:

```css
clamp(<min-rem>, <min-rem> + (<max-rem> - <min-rem>) * ((100vw - 360px) / (1440 - 360)), <max-rem>)
```

Concrete example (Body text, 14px → 15px):

```css
--text-body: clamp(0.875rem, 0.875rem + 0.0625 * ((100vw - 360px) / 1080), 0.9375rem);
```

All fluid tokens ship as CSS custom properties in `src/styles.css` inside the `tokens` cascade layer. Components MUST NOT re-derive clamp expressions inline.

## 4. Cascade Layers (frozen order)

`src/styles.css` MUST declare exactly this layer order at the top of the file, before any rule:

```css
@layer reset, tokens, base, components, utilities, overrides;
```

| Layer | Contents | Consumers |
|-------|----------|-----------|
| `reset` | Normalize, `*, *::before, *::after { box-sizing: border-box }`, focus-visible defaults. | Global. |
| `tokens` | Custom properties: colors, type scale, space scale, radii, motion, shadows. | Every layer below. |
| `base` | Element defaults (`html`, `body`, `a`, `button`, headings, form controls). | Global. |
| `components` | shadcn/ui, project components. | Route trees. |
| `utilities` | Tailwind v4 `@utility` blocks (fluid-space, container helpers). | Route trees. |
| `overrides` | Route-scoped last-resort corrections. Must document the reason inline. | Rare. |

Any rule outside these layers is a specification violation.

## 5. Container Queries First

Component-level responsiveness uses container queries. Media queries are reserved for shell chrome (sidebar collapse, sheet trigger) and print. Every reusable component that changes layout below a threshold MUST declare a container context.

```css
@layer components {
  .lla-table-shell {
    container-type: inline-size;
    container-name: table-shell;
  }
  @container table-shell (max-width: 640px) {
    .lla-table-shell [data-role="row"] { display: grid; }
  }
}
```

Container names use the prefix `lla-` (LaraLicensingV1) or a component-local name. Never rely on `min-width` media queries inside a component; use container queries.

## 6. Allowed Native CSS

Ship these techniques by default:

- CSS custom properties (`--token`) via `@layer tokens`.
- `oklch()` color literals; convert to `color-mix(in oklab, var(--x) 60%, transparent)` for tints.
- `light-dark(<light>, <dark>)` for theme-aware values driven by `:root[data-theme]`.
- Cascade layers (`@layer`).
- Container queries (`@container`, `container-type`, `container-name`).
- Logical properties (`margin-inline`, `padding-block`, `inset-inline-start`).
- Selectors: `:is()`, `:where()`, `:has()`, `:focus-visible`, `:user-invalid`.
- `@starting-style` for entry transitions of popovers/dialogs.
- `aspect-ratio`, `minmax()`, `subgrid` where supported.
- `prefers-reduced-motion`, `prefers-contrast`, `forced-colors`.

## 7. Banned Techniques

Never introduce:

- Runtime CSS-in-JS (styled-components, emotion, stitches runtime, JSS).
- Utility-first frameworks other than Tailwind v4 (no UnoCSS, no Windi).
- Global BEM stylesheets that bypass the layer order.
- Sass/Less. Native CSS + Tailwind v4 only.
- Media-query-driven component internals (component-scoped responsiveness = container queries).
- Fixed `px` values for type or vertical rhythm inside components (must consume tokens).
- Hardcoded hex/rgb color literals in components; every color resolves through the token layer.
- `!important`, except inside `overrides` layer with an inline reason comment.

Animation libraries: `motion` / `framer-motion` is permitted only for orchestrated route or dialog transitions. All hover/press feedback stays in native CSS transitions bound to motion tokens.

## 8. Fluid Type Preview (canonical steps)

Downstream `09-typography-scale.md` MUST use these exact clamp expressions.

| Token | Min (rem) | Max (rem) | Notes |
|-------|-----------|-----------|-------|
| `--text-display` | 1.75 | 2.25 | H1 only, ops shell never uses it. |
| `--text-title` | 1.25 | 1.5 | Route H1. |
| `--text-heading` | 1.0625 | 1.125 | Section H2. |
| `--text-body` | 0.875 | 0.9375 | Default body. |
| `--text-label` | 0.75 | 0.8125 | Form labels, chips. |
| `--text-code` | 0.75 | 0.8125 | Serials, hashes, request IDs. |

## 9. Fluid Space Scale

| Token | Min (rem) | Max (rem) | Purpose |
|-------|-----------|-----------|---------|
| `--space-2xs` | 0.25 | 0.25 | Icon padding. |
| `--space-xs` | 0.375 | 0.5 | Compact gaps. |
| `--space-sm` | 0.5 | 0.75 | Form field gap. |
| `--space-md` | 0.75 | 1 | Card padding. |
| `--space-lg` | 1 | 1.5 | Section gap. |
| `--space-xl` | 1.5 | 2 | Route gutters. |
| `--space-2xl` | 2 | 3 | Public shell hero band. |

Fixed values (no clamp): `--space-2xs`. All others fluid per §3.

## 10. Motion Budget (referenced from §4 shell)

| Token | Value | Use |
|-------|-------|-----|
| `--motion-fast` | 150ms | Buttons, inputs, toggles. |
| `--motion-medium` | 200ms | Popovers, tooltips, toasts. |
| `--motion-slow` | 250ms | Dialogs, sheets, route transitions. |
| `--ease-standard` | `cubic-bezier(0.2, 0, 0, 1)` | Enter. |
| `--ease-emphasized` | `cubic-bezier(0.3, 0, 0.1, 1)` | Emphasized enter. |
| `--ease-exit` | `cubic-bezier(0.4, 0, 1, 1)` | Exit. |

Under `prefers-reduced-motion: reduce`, all durations collapse to 0ms and translate/scale keyframes are removed. State-change opacity is retained.

## 11. Colored Examples (fluid interpolation preview)

```
Viewport 360px  |  --text-body = 14.00px  |  --space-md = 12.00px
Viewport 768px  |  --text-body = 14.38px  |  --space-md = 13.51px
Viewport 1024px |  --text-body = 14.62px  |  --space-md = 14.44px
Viewport 1440px |  --text-body = 15.00px  |  --space-md = 16.00px
Viewport 1920px |  --text-body = 15.00px  |  --space-md = 16.00px  (clamped)
```

## 12. Checklist for a New Component

- [ ] All colors sourced from `@layer tokens`.
- [ ] Vertical rhythm uses `--space-*` tokens only.
- [ ] Type sizes use `--text-*` tokens only.
- [ ] Component-scoped responsiveness uses container queries, not media queries.
- [ ] Motion durations use `--motion-*` tokens and respect `prefers-reduced-motion`.
- [ ] No runtime CSS-in-JS import.
- [ ] No `!important` outside `overrides` layer with a reason comment.
- [ ] Verified at 360, 768, 1440 viewport widths, light and dark themes.

## 13. Verification

- AC-ADS-018: every UI surface uses fluid `clamp()` type + spacing from this file.
- AC-ADS-019: no component ships CSS-in-JS runtime.
- AC-ADS-020: container queries drive component-level responsiveness for table, form, and card catalogs.

```bash
python3 linter-scripts/check-spec-cross-links.py
python3 linter-scripts/check-forbidden-strings.py
```

## Cross-References

- [Visual Foundations](./01-visual-foundations.md)
- [Responsive and Accessibility](./04-responsive-and-accessibility.md)
- [Team Mood and UX North Star](./05-team-mood-and-ux-north-star.md)
- [Core Design System, Theme Variables](../07-design-system/02-theme-variable-architecture.md)
- [Core Motion Transitions](../07-design-system/06-motion-transitions.md)
