# Spacing and Rhythm

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`spacing` · `rhythm` · `density` · `gap` · `table-density` · `form-density`

## 1. Purpose

Give every spacing token from [`08-token-registry.md`](./08-token-registry.md) §5 an explicit intent so components across Admin, Reseller, AppBuilder, and EndUser render at identical density. This is the doc every table, form, dialog, and KPI card cites.

## 2. Base Unit

- Base: `4px` (`--space-1`).
- All rhythm decisions snap to the closed scale `--space-1..12`.
- No component may compute a spacing value at runtime, and no component may use `margin` for sibling rhythm; use `gap` on the container instead.

## 3. Intent Map

| Token | Value | Canonical Intent |
|-------|-------|------------------|
| `--space-1` | 4px | Hairline gap: icon-to-label inside a chip, KPI trend arrow to number. |
| `--space-2` | 8px | Inline gap: adjacent buttons, badge to label, form field to inline help. |
| `--space-3` | 12px | Control padding-inline: input, button, select. Table cell padding-block (regular density). |
| `--space-4` | 16px | Stack gap: form field to form field, table row height contribution, section-heading-to-first-item. |
| `--space-6` | 24px | Section gap: dialog padding, card padding, shell gutter (desktop). |
| `--space-8` | 32px | Route stack gap: page title to first section, section to section. |
| `--space-12` | 48px | Empty-state padding, hero verify page, unauthenticated shell block. |

Any spacing decision NOT covered here defaults to the nearest token; do not invent a new value.

## 4. Shell Gutters

- Desktop (>=1024px): `padding-inline: var(--space-6)`.
- Tablet (768-1023px): `padding-inline: var(--space-4)`.
- Mobile (<768px): `padding-inline: var(--space-4)`.
- Content max-width: `1440px`, centered.
- Sidebar column: `260px` fixed on desktop, collapses to sheet below `768px`.

## 5. Stack Rhythm

Vertical rhythm is applied via `gap` on the container, never `margin-top` on children.

| Container | Gap |
|-----------|-----|
| Page shell (title -> tabs -> section) | `--space-8` |
| Section (heading -> body) | `--space-4` |
| Card body | `--space-4` |
| Form (field -> field) | `--space-4` |
| Field label -> input | `--space-2` |
| Field input -> help text | `--space-1` |
| Dialog body -> footer | `--space-6` |
| List (repeated item cards) | `--space-3` |

## 6. Inline Rhythm

| Container | Gap |
|-----------|-----|
| Toolbar (icon buttons) | `--space-2` |
| Button internals (icon -> label) | `--space-2` |
| Chip / badge internals | `--space-1` |
| Breadcrumb items | `--space-2` |
| Form footer actions | `--space-3` |
| Table header cell (label -> sort icon) | `--space-1` |

## 7. Table Density

Two densities. Density selection is per-surface, not per-user.

| Density | Row block padding | Cell inline padding | Header height | Use |
|---------|-------------------|---------------------|---------------|-----|
| Regular | `--space-3` | `--space-3` | 40px | Admin lists, Reseller license lists, quota tables |
| Compact | `--space-2` | `--space-3` | 36px | Audit log, request log, verify history |

Row hover surface: `color-mix(in oklab, var(--foreground) 6%, transparent)`.
Row selected surface: `color-mix(in oklab, var(--primary) 12%, transparent)`.
Row separator: `1px solid var(--border)` on `border-block-end` only.

## 8. Form Density

- Input height: `40px` (regular), `32px` (compact, filters only).
- Input padding-inline: `--space-3`.
- Field-to-field gap: `--space-4`.
- Fieldset legend to first field: `--space-3`.
- Submit row: `padding-block-start: --space-6`, aligned inline-end, `--space-3` between actions.
- Inline error text sits directly under the input with `--space-1` gap and never shifts the layout (reserve height with `min-block-size`).

## 9. KPI and Card Density

- KPI card padding: `--space-4`.
- KPI value to caption gap: `--space-1`.
- KPI trend line to value gap: `--space-2`.
- Card header padding-block: `--space-3`, padding-inline: `--space-4`.
- Card body padding: `--space-4`.
- Card footer padding: `--space-3` block, `--space-4` inline.

Cards never nest; a KPI item inside a section is a card, the section itself is unframed.

## 10. Dialog and Sheet Density

- Dialog padding: `--space-6`.
- Dialog title to body gap: `--space-4`.
- Body to footer gap: `--space-6`.
- Sheet gutter: `--space-4` mobile, `--space-6` tablet+.
- Popover padding: `--space-3`.

## 11. Empty States, Errors, Loading

- Empty-state container: `padding-block: --space-12`, centered stack with `gap: --space-4`.
- Error boundary card: same as empty state plus RequestId chip below the primary CTA at `--space-3` gap.
- Skeleton rows use the same row height as their target density; do not add extra breathing room.

## 12. Motion and Focus Interaction

Focus-ring offset (2px per `08-token-registry.md` §9) is included in click-target planning; interactive elements have a minimum 40px hit area, achieved via `--space-3` padding-inline plus intrinsic height.

## 13. Accessibility Floor

- Minimum tap target: 40x40px on touch (WCAG 2.5.5 Level AAA target: 44px, we aim for 44px on primary CTAs and 40px elsewhere).
- Adjacent interactive elements always separated by at least `--space-2`.
- No purely spacing-conveyed grouping; use borders or headings alongside gap.

## 14. Verification

- AC-ADS-029: no `margin` between siblings in Flex/Grid containers; use `gap`.
- AC-ADS-030: table density matches §7 for the correct surface.
- AC-ADS-031: form field rhythm follows §8.

```bash
python3 linter-scripts/check-spec-cross-links.py
```

## Cross-References

- [Token Registry](./08-token-registry.md)
- [Typography Scale](./09-typography-scale.md)
- [CSS Technique Budget](./07-css-technique-budget.md)
- [Components and States](./03-components-and-states.md)
- [Responsive and Accessibility](./04-responsive-and-accessibility.md)
