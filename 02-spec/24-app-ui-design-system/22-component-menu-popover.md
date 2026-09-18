# Component: Menu and Popover

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for two non-selecting overlay primitives: Menu (action list triggered by a Button, typically the row `...` overflow) and Popover (non-modal content bubble anchored to a trigger, e.g. help hints, keyboard-shortcut sheets, filter builders). It complements the modal Dialog/Sheet contract in [`21-component-dialog.md`](./21-component-dialog.md) and the listbox contract in [`19-component-select.md`](./19-component-select.md) §8.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §10 (overlay paddings), §12 (click-target floor), [`11-shape-and-motion.md`](./11-shape-and-motion.md) §2 (elevation), §3 (attach-and-detach motion), [`17-component-button.md`](./17-component-button.md) §1 (Button-as-navigation ban), §7 (destructive contract), [`19-component-select.md`](./19-component-select.md) §8 (listbox popover positioning), [`21-component-dialog.md`](./21-component-dialog.md) §5 (destructive confirmation), §9 (nested overlays), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Purpose and non-purpose

| Primitive | Purpose | NOT for |
|-----------|---------|---------|
| Menu | Action list of 2..8 items triggered by a Button, non-modal, closes on selection or Escape. Overflow for tertiary actions, row actions, toolbar overflow. | Navigation between routes (use `<Link>` per `17-component-button.md` §1); single confirmation (use Dialog); more than 8 items (rethink or split); form fields |
| Popover | Non-modal informational or lightweight interactive content anchored to a trigger. Help hints, keyboard-shortcut cheat sheet, filter builder, formula reveal. | Selecting a value from a closed set (Select); confirmations (Dialog); primary content (route body); more than one anchored popover open at the same trigger |

Neither primitive traps focus. Both dismiss on outside click and Escape. Neither carries `aria-modal`.

## 2. Menu anatomy

```
<button aria-haspopup="menu" aria-expanded={open} aria-controls={menuId}>...</button>
<overlay>
  <ul role="menu" id={menuId} aria-labelledby={triggerId}>
    <li role="menuitem" tabindex={-1}>Neutral action</li>
    <li role="separator" />
    <li role="menuitem" data-intent="destructive">Destructive verb...</li>
  </ul>
</overlay>
```

- Menu items are `role="menuitem"`; grouping uses `role="separator"` between logical clusters, NOT headers.
- Icons in menu items are decorative and `aria-hidden`; the item label carries the semantic.
- Destructive items render with a leading destructive-intent icon and label in `var(--destructive)`. Their `data-intent="destructive"` attribute triggers the confirmation route in §5.
- Keyboard-shortcut hint MAY render inline-end of the item label in Label/S `var(--muted-foreground)`. It is decorative; the shortcut binding lives on the underlying command, not the menu item.
- Trigger MUST be a Button per [`17-component-button.md`](./17-component-button.md); `ghost`/`neutral` size `sm` for row `...` overflow.

## 3. Popover anatomy

```
<button aria-haspopup="dialog" aria-expanded={open} aria-controls={popoverId}>Trigger</button>
<overlay>
  <div role="dialog" id={popoverId} aria-labelledby={titleId?}>
    {content}
  </div>
</overlay>
```

- Popover uses `role="dialog"` but NOT `aria-modal` (non-modal). Focus MAY move into the popover on open, but is not trapped.
- Content MAY be a paragraph (help hint), a definition list (formula reveal), or a small form (filter builder). If the form has a submit action, it MUST be a Popover, not a Menu.
- Popovers MAY contain a close X button in the top-inline-end corner for touch users; keyboard users close via Escape.

## 4. Geometry and motion

