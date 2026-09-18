# Shape and Motion

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`radius` · `elevation` · `borders-before-shadows` · `motion-recipes` · `starting-style` · `reduced-motion`

## 1. Purpose

Bind the radius, elevation, and motion tokens from [`08-token-registry.md`](./08-token-registry.md) §6-§8 to explicit intent. Every rounded corner, shadow, and animated transition in the app resolves through this contract. No component invents its own radius, drops a bespoke shadow, or writes a keyframe outside the recipes below.

## 2. Radius Intent

| Token | Value | Applies to | Never applies to |
|-------|-------|------------|------------------|
| `--radius-sm` | 4px | Inputs, checkboxes, textareas, small numeric badges | Buttons, cards |
| `--radius-md` | 6px | Buttons, chips, status badges, segmented controls, tag pills | Table containers |
| `--radius-lg` | 8px | Cards, dialogs, sheets, popovers, table containers, KPI tiles | Inputs, chips |
| `--radius-full` | 9999px | Avatars, status dots, floating action icons | Any rectangular content surface |

Nested containers do NOT nest radii. A dialog uses `--radius-lg` at the outer border; inputs inside keep `--radius-sm`. `--radius-lg` is the ceiling: no component uses a larger radius.

## 3. Border and Elevation Policy

`01-visual-foundations.md` §4 rule: borders provide hierarchy before shadows. Restated:

1. Default separation between surfaces is a 1px border in `var(--border)`.
2. Shadows are reserved for surfaces that leave the document flow: dialogs, sheets, popovers, tooltips, dropdown menus, toasts.
3. Toolbars, tables, cards, KPI tiles, section headers stay at `--elevation-0`. Do not add a shadow to make them look "clickable"; use hover surfaces from `10-spacing-and-rhythm.md` §7.

| Surface | Border | Elevation |
|---------|--------|-----------|
| Card | 1px `--border` | `--elevation-0` |
| Table container | 1px `--border` | `--elevation-0` |
| KPI tile | 1px `--border` | `--elevation-0` |
| Popover | 1px `--border` | `--elevation-2` |
| Dropdown menu | 1px `--border` | `--elevation-2` |
| Tooltip | none | `--elevation-1` |
| Toast | 1px `--border` | `--elevation-2` |
| Dialog | 1px `--border` | `--elevation-2` |
| Sheet | 1px `--border` on inline-start edge | `--elevation-2` |

## 4. Motion Recipes

Every animated component MUST pick exactly one recipe from this catalog. Recipes name the duration, easing, entering keyframes, and exiting keyframes. All recipes collapse under `prefers-reduced-motion: reduce` per §7.

### 4.1 Recipe A: instant feedback

- Duration: `--motion-instant` (80ms)
- Easing: `--motion-ease-out`
- Property: `color`, `background-color`, `border-color`, `box-shadow`
- Applies to: hover/pressed states on buttons, links, table rows, list items, icon buttons.
- No transform. No opacity keyframe.

### 4.2 Recipe B: attach-and-detach (popover, dropdown, tooltip)

- Duration: `--motion-fast` (140ms)
- Easing: `--motion-ease-out` (enter), `--motion-ease-in-out` (exit)
- Enter: `@starting-style { opacity: 0; translate: 0 -4px }`, land at `opacity: 1; translate: 0 0`.
- Exit: same values in reverse; `data-state="closed"` drives the reverse via CSS variables.
- Applies to: popovers, dropdown menus, tooltips, combobox lists.

### 4.3 Recipe C: dialog

- Duration: `--motion-base` (220ms)
- Easing: `--motion-ease-out`
- Enter: overlay fades `opacity: 0 -> 1`; panel `@starting-style { opacity: 0; scale: 0.98 }` -> `opacity: 1; scale: 1`.
- Exit: reverse.
- No `translate` on the panel; scale change stays subtle to avoid layout shift.

### 4.4 Recipe D: sheet / drawer

- Duration: `--motion-base` (220ms)
- Easing: `--motion-ease-out`
- Enter: `translate: 100% 0` -> `translate: 0 0` for inline-end sheets; `-100% 0` -> `0 0` for inline-start sheets.
- Overlay fade concurrent.

### 4.5 Recipe E: toast

- Duration: `--motion-fast` (140ms) enter, `--motion-base` (220ms) auto-dismiss.
- Enter: `opacity: 0; translate: 0 8px` -> `opacity: 1; translate: 0 0`.
- Exit: `opacity: 1 -> 0`; `translate` returns to `0 8px` on the last 40% of the exit.

### 4.6 Recipe F: route transition

- Owner: `motion` (framer-motion) via a single `AnimatePresence` in `_authenticated` shell. No route file opts in independently.
- Duration: `--motion-base` (220ms)
- Enter: `opacity: 0` -> `opacity: 1`. No translate, no scale.
- Skeleton rows appear inside the new route immediately; the transition does not delay data loading.

### 4.7 Recipe G: skeleton pulse

- Duration: `1200ms` linear infinite (pulsing tokens, not one of the `--motion-*` presets because pulses are perceptual, not transitional).
- Property: `background-color` cycling between two `color-mix` mixes over `--muted`.
- Disabled entirely under `prefers-reduced-motion: reduce`; skeletons remain visible as static tinted blocks.

## 5. `@starting-style` Rules

- Use only for entering states of overlay surfaces (Recipes B, C, E).
- Never use for hover or focus states; those are governed by `transition`.
- Always paired with a matching `transition` on `display` (allow-discrete) so the exit animation runs before removal.

```css
.popover {
  transition:
    opacity var(--motion-fast) var(--motion-ease-out),
    translate var(--motion-fast) var(--motion-ease-out),
    display var(--motion-fast) allow-discrete;
}
```

## 6. Focus, Selection, and Loading Motion

- Focus ring never animates size; only opacity `0 -> 1` over `--motion-instant`.
- Row selection change: background-color transition over `--motion-instant`. No translate.
- Loading spinners: single stroke rotation `1000ms linear infinite`; disabled under reduced motion (swap for a static `Loader` icon with `aria-live="polite"` status text).

## 7. `prefers-reduced-motion: reduce`

When the media query matches:

- All `--motion-*` durations resolve to `0ms` via a scoped override in `@layer overrides`.
- `translate` and `scale` keyframes are dropped: elements enter and exit at their final geometry.
- Recipe G (skeleton pulse) turns off; skeleton blocks stay statically tinted.
- Toast auto-dismiss timer is UNCHANGED; motion off does not mean toast blindness.
- Route transitions (Recipe F) fade duration collapses to `0ms`; `AnimatePresence` still mounts/unmounts.

## 8. Non-Goals

- No parallax.
- No decorative background motion.
- No gradient animations.
- No cursor-following effects.
- No spring physics; all recipes use cubic-bezier easings from `08-token-registry.md` §8.
- No bespoke `@keyframes` outside the seven recipes above; if a new interaction needs motion, extend this doc first.

## 9. Verification

- AC-ADS-032: every animated component maps to exactly one recipe in §4.
- AC-ADS-033: shadows appear only on the surfaces in §3.
- AC-ADS-034: reduced-motion collapse verified per §7 with screenshots at both settings.
- AC-ADS-035: `@starting-style` used only for Recipes B, C, E.

```bash
python3 linter-scripts/check-spec-cross-links.py
```

## Cross-References

- [Visual Foundations](./01-visual-foundations.md)
- [Token Registry](./08-token-registry.md)
- [CSS Technique Budget](./07-css-technique-budget.md)
- [Spacing and Rhythm](./10-spacing-and-rhythm.md)
- [Components and States](./03-components-and-states.md)
