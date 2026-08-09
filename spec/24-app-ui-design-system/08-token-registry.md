# Token Registry

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`tokens` · `oklch` · `light-dark` · `semantic-color` · `type-scale` · `motion-tokens`

## 1. Purpose

Single source of truth for every design token consumed by components. `07-css-technique-budget.md` bans hardcoded values; this file defines the closed set that replaces them. `src/styles.css` must match this registry exactly; any drift is a runtime finding.

## 2. Registration Rules

- Every token is declared under `:root` (light) and `.dark` (dark) in `src/styles.css`, and exposed to Tailwind v4 via `@theme inline` inside `@layer tokens`.
- Color values authored in `oklch()`. No `#hex`, no `rgb()`, no HSL.
- Tints and overlays derived at consumption via `color-mix(in oklab, var(--token) <pct>%, transparent)`. Do not pre-bake extra alpha tokens.
- Naming: `--<role>` for semantic color, `--text-<role>` for type, `--space-<n>` for spacing, `--radius-<size>` for radius, `--motion-<name>` for motion, `--elevation-<n>` for shadow.
- Semantic role names never encode hue (never `--blue-primary`).
- Any token added here MUST be linked from `03-components-and-states.md` before use in a component.

## 3. Color Roles (Semantic)

| Token | Purpose | Light OKLCH | Dark OKLCH |
|-------|---------|-------------|------------|
| `--background` | Page canvas | `oklch(1 0 0)` | `oklch(0.129 0.042 264.695)` |
| `--foreground` | Primary text on canvas | `oklch(0.129 0.042 264.695)` | `oklch(0.984 0.003 247.858)` |
| `--surface` | Toolbars, table headers | `oklch(0.984 0.003 247.858)` | `oklch(0.208 0.042 265.755)` |
| `--surface-raised` | Cards, dialogs, popovers | `oklch(1 0 0)` | `oklch(0.245 0.042 265.755)` |
| `--muted` | Secondary surface | `oklch(0.968 0.007 247.896)` | `oklch(0.279 0.041 260.031)` |
| `--muted-foreground` | Secondary text | `oklch(0.554 0.046 257.417)` | `oklch(0.704 0.04 256.788)` |
| `--primary` | Main command state | `oklch(0.208 0.042 265.755)` | `oklch(0.929 0.013 255.508)` |
| `--primary-foreground` | Text on primary | `oklch(0.984 0.003 247.858)` | `oklch(0.208 0.042 265.755)` |
| `--accent` | Info emphasis | `oklch(0.968 0.007 247.896)` | `oklch(0.279 0.041 260.031)` |
| `--accent-foreground` | Text on accent | `oklch(0.208 0.042 265.755)` | `oklch(0.984 0.003 247.858)` |
| `--success` | Bound, verified, active | `oklch(0.62 0.15 150)` | `oklch(0.72 0.16 152)` |
| `--success-foreground` | Text on success surface | `oklch(0.129 0.042 264.695)` | `oklch(0.129 0.042 264.695)` |
| `--warning` | Expiring, rate-limited | `oklch(0.78 0.15 82)` | `oklch(0.82 0.16 84)` |
| `--warning-foreground` | Text on warning surface | `oklch(0.129 0.042 264.695)` | `oklch(0.129 0.042 264.695)` |
| `--destructive` | Revoked, blocked, failed | `oklch(0.577 0.245 27.325)` | `oklch(0.704 0.191 22.216)` |
| `--destructive-foreground` | Text on destructive surface | `oklch(0.984 0.003 247.858)` | `oklch(0.984 0.003 247.858)` |
| `--border` | Boundaries | `oklch(0.929 0.013 255.508)` | `oklch(1 0 0 / 10%)` |
| `--input` | Form-control boundary | `oklch(0.929 0.013 255.508)` | `oklch(1 0 0 / 15%)` |
| `--ring` | Focus ring | `oklch(0.704 0.04 256.788)` | `oklch(0.551 0.027 264.364)` |

Status tokens (`--success`, `--warning`, `--destructive`) MUST always ship with an icon or textual label; color alone never conveys state (AC-ADS-005).

### 3.1 Derived surfaces

- Hover: `color-mix(in oklab, var(--foreground) 6%, transparent)` over the current surface.
- Pressed: `color-mix(in oklab, var(--foreground) 10%, transparent)`.
- Selected row: `color-mix(in oklab, var(--primary) 12%, transparent)`.
- Disabled foreground: `color-mix(in oklab, var(--foreground) 45%, transparent)`.

## 4. Type Scale

Sourced from `01-visual-foundations.md` §2; every entry MUST be exposed as a CSS custom property in `@theme inline`.