| Property | Menu | Popover |
|----------|------|---------|
| Elevation | Elevation-1 per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §2 | Elevation-1 |
| Border radius | `--radius-md` | `--radius-md` |
| Padding | `--space-1` block, 0 inline; each item pads `--space-2` inline + `--space-2` block, hit target 40 px | `--space-4` on all sides |
| Inline-size | min-inline-size 200 px, max-inline-size 320 px; content-fit within range | min-inline-size 240 px, max-inline-size `min(480px, 100vw - 2 * --space-4)` |
| Max block-size | `min(400px, 100vh - --shell-topbar - 2 * --space-4)` with internal scroll | `min(320px, 100vh - --shell-topbar - 2 * --space-4)` with internal scroll |
| Positioning | Below the trigger by default; flips above when clipped; gap `--space-1` | Same rules; MAY also anchor inline-start/inline-end for lateral hints |
| Motion enter | `attach-and-detach` recipe with `@starting-style`; 140 ms opacity+translate 4 px | 160 ms opacity+scale 0.98 -> 1 |
| Motion exit | 100 ms | 120 ms |
| Reduced motion | Fade only, 60 ms | Fade only, 80 ms |

## 5. Destructive items inside Menu (destructive contract handoff)

Cross-referenced from [`17-component-button.md`](./17-component-button.md) §7 and [`21-component-dialog.md`](./21-component-dialog.md) §5. Selecting a Menu item marked `data-intent="destructive"` MUST NOT fire the mutation directly. Instead:

- The Menu closes on selection (like any menuitem).
- Immediately after close, a confirmation Dialog opens per [`21-component-dialog.md`](./21-component-dialog.md) §5. Focus moves to the Dialog's Cancel Button (or phrase Input for production/bulk).
- The `Idempotency-Key` is generated when the confirmation Dialog opens (not when the Menu item is selected).
- Telemetry: the Menu selection emits `MenuItemSelected` at LogLevel=info with `Command`, `TargetEntityType?`, `TargetEntityId?`; the Dialog then emits `DestructiveConfirmed`/`DestructiveResolved` per Dialog §5.

Firing a destructive mutation directly from a menuitem `onSelect` handler is BANNED and fails `linter-scripts/check-destructive-context.py` (Plan 06 Step 46).

## 6. Dismissal rules (shared)

