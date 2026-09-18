# Iconography and Assets

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** Single normative source for icon family, sizing, accessible-name rules, and non-icon asset policy. Every design-system component (`17`..`25`) that references a Lucide glyph binds to this file.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`11-shape-and-motion.md`](./11-shape-and-motion.md), [`17-component-button.md`](./17-component-button.md), [`19-component-select.md`](./19-component-select.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`25-component-badge-status.md`](./25-component-badge-status.md).

---

## 1. Family and provenance

- Single icon family: **Lucide** (outline, 1.5 px stroke, 24 px source viewBox). Mixing icon families is BANNED.
- Consumed via `lucide-react`. Custom SVG glyphs are permitted ONLY when Lucide has no near-equivalent AND the glyph is registered in §6. Ad hoc inline SVG in feature code is BANNED.
- Emoji as UI icons is BANNED (breaks tone, non-monochrome, platform-variant rendering). Emoji in user-supplied content is allowed as data.

## 2. Sizing

| Token | Size | Where |
|-------|------|-------|
| `--icon-xs` | 14 px | Inside Badge (`25-component-badge-status.md` §3), inside compact chip. |
| `--icon-sm` | 16 px | Default inline icon in Body text, Menu items, Toast/Banner leading icon. |
| `--icon-md` | 20 px | Button leading/trailing icon, Input adornment, Select chevron. |
| `--icon-lg` | 24 px | Nav rail primary items, empty-state hero, Dialog header icon. |
| `--icon-xl` | 32 px | Route-shell terminal-state (403/404/500) hero. |

Sizes outside this scale are BANNED. Icon size MUST NOT be set via arbitrary Tailwind classes; use the token or the component's built-in slot.

## 3. Color

- Icons inherit `currentColor` by default. Standalone color props (`stroke="#..."`, hex classes) are BANNED.
- Semantic icons in Badge/Toast/Banner inherit tone from the surrounding primitive; do not override.
- Decorative icons in dense text MUST resolve to `--fg-muted`; never full `--fg`.
- Filled variants are BANNED (family consistency). If emphasis is required, change the enclosing surface tone, not the stroke weight or fill.

## 4. Accessibility (accessible-name rule)

Every icon renders in exactly one of three modes:

1. **Decorative** (icon next to a visible text label): `aria-hidden="true"`. The label carries the name.
2. **Icon-only interactive** (icon Button in a toolbar or row overflow): the enclosing `<button>` MUST set `aria-label` with a verb-first name (`aria-label="Open filters"`, not `"Filters"`). The `<svg>` remains `aria-hidden="true"`.
3. **Icon-only meaningful non-interactive** (extremely rare): `<svg role="img" aria-label="...">`. Prefer adding a visually-hidden `<span class="sr-only">` label on the parent instead.

`title` attribute on `<svg>` as a tooltip fallback is BANNED (`17-component-button.md` §8 no-tooltip-on-disabled corollary).

## 5. Naming and selection rules

- Pick the icon that most directly names the object or action; metaphor stretch is BANNED (`Rocket` for "deploy release" is fine; `Rocket` for "renew license" is not).
- Reuse the same glyph across surfaces for the same concept. The canonical map:

| Concept | Icon |
|---------|------|
| Search | `Search` |
| Filter | `SlidersHorizontal` |
| Refresh / retry | `RefreshCw` |
| Copy to clipboard | `Copy` |
| More actions / overflow | `MoreHorizontal` |
| Close | `X` |
| Success | `CheckCircle2` |
| Info | `Info` |
| Warning | `AlertTriangle` |
| Error | `XCircle` |
| Rate limited | `Timer` |
| External link | `ArrowUpRight` |
| Download | `Download` |
| Upload | `Upload` |
| Add / create | `Plus` |
| Edit | `Pencil` |
| Revoke / ban | `Ban` |
| Expired | `CalendarX2` |
| Environment | `Boxes` |
| Feature | `ToggleRight` |
| Quota | `Gauge` |

Surface-specific mappings (LicenseState, SerialState, etc.) live in `25-component-badge-status.md` §4 and MUST NOT be duplicated here.

## 6. Custom SVG registry

Custom (non-Lucide) SVG assets, if any, MUST be listed here with source, viewBox, and consumers. v1 registry: **empty**. Adding a custom asset REQUIRES a same-commit update to this table AND a matching `src/assets/icons/<name>.svg` file with `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">` scaffold.

| Name | Source | ViewBox | Consumers |
|------|--------|---------|-----------|
| (none) | | | |

## 7. Non-icon assets

- **Illustrations:** BANNED in v1. Empty states use one large Lucide icon (`--icon-xl`) plus text per `15-empty-error-loading-catalog.md`. Illustrations may be reconsidered at v2 with a dedicated spec.
- **Product logo / wordmark:** single SVG at `src/assets/brand/lara-mark.svg`; MUST render at `--space-8` (32 px) in the shell TopBar and `--icon-xl` (32 px) in Dialog headers. No PNG raster.
- **Favicons / social preview images:** managed at the route head level; not covered by this spec.
- **User-supplied media** (avatars, product images): out of scope for v1 (no such surface exists).

## 8. Motion

Icons do NOT animate by default. Allowed exceptions:

- `RefreshCw` MAY rotate 360° / 900 ms linear while a query is refetching; MUST stop on completion; MUST NOT loop when no fetch is in flight.
- `Loader2` (Lucide's spinner) is the ONLY spinner glyph, at 900 ms linear infinite; used exclusively inside Button `loading` state and Dialog primary-action pending state.
- No bounce, no morph, no crossfade between icons on state change.

`prefers-reduced-motion: reduce` disables `RefreshCw` rotation (the query-refetch signal is carried by the surrounding dimmed body per `24-component-table.md` §9) and reduces `Loader2` to opacity pulse at 1200 ms.

## 9. Anti-patterns

1. Multiple icon families in one surface.
2. Emoji as an interactive control.
3. Icon-only Button without `aria-label`.
4. `title` on `<svg>` used as a hover tooltip.
5. Hard-coded stroke or fill color.
6. Arbitrary sizes outside §2.
7. Filled Lucide variants.
8. New inline SVG glyphs in feature files, bypassing §6.
9. Icon metaphor stretch (icon does not name the action).
10. Different glyphs for the same concept across surfaces.
11. Looping icon motion outside `RefreshCw` (active fetch) or `Loader2`.
12. PNG or JPEG raster of a glyph that Lucide already provides.

## 10. Acceptance criteria

- AC-ICO-001: Every rendered `<svg>` in the UI is either Lucide or in the §6 registry; a lint script (future `check-icon-family.py`) asserts zero orphan `<svg>` elements.
- AC-ICO-002: Every icon-only `<button>` sets `aria-label`; verified by future accessibility test.
- AC-ICO-003: Icon sizes resolve to one of the five tokens in §2; arbitrary `w-[13px]` and equivalents fail lint.
- AC-ICO-004: `RefreshCw` rotation and `Loader2` spin honor `prefers-reduced-motion: reduce`.
- AC-ICO-005: The concept-to-icon map in §5 is single-valued; no concept maps to two glyphs across the shipped components.

## 11. Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- Manual: `grep -R "<svg" src/` returns only Lucide-generated elements and (currently zero) §6 registry files.
