# Breadcrumbs and Page Header

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`breadcrumbs` · `page-header` · `page-actions` · `identifiers` · `truncation`

## 1. Purpose

Bind the `page-header` and `page-actions` sub-grid slots defined in [`12-shell-layout.md`](./12-shell-layout.md) §3.5 to a normative composition contract. Every authenticated route builds its header from this recipe; detail routes (Reseller, License, Serial, User, App Update) render breadcrumbs identically. This closes the drift risk called out in `12-shell-layout.md` and enforces the `<Identifier>` contract from [`09-typography-scale.md`](./09-typography-scale.md) §6.

## 2. Page Header Composition

The `page-header` sub-grid area contains, in this order, top to bottom:

1. **Breadcrumb** (detail routes only; omitted on landing/list routes).
2. **Title row**: H1 + optional inline status badge + optional identifier chip.
3. **Description** (optional): one sentence, max 140 characters, `--text-body`, `color: var(--muted-foreground)`.

Vertical gap: `--space-1` between breadcrumb and title, `--space-2` between title row and description.

`page-actions` is a separate grid area rendered directly below `page-header` per `12-shell-layout.md` §3.5. Its height is reserved (`min-block-size: 40px`) even when empty to prevent layout shift when actions load asynchronously.

## 3. Breadcrumb Contract

### 3.1 When breadcrumbs appear

- **List routes** (`/admin/licenses`, `/reseller/$resellerId/licenses`, etc.): no breadcrumb. The sidebar's active item already conveys location.
- **Detail routes** (`/admin/licenses/$licenseId`, `/admin/resellers/$resellerId`, etc.): breadcrumb required.
- **Nested detail routes** (`/admin/resellers/$resellerId/prefixes/$prefixId`): breadcrumb required with every intermediate parent.
- **Route errors** (`/forbidden`, `/not-found`): no breadcrumb.

### 3.2 Composition

The breadcrumb is an ordered list of segments. Each segment is either:

- A **parent link**: sentence-case label, resolves to the parent route from [`13-navigation-ia.md`](./13-navigation-ia.md).
- The **current segment**: rendered as text (not a link) with `aria-current="page"` on its `<li>`.

Separator: `ChevronRight` icon (12px), `color: var(--muted-foreground)`, `--space-2` inline gap on either side.

### 3.3 Identifier segments

If a segment represents a resource whose display value is an identifier (LicenseId, SerialValue, RequestId, PrefixId), the segment MUST render through the `<Identifier>` component per `09-typography-scale.md` §6:

- Family `--font-mono`, size `--text-code`.
- Middle-ellipsis when the identifier exceeds the segment's available inline size.
- Tooltip on hover reveals the untruncated value.
- Copy action available from a context action, never from the breadcrumb click itself.
- Click on an identifier parent link navigates; the copy affordance lives in the page header title chip, not the breadcrumb.

### 3.4 Truncation

Breadcrumbs never wrap. When total width exceeds the container:

1. Collapse middle segments into a single `…` menu button that opens a popover (Recipe B per `11-shape-and-motion.md` §4.2) listing the collapsed segments.
2. First and last segments always remain visible.
3. Identifier segments MAY middle-ellipsis independently; the outer collapse rule applies first.

### 3.5 Typography

- Breadcrumb items: `--text-label`, weight 500 for links, weight 500 for the current segment (same visual weight; disambiguation is via color + `aria-current`, not weight).
- Link color: `var(--muted-foreground)`; hover: `var(--foreground)`.
- Current segment color: `var(--foreground)`.
- Separator color: `var(--muted-foreground)`.

### 3.6 Accessibility

- Wrapper element: `<nav aria-label="Breadcrumb">` containing `<ol>`.
- Current segment: `<li>` with `aria-current="page"`, inner text (no `<a>`).
- Separators: `aria-hidden="true"` (they are decorative).

## 4. Title Row

### 4.1 H1

Every authenticated route renders exactly one H1 (`--text-title`, weight 600, `01-visual-foundations.md` §2). The H1 is:

- The resource's human name on detail routes (e.g. "Reseller: Acme Retail").
- The section name on list routes (e.g. "Licenses").
- The action name on wizard routes (e.g. "Issue license").

### 4.2 Status badge (optional)

Rendered inline after the H1 with `--space-3` inline gap. Uses the `<StatusBadge>` component:

- Semantic color from `08-token-registry.md` §3 status tokens.
- Icon always present per `05-team-mood-and-ux-north-star.md` (color alone never conveys state).
- Text ALL CAPS with `letter-spacing: 0.04em` per `09-typography-scale.md` §8.

### 4.3 Identifier chip (optional)

Rendered inline after the status badge with `--space-3` inline gap. Uses the `<Identifier>` component:

