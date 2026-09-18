# Typography Scale

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`typography` · `font-families` · `type-scale` · `identifiers` · `tabular-numerics`

## 1. Purpose

Bind the type tokens defined in [`08-token-registry.md`](./08-token-registry.md) §4 to families, weights, tracking, numeric handling, and per-role usage. Every heading, body, label, and identifier in the app resolves through this contract; no component may declare its own `font-family`, `letter-spacing`, or `font-variant-numeric`.

## 2. Families of Record

| Family | Role | Loading |
|--------|------|---------|
| IBM Plex Sans | UI, headings, labels, body copy | `<link rel="stylesheet">` in `src/routes/__root.tsx` head, `display=swap`, subset `latin` |
| JetBrains Mono | Identifiers (serial, hash, RequestId, ClientId, timestamps) | Same route, same swap policy |

Fallback stacks:

```css
--font-sans: "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
--font-mono: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace;
```

No third family is permitted. Serif, script, and display fonts are banned by [`07-css-technique-budget.md`](./07-css-technique-budget.md) §4.

## 3. Weight Ladder

| Weight | Token | Usage |
|--------|-------|-------|
| 400 | `--font-weight-regular` | Body copy, table cells, identifiers |
| 500 | `--font-weight-medium` | Labels, secondary emphasis, monospaced identifiers when hovered/selected |
| 600 | `--font-weight-semibold` | Headings, primary buttons, KPI values |

Weights 100, 200, 300, 700, 800, 900 are not loaded. Do not request them.

## 4. Role Map

Every role below MUST cite a token from `08-token-registry.md` §4. `family` is one of `sans` or `mono`.

| Role | Token | Family | Weight | Tracking | Notes |
|------|-------|--------|--------|----------|-------|
| Display (route hero, empty-state title) | `--text-display` | sans | 600 | `-0.01em` | One per route max. Never in table rows. |
| Title (page H1) | `--text-title` | sans | 600 | `-0.005em` | Exactly one per authenticated route. |
| Heading (section H2, dialog H1) | `--text-heading` | sans | 600 | `0` | Section boundaries within a page. |
| Body | `--text-body` | sans | 400 | `0` | Paragraphs, table cells, form help text. |
| Label | `--text-label` | sans | 500 | `0.01em` | Form labels, table headers, KPI captions. |
| Code / Identifier | `--text-code` | mono | 500 | `0` | Serial, hash, RequestId, ClientId, LicenseId. |

Line heights and sizes are frozen in `08-token-registry.md` §4; do not override in components.

## 5. Numeric Handling

Identifiers, tables, KPIs, quotas, and timestamps MUST render with tabular figures so columns align:

```css
font-variant-numeric: tabular-nums;
```

Applied via a `--font-variant-numeric-tabular` token, consumed by:

- Every `<td>` and `<th>` in data tables.
- KPI value slots.
- Quota counters (`allowance`, `consumed`, `remaining`).
- `<time>` elements and RequestId chips.
- Identifiers wrapped in the `<Identifier>` component.

Body copy uses default (`normal`) figures.

## 6. Identifier Styling

Serial, hash, RequestId, ClientId, and LicenseId ALWAYS render through the `<Identifier>` component:

- Family: `--font-mono`.
- Size: `--text-code`.
- Weight: 500.
- Visual truncation permitted (middle-ellipsis) but copy action MUST copy the complete value.
- Tooltip on hover displays the full untruncated string.
- Focus-visible ring per [`08-token-registry.md`](./08-token-registry.md) §9.

No identifier is rendered as plain body text.

## 7. Line Length and Wrapping

- Prose paragraphs: `max-width: 68ch` on `<p>` inside long-form regions (docs, verify page copy).
- Table cells: never truncate identifiers except through `<Identifier>`; wrap other cells with `overflow-wrap: anywhere` when necessary.
- Headings: `text-wrap: balance` where supported; base rule remains readable without it.
- Never use `hyphens: auto`; identifiers must never hyphenate.

## 8. Casing and Punctuation

- Sentence case for headings, buttons, menu items, labels.
- Never ALL CAPS except for enum badges rendered through the `<StatusBadge>` component (which applies `letter-spacing: 0.04em`).
- No em dashes in UI copy. Use commas, colons, or period-separated sentences.
- Ellipsis rendered as the character `…`, not three dots.

## 9. Localization Readiness

- All type roles size in `rem`; no `px` sizes in components.
- Do not bake language-specific tracking into components; overrides live in `@layer overrides` only when a language proves the need.
- Reserve 30% expansion room around labels; no fixed-width `<label>` boxes.

## 10. Accessibility

- Body copy contrast >= 4.5:1 against its surface (AC-ADS-A11Y-001).
- Label copy contrast >= 4.5:1 (AC-ADS-A11Y-001).
- Minimum interactive text size: `--text-label`.
- `line-height` never below `1.4` for body-sized text.
- Focus-visible ring never overlaps descender letters; ring offset covers it.

## 11. Verification

- AC-ADS-025: only IBM Plex Sans and JetBrains Mono are loaded.
- AC-ADS-026: identifiers render through `<Identifier>` with tabular numerics.
- AC-ADS-027: exactly one `--text-title` H1 per authenticated route.
- AC-ADS-028: KPI and quota counters use `tabular-nums`.

```bash
python3 linter-scripts/check-spec-cross-links.py
python3 linter-scripts/check-forbidden-strings.py
```

## Cross-References

- [Visual Foundations](./01-visual-foundations.md)
- [Fluid Design Foundations](./06-fluid-design-foundations.md)
- [CSS Technique Budget](./07-css-technique-budget.md)
- [Token Registry](./08-token-registry.md)
- [Components and States](./03-components-and-states.md)
