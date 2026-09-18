# Responsive and Accessibility

**Version:** 0.23.0
**Updated:** 2026-07-15
**Status:** Active
**Category:** UI / Frontend

## 1. Responsive Contract

| Range | Layout behavior |
|-------|-----------------|
| Under 768px | Navigation sheet, single-column forms, record cards or horizontally scrollable tables. |
| 768px to 1199px | Collapsible sidebar, two-column forms only when labels remain readable. |
| 1200px and above | Fixed sidebar, full table density, multi-column dashboards. |

Fixed-format controls, charts, KPI grids, and tables declare stable dimensions through grid tracks, `minmax()`, aspect ratio, or bounded height. Dynamic labels must wrap without overlapping adjacent controls. Font size does not scale with viewport width.

## 2. Keyboard and Focus

All functionality is available by keyboard. Focus order follows reading order. Every interactive element has a visible `:focus-visible` ring with at least 3:1 contrast. Dialogs and sheets trap focus while open and restore it on close. Escape closes dismissible overlays but never discards an unsaved mutation without confirmation.

## 3. Semantics

- One H1 per route with sequential heading levels.
- Navigation uses `nav`; primary content uses `main`.
- Data tables use headers with explicit scope.
- Form errors use `aria-describedby`; summaries link to invalid fields.
- Icon-only buttons have accessible names and tooltips.
- Loading uses `aria-busy`; status updates use an appropriate live region.

## 4. Contrast and Non-Color Cues

Normal text meets WCAG AA 4.5:1, large text meets 3:1, and control boundaries plus focus indicators meet 3:1 against adjacent colors. Success, warning, destructive, and selected states use text or icons in addition to color.

## 5. Motion

Motion is limited to 150ms control feedback, 200ms overlays, and 250ms route-state transitions. No hover transition exceeds 300ms. `prefers-reduced-motion: reduce` removes non-essential transforms, animated scrolling, and repeated motion while preserving state changes.

## 6. Content Resilience

- Text supports 200% browser zoom without loss of function.
- Long serials, hashes, client names, and localized dates cannot resize toolbars or obscure actions.
- Truncated identifiers expose the full value through accessible text or a deliberate reveal action.
- Touch targets are at least 44 by 44px on mobile, including icon-only commands.

## 7. Verification Viewports

Before a UI surface is accepted, verify at 390 by 844, 768 by 1024, and 1440 by 1000. Validate light and dark themes, keyboard-only flow, reduced motion, empty/error/loading states, and at least one longest expected identifier.

## Cross-References

- [Shell and navigation](./02-shell-and-navigation.md)
- [Components and states](./03-components-and-states.md)
- [Core motion](../07-design-system/06-motion-transitions.md)