- Full identifier value.
- Trailing copy button (icon-only, 32x32 hit area, `aria-label="Copy <resource> id"`).
- Copy action produces a toast (Recipe E, `11-shape-and-motion.md` §4.5) confirming the copied value.

### 4.4 Wrapping

Title row is a flex row with `flex-wrap: wrap` and `gap: --space-3`. Below the `lla-page` container's 640px inline size, the identifier chip wraps below the H1 + status badge. The status badge NEVER wraps to its own line; it stays with the H1.

## 5. Description

- Single sentence, max 140 characters.
- `--text-body`, `color: var(--muted-foreground)`.
- Never contains identifiers, links, or interactive elements; it is prose only.
- If longer copy is needed, move it into a callout inside `page-content`.

## 6. Page Actions

### 6.1 Slot geometry

`page-actions` renders a flex row: primary action inline-end on desktop, below-title on mobile. `gap: --space-3`. `justify-content: flex-end` on desktop, `justify-content: stretch` on mobile with primary spanning full width.

### 6.2 Rules

- At most **one** primary action. If a route seems to need two, one of them is a secondary action.
- At most **two** secondary actions rendered as separate buttons.
- Any additional actions collapse into an overflow menu (`MoreHorizontal` icon button + dropdown per Recipe B).
- **Destructive actions** (Revoke, Delete, Deactivate) NEVER appear in the primary slot and NEVER inline with primary/secondary. They live in the overflow menu, opened via a distinct destructive variant (`color: var(--destructive)`), and always require a confirmation dialog (Recipe C, `11-shape-and-motion.md` §4.3).

### 6.3 Loading and disabled

- Primary action shows a spinner (Recipe G disabled under reduced-motion, static "Loader" icon fallback) and keeps its label; do not swap the label text.
- Disabled state uses `aria-disabled="true"` and mutes the foreground per `10-spacing-and-rhythm.md` §3.1; tooltip explains the precondition, same rule as `13-navigation-ia.md` §3 Disabled.

### 6.4 Height reservation

`min-block-size: 40px` on `page-actions` even when empty; this prevents layout shift on routes that mount actions after the primary data query resolves.

## 7. Detail Route Canonical Recipe

For a detail route (Admin License detail, Admin Reseller detail, Admin User detail, App Update detail, Reseller Serial detail, etc.):

```
[Breadcrumb: Parent > Grandparent > <current identifier>]
[H1 <resource name>] [StatusBadge] [Identifier chip + copy]
[Description (optional, one sentence)]

[page-actions: primary CTA, up to 2 secondary, overflow for destructive]

[page-content: sub-grid owned by the route]
```

Example (Admin License detail `/admin/licenses/LIC_01ABCDE...`):

- Breadcrumb: `Licenses > LIC_01ABCDE…9`
- H1: `License for Acme Retail`
- Status badge: `ACTIVE` (success)
- Identifier chip: `LIC_01ABCDEF9GHIJK` with copy
- Description: `Bound to serial SRL_… on 2026-07-14.`
- Primary action: `Renew`
- Secondary actions: `Suspend`
- Overflow: `Revoke` (destructive, requires confirmation)

## 8. List Route Canonical Recipe

For a list route (Admin Licenses, Admin Users, Reseller Licenses):

```
(no breadcrumb)
[H1 <section name>]
[Description (optional, one sentence)]

[page-actions: primary CTA (e.g. "New license"), overflow for bulk operations]

[page-content: filters row + table]
```

## 9. Wizard Route Canonical Recipe

For a wizard route (Admin issue license, publish app update):

```
[Breadcrumb: Parent > <action name>]
[H1 <action name>]
[Description explaining the action (one sentence)]

[page-actions: reserved but empty; the wizard footer owns primary/secondary CTAs]

[page-content: wizard step content]
```

Wizards do NOT put the primary submit action in `page-actions`; it lives at the bottom of the wizard content per `04-responsive-and-accessibility.md` (thumb reach on mobile).

## 10. Verification

- AC-ADS-046: exactly one H1 per authenticated route.
- AC-ADS-047: breadcrumb identifiers render through `<Identifier>` and never as plain text.
- AC-ADS-048: at most one primary action in `page-actions`.
- AC-ADS-049: destructive actions live in the overflow menu, require confirmation dialog.
- AC-ADS-050: `page-actions` reserves `min-block-size: 40px` even when empty.

```bash
python3 linter-scripts/check-spec-cross-links.py
```

## Cross-References

- [Shell Layout](./12-shell-layout.md)
- [Navigation IA](./13-navigation-ia.md)
- [Token Registry](./08-token-registry.md)
- [Typography Scale](./09-typography-scale.md)
- [Spacing and Rhythm](./10-spacing-and-rhythm.md)
- [Shape and Motion](./11-shape-and-motion.md)
- [Components and States](./03-components-and-states.md)
- [Team Mood and UX North Star](./05-team-mood-and-ux-north-star.md)
