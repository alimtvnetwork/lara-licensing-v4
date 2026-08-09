---
Slug: 01-token-delta
Status: completed
Created: 2026-07-18
Parent: 07-ui-spec-conformance-and-code-finetune
---

# Token Delta Audit

Comparison of `src/styles.css` (v0.246.0) against `spec/24-app-ui-design-system/08-token-registry.md` (v0.24.0).

## Delta

### Color roles

| Token | Current | Required (light / dark) | Action |
|-------|---------|-------------------------|--------|
| `--background` | matches | `oklch(1 0 0)` / `oklch(0.129 0.042 264.695)` | ok |
| `--foreground` | matches | `oklch(0.129 0.042 264.695)` / `oklch(0.984 0.003 247.858)` | ok |
| `--surface` | MISSING | `oklch(0.984 0.003 247.858)` / `oklch(0.208 0.042 265.755)` | add |
| `--surface-raised` | MISSING | `oklch(1 0 0)` / `oklch(0.245 0.042 265.755)` | add |
| `--muted` | matches | as spec | ok |
| `--muted-foreground` | matches | as spec | ok |
| `--primary`, `--primary-foreground` | matches | as spec | ok |
| `--accent`, `--accent-foreground` | matches | as spec | ok |
| `--success` | MISSING | `oklch(0.62 0.15 150)` / `oklch(0.72 0.16 152)` | add |
| `--success-foreground` | MISSING | `oklch(0.129 0.042 264.695)` both | add |
| `--warning` | MISSING | `oklch(0.78 0.15 82)` / `oklch(0.82 0.16 84)` | add |
| `--warning-foreground` | MISSING | `oklch(0.129 0.042 264.695)` both | add |
| `--destructive`, `--destructive-foreground` | matches | as spec | ok |
| `--border`, `--input`, `--ring` | matches | as spec | ok |
| `--chart-1..5` | matches | as spec | ok |
| `--sidebar*` | present (shadcn) | not in registry but required by shadcn Sidebar | keep |

### Type scale (all MISSING, add)

| Token | Value | Fluid |
|-------|-------|-------|
| `--text-display` | `2.25rem` / lh `1.1` / weight `600` | `clamp(1.75rem, 1.25rem + 2.2vw, 2.25rem)` |
| `--text-title` | `1.5rem` / `1.25` / `600` | `clamp(1.25rem, 1.1rem + 0.9vw, 1.5rem)` |
| `--text-heading` | `1.125rem` / `1.35` / `600` | fixed |
| `--text-body` | `0.9375rem` / `1.5` / `400` | fixed |
| `--text-label` | `0.8125rem` / `1.4` / `500` | fixed |
| `--text-code` | `0.8125rem` / `1.5` / `500` | fixed |

### Spacing (all MISSING, add)

`--space-1..12` on 4px base: 4, 8, 12, 16, 24, 32, 48.

### Radius (rescale)

Current: `--radius: 0.625rem` (10px); derived `sm=6, md=8, lg=10, xl=14, 2xl=18, 3xl=22, 4xl=26`.
Spec: `--radius-sm: 4px`, `--radius-md: 6px`, `--radius-lg: 8px`, `--radius-full: 9999px`.
Action: rewrite `@theme inline` radius block to fixed spec values; keep `--radius-xl/2xl/3xl/4xl` derived from `--radius-lg` for shadcn continuity; add `--radius-full`.

### Elevation (all MISSING, add)

- `--elevation-0`: `none`.
- `--elevation-1`: `0 1px 2px color-mix(in oklab, var(--foreground) 8%, transparent)`.
- `--elevation-2`: `0 8px 24px color-mix(in oklab, var(--foreground) 12%, transparent)`.

### Motion (all MISSING, add)

- `--motion-instant`: `80ms`.
- `--motion-fast`: `140ms`.
- `--motion-base`: `220ms`.
- `--motion-ease-out`: `cubic-bezier(0.2, 0.8, 0.2, 1)`.
- `--motion-ease-in-out`: `cubic-bezier(0.4, 0, 0.2, 1)`.
- `@media (prefers-reduced-motion: reduce)` override collapses all three durations to `0ms`.

### Focus (behavioral, not new tokens)

Registry §9 requires `:focus-visible` ring at 2px with 2px offset colored to current surface. Enforced by a `@utility focus-ring` in the rewrite.

## Verification

- Every registry §3-§8 token accounted for above.
- Radius delta explicitly noted; action documented.
- All added tokens use OKLCH (AC-ADS-021).
