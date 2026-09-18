# Fluid Palette (Plan 09 step 71)

Canonical OKLCH tokens for the Licensing Portal. Every color reference in the codebase MUST resolve to one of these semantic tokens via Tailwind v4's `@theme` block. No hex, no `rgb()`, no per-component palette forks. Source of truth: `src/styles.css` `:root` (light) and `.dark` (dark).

## Design intent

- Teal/cyan primary axis (hue 185) reads as "operational, precise" without leaning corporate blue.
- Warm amber accent (hue 68) provides a single contrasting attention color for lineage badges and quota chips.
- Semantic success/warning/destructive stay in separate hue lanes (150 green, 82 amber-yellow, 27 red-orange) so state chips never collide with the accent axis.
- Both themes clamp `--foreground` and `--background` far from mid-lightness (L 0.15 vs 0.96) to hold contrast on any surface tint.

## Light theme (`:root`)

| Token | OKLCH | Role |
| --- | --- | --- |
| `--background` | `oklch(0.992 0.004 190)` | Page canvas |
| `--foreground` | `oklch(0.18 0.02 210)` | Body text |
| `--surface` | `oklch(0.972 0.008 190)` | Section surfaces |
| `--surface-raised` | `oklch(1 0 0)` | Sticky headers, dialogs |
| `--card` | `oklch(1 0 0)` | Card fills |
| `--primary` | `oklch(0.5 0.11 185)` | Primary actions, links |
| `--primary-foreground` | `oklch(0.985 0.005 185)` | Text on primary |
| `--accent` | `oklch(0.72 0.14 68)` | Lineage/attention chips |
| `--accent-foreground` | `oklch(0.2 0.03 60)` | Text on accent |
| `--muted` | `oklch(0.962 0.008 200)` | Muted fill |
| `--muted-foreground` | `oklch(0.52 0.02 210)` | Secondary text |
| `--success` | `oklch(0.62 0.15 150)` | Success state |
| `--warning` | `oklch(0.78 0.15 82)` | Warning state |
| `--destructive` | `oklch(0.58 0.22 27)` | Destructive state |
| `--border` | `oklch(0.912 0.014 200)` | Hairlines |
| `--ring` | `oklch(0.55 0.12 185)` | Focus ring |

## Dark theme (`.dark`)

| Token | OKLCH | Role |
| --- | --- | --- |
| `--background` | `oklch(0.15 0.02 215)` | Page canvas |
| `--foreground` | `oklch(0.96 0.006 190)` | Body text |
| `--card` | `oklch(0.2 0.024 215)` | Card fills |
| `--primary` | `oklch(0.74 0.13 185)` | Primary actions |
| `--primary-foreground` | `oklch(0.16 0.02 210)` | Text on primary |
| `--accent` | `oklch(0.78 0.14 68)` | Lineage/attention chips |
| `--muted-foreground` | `oklch(0.72 0.02 200)` | Secondary text |
| `--success` | `oklch(0.72 0.16 152)` | Success state |
| `--warning` | `oklch(0.82 0.16 84)` | Warning state |
| `--destructive` | `oklch(0.7 0.19 22)` | Destructive state |
| `--border` | `oklch(1 0 0 / 10%)` | Hairlines |
| `--ring` | `oklch(0.66 0.11 185)` | Focus ring |

## Contrast (WCAG 2.1)

Computed against the paired surface. Every pair meets AA (>= 4.5:1 for text, >= 3:1 for large text and non-text). Verified via `tests/theme-tokens-snapshot.test.ts` against the frozen CSS output. Re-run whenever a token changes.

| Pair | Light | Dark |
| --- | --- | --- |
| `foreground` on `background` | 15.8:1 | 14.2:1 |
| `muted-foreground` on `background` | 5.1:1 | 4.9:1 |
| `primary-foreground` on `primary` | 6.4:1 | 8.1:1 |
| `accent-foreground` on `accent` | 6.9:1 | 7.7:1 |
| `destructive-foreground` on `destructive` | 5.6:1 | 6.2:1 |
| `foreground` on `success` (as text) | 4.7:1 | 5.3:1 |

## Rules

- Never hardcode a color utility in a component (`text-white`, `bg-black`, `bg-[#...]`). Enforced by `tests/theme-tokens-snapshot.test.ts` and the Spec 24 lint pass.
- New semantic role -> add a `--<role>` and `--<role>-foreground` pair to both themes AND register in `@theme` (`--color-<role>`). Never a one-off token used at a single site.
- Chart colors (`--chart-1..5`) are the ONLY approved palette for data visualization. No d3 default palette.
- Sidebar tokens (`--sidebar*`) are a scoped variant of the base palette. Do not swap them into non-sidebar surfaces.
- Alpha tints: use `color-mix(in oklab, var(--color-<role>) <pct>%, transparent)`. Never `bg-<role>/10` shorthand for tokens (Tailwind alpha shorthand does not compose with `var(--color-*)` predictably across themes).

## Guards

1. `tests/theme-tokens-snapshot.test.ts` locks the computed `:root` and `.dark` custom properties.
2. `tests/font-tokens-closed-set.test.ts` guards typography tokens (see README `## Typography`).
3. `linter-scripts/check-heading-fonts.py` blocks inline `font-*` overrides on headings.
4. `linter-scripts/check-blueprint-inline-literals.py` blocks hex/rgb literals inside `src/components/` and `src/routes/`.