| Token | Size | Line height | Weight | Fluid form |
|-------|------|-------------|--------|-----------|
| `--text-display` | `2.25rem` | `1.1` | `600` | `clamp(1.75rem, 1.25rem + 2.2vw, 2.25rem)` |
| `--text-title` | `1.5rem` | `1.25` | `600` | `clamp(1.25rem, 1.1rem + 0.9vw, 1.5rem)` |
| `--text-heading` | `1.125rem` | `1.35` | `600` | fixed |
| `--text-body` | `0.9375rem` | `1.5` | `400` | fixed |
| `--text-label` | `0.8125rem` | `1.4` | `500` | fixed |
| `--text-code` | `0.8125rem` | `1.5` | `500` | fixed |

Fluid tokens follow the canonical `clamp(min, min + (max - min) * ((100vw - 22.5rem) / (67.5)), max)` shape defined in `06-fluid-design-foundations.md` §3, with the vw values rounded to 1 decimal for readability.

## 5. Spacing

Base unit `4px`. Scale is closed.

| Token | Value |
|-------|-------|
| `--space-1` | `4px` |
| `--space-2` | `8px` |
| `--space-3` | `12px` |
| `--space-4` | `16px` |
| `--space-6` | `24px` |
| `--space-8` | `32px` |
| `--space-12` | `48px` |

Desktop shell gutter: `--space-6`. Mobile shell gutter: `--space-4`. Max content width: `1440px`.

## 6. Radius

| Token | Value | Use |
|-------|-------|-----|
| `--radius-sm` | `4px` | Inputs, checkboxes |
| `--radius-md` | `6px` | Buttons, badges |
| `--radius-lg` | `8px` | Cards, dialogs, table containers |
| `--radius-full` | `9999px` | Avatars, status dots |

Max component radius is `--radius-lg` (`01-visual-foundations.md` §4).

## 7. Elevation

Shadows are reserved for overlays.

| Token | Value |
|-------|-------|
| `--elevation-0` | `none` |
| `--elevation-1` | `0 1px 2px color-mix(in oklab, var(--foreground) 8%, transparent)` |
| `--elevation-2` | `0 8px 24px color-mix(in oklab, var(--foreground) 12%, transparent)` |

Popovers and dialogs use `--elevation-2`. Toolbars stay at `--elevation-0` and rely on borders.

## 8. Motion

| Token | Value | Use |
|-------|-------|-----|
| `--motion-instant` | `80ms` | Hover feedback, icon color changes |
| `--motion-fast` | `140ms` | Popovers, tooltips, dropdowns |
| `--motion-base` | `220ms` | Dialogs, sheets, route transitions |
| `--motion-ease-out` | `cubic-bezier(0.2, 0.8, 0.2, 1)` | Standard easing |
| `--motion-ease-in-out` | `cubic-bezier(0.4, 0, 0.2, 1)` | Symmetric transitions |

`prefers-reduced-motion: reduce` collapses all durations to `0ms` and disables translate/scale keyframes.

## 9. Focus

- Ring color: `var(--ring)`.
- Ring width: `2px`.
- Ring offset: `2px`, offset color equals the current surface.
- Applied via `:focus-visible` only. Never `:focus`.

## 10. Runtime Alignment

`src/styles.css` currently ships §3 tokens for `background`, `foreground`, `primary`, `accent`, `muted`, `destructive`, `border`, `input`, `ring`, `chart-1..5`, `sidebar*`. Tokens that are declared here but not yet in `src/styles.css` and therefore MUST be added in a follow-up runtime step:

- `--surface`, `--surface-raised`
- `--success`, `--success-foreground`
- `--warning`, `--warning-foreground`
- `--text-display`, `--text-title`, `--text-heading`, `--text-body`, `--text-label`, `--text-code`
- `--space-1..12`
- `--radius-sm`, `--radius-md`, `--radius-lg`, `--radius-full` (current file exposes a different radius scale; replacement is tracked)
- `--elevation-0..2`
- `--motion-instant`, `--motion-fast`, `--motion-base`, `--motion-ease-out`, `--motion-ease-in-out`

This gap is a known runtime finding recorded in `spec/25-app-audit/14-ui-surface-gaps.md` and will be closed by a Layer H step later in Plan 06. Until then, components MUST NOT reference tokens flagged above; they must fall back to the currently-shipped set.

## 11. Verification

- AC-ADS-021: color tokens authored in OKLCH only.
- AC-ADS-022: status meaning uses icon plus label, never color alone.
- AC-ADS-023: focus ring visible on every interactive element.
- AC-ADS-024: reduced-motion collapses `--motion-*` durations to `0ms`.

```bash
python3 linter-scripts/check-spec-cross-links.py
```

## Cross-References

- [Visual Foundations](./01-visual-foundations.md)
- [Fluid Design Foundations](./06-fluid-design-foundations.md)
- [CSS Technique Budget](./07-css-technique-budget.md)
- [Components and States](./03-components-and-states.md)
- [UI Surface Gaps](../25-app-audit/14-ui-surface-gaps.md)