- Escape closes the Menu/Popover and returns focus to the trigger.
- Outside click closes both. Clicking inside the Popover does not close it (unless the content explicitly calls the close handler, e.g. a filter builder's Apply button).
- For Menu, selecting any non-destructive menuitem closes the Menu and returns focus to the trigger.
- For Menu, selecting a destructive menuitem closes the Menu and moves focus to the confirmation Dialog per §5. Focus does NOT return to the trigger until the Dialog closes.
- Scroll on the underlying route: Menu and Popover CLOSE on the first scroll event outside the overlay (they are anchored; they must not drift). Scroll INSIDE the overlay (long menu, long popover) does NOT close.
- Route change closes both.

## 7. Focus and keyboard contract

**Menu**:

- Open: `Enter`, `Space`, `ArrowDown`, `ArrowUp` on the trigger; focus moves to the first enabled menuitem (`ArrowDown`/`Enter`) or last enabled menuitem (`ArrowUp`).
- Within: `ArrowDown`/`ArrowUp` move focus (with wrap); `Home`/`End` jump to first/last enabled; typeahead (letters, 500 ms reset) focuses first item whose label starts with the typed prefix; `Enter`/`Space` activate; `Escape` closes; `Tab`/`Shift+Tab` close the menu and move focus to the next/previous focusable element in the shell (does NOT walk menuitems).
- Separator items and disabled items are skipped by all navigation.

**Popover**:

- Open: `Enter` or `Space` on the trigger.
- Within: Tab moves focus in DOM order among focusable content; wrap is NOT trapped, so Tab from the last focusable inside eventually leaves the popover and closes it.
- Escape closes.

## 8. Disabled items

- Menu items MAY be `aria-disabled="true"` with a precondition tooltip; native `disabled` on a menuitem is banned (unreachable by screen readers, tooltip does not fire), matching the rule in [`17-component-button.md`](./17-component-button.md) §5 and [`19-component-select.md`](./19-component-select.md) §6.
- Permission-gated items MUST be HIDDEN from the Menu, not disabled, matching [`13-navigation-ia.md`](./13-navigation-ia.md) §3. A Menu MUST NOT render items the caller lacks permission to invoke.
- If hiding all items would leave the Menu empty, the trigger Button MUST be hidden too. Rendering a trigger that opens an empty Menu is BANNED.

## 9. Nested overlays

- Popover inside Popover: banned.
- Menu inside Menu (submenu): allowed with `role="menu"` on the submenu, `aria-haspopup="menu"` on the parent item, and a max nesting depth of 2. Submenus open on `ArrowRight` and close on `ArrowLeft` or Escape (which closes only the innermost). Use sparingly; more than one submenu in a Menu suggests a wrong primitive (use Sheet or route).
- Menu inside Dialog/Sheet: allowed; portal renders inside the container per [`21-component-dialog.md`](./21-component-dialog.md) §9.
- Popover inside Dialog/Sheet: allowed; same portal rule.
- Tooltip inside Menu item: allowed for disabled precondition explanation only.

## 10. Copy rules

- Menu item labels: sentence case, verb-first for actions ("Revoke license", "Copy license code"), no trailing punctuation except an ellipsis `…` when the action opens a modal/dialog for further input.
- Destructive items use the actual verb ("Revoke license", "Delete reseller"). Never "Remove" as a euphemism.
- Popover title (when present): sentence case, Heading/S typography.
- Popover body: second person, one to three short sentences.

## 11. Anti-patterns (forbidden)

- Menu used for navigation between routes (use `<Link>` list or a Sheet).
- Menu with more than 8 items.
- Menu with headers instead of separators between clusters.
- Destructive mutation fired directly from a menuitem `onSelect` handler.
- Rendering a Menu trigger that opens an empty Menu (all items permission-hidden).
- Native `disabled` on a menuitem.
- Popover with a form that has no submit action (that is a Menu of actions or a Dialog).
- Popover opened on hover only (must be openable via keyboard). Hover-only tooltips are a separate Tooltip primitive, out of scope here.
- Nested Popover.
- Focus trap inside a Popover.
- Menu or Popover that does not close on route change.
- Menu or Popover that stays open when the anchor scrolls out of view.

## 12. Acceptance

- AC-MEN-001: Every destructive Menu item routes through the confirmation Dialog per §5; a menuitem `onSelect` that fires a mutation directly fails `linter-scripts/check-destructive-context.py` (Plan 06 Step 46).
- AC-MEN-002: Permission-gated menuitems are HIDDEN, not disabled; a Menu that would render zero items also hides its trigger. Enforced by a component-level test and a route-level a11y test.
- AC-MEN-003: Native `disabled` on `role="menuitem"` fails a lint rule.
- AC-MEN-004: Menu keyboard contract matches §7 (open on Enter/Space/Arrow, wrap on Arrow, typeahead 500 ms, Tab closes); verified by a Playwright a11y test.
- AC-MEN-005: Menu closes on outside click, Escape, route change, and outside scroll; verified by four Playwright tests.
- AC-MEN-006: `MenuItemSelected` log line fires on selection with `Command` and target IDs; verified with a fake logger.
- AC-POP-001: Popover uses `role="dialog"` WITHOUT `aria-modal`; a snapshot of the rendered ARIA attributes asserts this.
- AC-POP-002: Popover does NOT trap focus; a Playwright test tabs from the last focusable inside and asserts focus moves to the next element in the shell and the Popover closes.
- AC-POP-003: Popover opens on Enter/Space keyboard activation; a hover-only Popover fails an a11y test.
- AC-POP-004: Popover closes on Escape, outside click, route change, and outside scroll; four Playwright tests.
- AC-MEN-007: Submenu max depth is 2; deeper nesting fails a component test.
- AC-MEN-008: Motion honors `prefers-reduced-motion`; snapshot of computed animation under the media query asserts fade-only.
- AC-MEN-009: Menu inside a Dialog/Sheet portals inside the container per §9; verified by a component test asserting portal parent.
