# Visual Foundations

**Version:** 0.23.0
**Updated:** 2026-07-15
**Status:** Active
**Category:** UI / Frontend

## 1. Direction

LaraLicensingV1 uses a precise operations-console aesthetic: high information density, restrained surfaces, strong status legibility, and minimal decoration. The interface must feel trustworthy to administrators while remaining approachable on public authentication and verification screens.

## 2. Typography

| Role | Family | Usage |
|------|--------|-------|
| UI and headings | IBM Plex Sans | Navigation, headings, labels, body text. |
| Identifiers | JetBrains Mono | Serials, hashes, request IDs, client IDs, and timestamps. |

Fonts load through `<link>` elements in `src/routes/__root.tsx`. The fallback stack is `system-ui, sans-serif` for UI and `ui-monospace, monospace` for identifiers. Letter spacing is always `0`.

| Token | Size | Line height | Weight |
|-------|------|-------------|--------|
| `--text-display` | 2.25rem | 1.1 | 600 |
| `--text-title` | 1.5rem | 1.25 | 600 |
| `--text-heading` | 1.125rem | 1.35 | 600 |
| `--text-body` | 0.9375rem | 1.5 | 400 |
| `--text-label` | 0.8125rem | 1.4 | 500 |
| `--text-code` | 0.8125rem | 1.5 | 500 |

Each route has one H1. Headings use solid foreground colors, never gradient text.

## 3. Semantic Color Roles

All values are OKLCH custom properties in `src/styles.css`, registered through Tailwind v4 `@theme inline`. Components consume semantic utilities only.

| Token | Purpose |
|-------|---------|
| `background`, `foreground` | Page canvas and primary text. |
| `surface`, `surface-raised` | Toolbars, tables, dialogs, and repeated item cards. |
| `primary` | Main command and current navigation state. |
| `accent` | Secondary emphasis and informational data. |
| `success` | Active, bound, verified, completed. |
| `warning` | Expiring, suspended, rate-limited. |
| `destructive` | Revoked, blocked, failed, destructive commands. |
| `muted`, `muted-foreground` | Secondary surfaces and supporting text. |
| `border`, `input`, `ring` | Boundaries, controls, focus indication. |

Status meaning never depends on color alone. Every status includes an icon or visible label.

## 4. Spacing and Shape

The spacing scale is 4, 8, 12, 16, 24, 32, and 48px. Dashboard content uses a maximum width of 1440px with 24px desktop gutters and 16px mobile gutters.

- Inputs, buttons, tables, and repeated item cards use a maximum 8px radius.
- Page sections are unframed and must not appear as floating cards.
- Cards are reserved for repeated records, KPI items, and dialogs.
- Cards must never be nested inside cards.
- Borders provide hierarchy before shadows. Shadows are reserved for overlays.

## 5. Iconography and Data

Use one outline icon family at 1.5px stroke. Familiar icon-only commands such as refresh, copy, reveal, rotate, and close require an accessible name and tooltip. Never abbreviate license state labels. Serial and hash values may truncate visually, but copy actions always copy the complete value.

## 6. Dark Theme

Light and dark themes have identical semantic roles and state contrast. Dark mode uses neutral surfaces rather than a blue-tinted monochrome palette. Theme changes must not alter component dimensions or chart meaning.

## Cross-References

- [Core design system](../07-design-system/00-overview.md)
- [UI surfaces](../21-app/16-ui-surfaces.md)
- [Component and state system](./03-components-and-states.md)