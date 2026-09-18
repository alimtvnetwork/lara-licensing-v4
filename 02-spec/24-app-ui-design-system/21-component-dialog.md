# Component: Dialog and Sheet

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for modal-and-panel overlays: Dialog (centered modal for focused tasks and destructive confirmations) and Sheet (edge-attached drawer for edit panels and long secondary flows). It reconciles [`17-component-button.md`](./17-component-button.md) §7 (destructive contract requires confirmation Dialog OR overflow Menu), [`18-component-input.md`](./18-component-input.md) §12 (Escape-twice safety rule), and [`19-component-select.md`](./19-component-select.md) §8 (listbox popover inside a Dialog).
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §10 (overlay paddings), §12 (click-target floor), [`11-shape-and-motion.md`](./11-shape-and-motion.md) §2 (elevation), §3 (attach-and-detach motion), §5 (reduced-motion), [`12-shell-layout.md`](./12-shell-layout.md) §4 (shell variants), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3.4 (mutation loading), §4.2 (Validation surface), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md) §7 (destructive contract), [`18-component-input.md`](./18-component-input.md) §12 (keyboard contract), [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Purpose and non-purpose

Two primitives with distinct purposes:

| Primitive | Purpose | NOT for |
|-----------|---------|---------|
| Dialog | Blocking modal for a focused task or confirmation. Content fits in one viewport without scrolling in the typical case. | Long forms (Sheet); persistent side panels (shell layout); toasts (Toast); route-level errors (`/error`) |
| Sheet | Edge-attached (inline-end) drawer for edit panels, detail previews, or forms taller than one viewport. | Confirmations (Dialog); navigation (Menu); primary route content (routes) |

Both are BLOCKING: while open, background surface is inert (`aria-hidden="true"` and `inert` attribute on the shell), focus is trapped inside, and Escape closes them per §7.

## 2. Anatomy

Every Dialog and Sheet composes the same four regions:

```
<overlay>
  <container role="dialog" aria-modal="true" aria-labelledby=titleId aria-describedby=descId?>
    <header>
      <h2 id={titleId}>Title (Heading/S)</h2>
      <p id={descId}>Optional short description (Body/S)</p>
      <button aria-label="Close">X</button>
    </header>
    <body>
      {content}
    </body>
    <footer>
      <button>Secondary (neutral)</button>
      <button>Primary (primary or destructive)</button>
    </footer>
  </container>
</overlay>
```

- The `X` close button in the header is REQUIRED for non-destructive Dialogs and all Sheets. It is FORBIDDEN on destructive confirmation Dialogs (see §5): the only exits are the two footer Buttons ("Cancel" and the destructive verb).
- The footer holds at most 3 Buttons; more than 3 forces a rethink (use a Menu or split the task).
- Primary Button is inline-end; secondary Buttons are inline-start of the primary. Never invert this order.

## 3. Geometry and layout

| Property | Dialog | Sheet |
|----------|--------|-------|
| Elevation | Elevation-2 per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §2 | Elevation-2 |
| Border radius | `--radius-lg` on container | `--radius-lg` on inline-start edge only (inline-end flush) |
| Container inline-size | `min(560px, 100vw - 2 * --space-4)` for default; `min(720px, 100vw - 2 * --space-4)` for `lg` variant; `min(400px, 100vw - 2 * --space-4)` for confirmation Dialogs | `clamp(360px, 40vw, 640px)` default; `clamp(480px, 55vw, 900px)` for `lg` variant |
| Max block-size | `calc(100vh - 2 * --space-4)` with internal scroll on body only | Full viewport block-size; internal scroll on body |
| Padding | `--space-4` inline, `--space-4` block on header/footer, `--space-4` on body | Same |
| Overlay | `background: color-mix(in oklab, var(--background) 40%, transparent)`, `backdrop-filter: blur(4px)` | Same |
| Position | Centered viewport (inline and block) | Anchored to inline-end edge, full block-size |

Header and footer are sticky within the container so long body content does not push the primary Button off-screen. Body scrolls; header and footer never scroll.

## 4. Motion

- Enter: `attach-and-detach` recipe per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §3 with `@starting-style`.
  - Dialog: opacity 0 -> 1, translate block 4 px -> 0, scale 0.98 -> 1, duration 180 ms, easing `--ease-out-standard`.
  - Sheet: opacity 0 -> 1, translate inline `+100%` -> 0, duration 220 ms, easing `--ease-out-standard`.
- Exit: reverse enter, duration 140 ms (Dialog) / 180 ms (Sheet), easing `--ease-in-standard`.
- Overlay fades opacity 0 -> 1 alongside the container, same duration.
- Under `prefers-reduced-motion: reduce`: overlay and container fade only, no translate/scale, duration 80 ms.

## 5. Destructive confirmation Dialog (destructive contract)

Cross-referenced from [`17-component-button.md`](./17-component-button.md) §7. Every destructive server action MUST route through this pattern:

- **Trigger**: a Button with `intent="destructive"` (or an overflow Menu item marked destructive). Clicking the trigger opens the confirmation Dialog; the mutation is NOT fired from the trigger.
- **Container**: confirmation variant (400 px max inline-size), no `X` close button in the header (only Cancel + destructive verb).
- **Title**: sentence case, uses the destructive verb ("Revoke license", "Delete reseller", "Deny quota request"). Never "Are you sure?".
- **Body**: one paragraph stating the consequence in second person, plus a bullet list of concrete effects. Never uses "cannot be undone" as filler; state the actual effect (e.g. "The license status changes to Revoked and all currently issued Serials stop verifying within 60 seconds").
- **Secondary confirmation**: for actions whose blast radius touches production data or is bulk (>10 rows), require the user to type a confirmation phrase (e.g. the reseller name) into an Input inside the body. The destructive Button stays `aria-disabled` until the phrase matches. `aria-disabled` (not native `disabled`) so screen readers can still announce it.
- **Footer**: two Buttons. Cancel (`ghost`/`neutral`) inline-start, destructive verb (`solid`/`destructive`) inline-end. No third button.
- **Mutation**: MUST send `Idempotency-Key` per [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md). The key is generated when the Dialog opens and re-used on retry within the same open session.
- **On success**: Dialog closes; toast surfaces success with `RequestId` per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2 (toast for `Conflict`/`IdempotencyReplay` and neutral success).
- **On failure**: Dialog stays open, error surface renders inside the body per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.1 (icon, headline, body, RequestId chip). The destructive Button remains available for retry with the same `Idempotency-Key`.
- **On rate-limit (`429`)**: Dialog stays open, `<RetryAfterBanner>` renders at the top of the body, destructive Button is `aria-disabled` until countdown elapses.
- **Telemetry**: emits `DestructiveConfirmed` at LogLevel=info on submit (before server response) with `Action`, `TargetEntityType`, `TargetEntityId`, `Idempotency-Key`, `RequestId=null`. On response, emits `DestructiveResolved` at LogLevel=info (success) or LogLevel=warn (failure) with `Outcome`, `ErrorCode?`, `RequestId`, `Idempotency-Key`.

## 6. Sheet: edit panels and detail previews

Sheets are the default surface for:

- Row edit from a Table (e.g. edit reseller quota row, edit feature registry entry) when the form has more than 3 fields OR includes a Table/list of its own.
- Detail preview from a list without navigating away (e.g. peek a license from the Licenses table).
- Long secondary flows launched from a primary route (e.g. quota request submit from the Reseller portal).

Sheet rules:

- Only ONE Sheet may be open at a time. Opening a second Sheet closes the first (with an unsaved-changes guard per §8).
- Sheets MAY contain forms with submit Buttons in the footer. Form submit obeys the Form contract (Plan 06 Step 20) and closes the Sheet on success.
- A Sheet MUST NOT open another modal Dialog for confirmation of routine actions; the Sheet's own footer holds the primary action. Exception: destructive actions inside a Sheet DO open a nested confirmation Dialog per §5 (which becomes the topmost focus trap; the Sheet stays behind and is `inert`).
- Sheets do NOT navigate. Clicking a link inside a Sheet body closes the Sheet (with unsaved-changes guard) and navigates the parent route.

## 7. Focus and keyboard contract

- On open: focus moves to the first focusable element inside the body, EXCEPT for destructive confirmation Dialogs, where focus moves to the Cancel Button (safest default). If the destructive Dialog requires a phrase Input (§5), focus moves to that Input instead.
- Focus is trapped: Tab/Shift+Tab cycles within the container.
- Escape closes the Dialog/Sheet, subject to the two-Escape safety rule from [`18-component-input.md`](./18-component-input.md) §12: if any Input or TextArea inside the container is dirty and focused, the first Escape clears its selection; the second Escape closes the container.
- Escape is FORBIDDEN from closing a destructive confirmation Dialog whose destructive Button is `aria-disabled` awaiting the phrase; Escape still closes it if the user has not typed anything. In practice: Escape always cancels unless the mutation is in flight.
- Clicking the overlay closes non-destructive Dialogs and Sheets (subject to unsaved-changes guard, §8). It NEVER closes a destructive confirmation Dialog; that requires an explicit Cancel click.
- On close: focus returns to the element that opened the container. If that element no longer exists (e.g. it was inside a removed row), focus returns to the nearest surviving parent landmark and announces context via a visually-hidden `aria-live` region.

## 8. Unsaved-changes guard

If the container hosts a form with dirty (touched-and-changed) fields, closing the container via Escape, overlay click, X button, or Cancel Button MUST trigger a nested confirmation:

- Title: "Discard changes?"
- Body: "Your changes are not saved. Closing will discard them."
- Buttons: "Keep editing" (returns focus into the original container) and "Discard" (destructive, closes the container).

The nested confirmation is a small Dialog (400 px) rendered above the original container. The original stays `inert` behind it.

Submit-in-flight overrides the guard: while the mutation is pending, Escape and overlay click are ignored; the X button and Cancel Button are `aria-disabled`.

## 9. Nested overlays

- Dialog INSIDE Dialog: allowed ONLY for the destructive confirmation nested inside a Sheet (§6) or the unsaved-changes guard nested inside any container (§8). Deeper nesting is banned.
- Popover/Select listbox INSIDE Dialog or Sheet: allowed; the listbox portal MUST render inside the container (not the document root) so focus trap and `inert` background continue to work.
- Toast on top of an open Dialog: allowed for success/failure of the Dialog's own action; toasts stack in the top-inline-end corner of the viewport (not the container).

## 10. Copy rules

- Title: sentence case, no trailing punctuation, one line. Uses the primary verb of the action.
- Body: second person, one short paragraph. Optional bullet list of consequences (destructive) or fields (edit).
- Primary Button label: uses the same verb as the title ("Revoke license" in title, "Revoke" on Button OR "Revoke license" on Button; the two match).
- Never "OK" or "Yes/No" on Buttons.
- Never "Are you sure?" as title copy.

## 11. Anti-patterns (forbidden)

- Dialog for a long form (>3 fields with helper text) instead of a Sheet.
- Sheet for a destructive confirmation.
- Firing the destructive mutation from the trigger instead of the confirmation Dialog's primary Button.
- Sending a destructive mutation without `Idempotency-Key`.
- Overlay click closes a destructive confirmation Dialog.
- Native `disabled` on the destructive Button waiting for a phrase (must be `aria-disabled`).
- More than 3 Buttons in a footer.
- Two Sheets open simultaneously.
- A Dialog that opens another Dialog for a non-destructive, non-guard purpose.
- Route navigation from inside a Sheet without closing the Sheet.
- Focus not returning to the trigger on close.
- Toasts rendered inside the container instead of the viewport corner.

## 12. Acceptance

- AC-DLG-001: Every destructive server action in `src/**` routes through the confirmation Dialog pattern in §5; a direct destructive mutation from a Button without an intervening Dialog fails `linter-scripts/check-destructive-context.py` (Plan 06 Step 46).
- AC-DLG-002: Every destructive mutation sends `Idempotency-Key`; missing header fails a unit test with a fake client.
- AC-DLG-003: Overlay click closes non-destructive containers only; a click test against a destructive Dialog asserts it stays open.
- AC-DLG-004: Two-Escape safety rule applies to Inputs and TextAreas inside Dialog/Sheet; verified by a Playwright test that types, presses Escape once (asserts selection cleared, container still open), presses Escape twice (asserts container closed).
- AC-DLG-005: Focus trap: Tab from the last focusable element wraps to the first; Shift+Tab from the first wraps to the last. Verified by a Playwright a11y test.
- AC-DLG-006: Focus returns to the trigger on close; if the trigger no longer exists, focus lands on the nearest surviving landmark with an `aria-live` announcement. Verified by a Playwright test that removes the trigger before closing.
- AC-DLG-007: Unsaved-changes guard fires on Escape/overlay/X/Cancel when any Form field is dirty; verified by a Playwright test.
- AC-DLG-008: `DestructiveConfirmed` and `DestructiveResolved` log lines fire per §5; verified with a fake logger.
- AC-DLG-009: Motion honors `prefers-reduced-motion` per §4; snapshot of computed animation duration under the media query asserts fade-only.
- AC-DLG-010: Only ONE Sheet may be open at a time; opening a second while the first is dirty triggers the unsaved-changes guard on the first, then opens the second on confirm.
- AC-DLG-011: Popover/Select portals inside a Dialog/Sheet render into the container element, not `document.body`; a component test asserts the portal parent.
- AC-DLG-012: Rate-limited destructive mutation surfaces `<RetryAfterBanner>` inside the Dialog body and holds the destructive Button `aria-disabled` until the countdown elapses; verified by a Playwright test with intercepted 429 response.
- AC-DLG-013: Destructive Dialog has no `X` close button in the header; enforced by a component-level test.
