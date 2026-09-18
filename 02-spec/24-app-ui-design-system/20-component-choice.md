# Component: Choice (Checkbox, Radio, Switch)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for the three boolean/single-choice primitives: Checkbox (independent boolean or multi-value group), Radio (exclusive choice within a group), Switch (immediate-effect boolean toggle). It reconciles the deferral in [`19-component-select.md`](./19-component-select.md) §1 and the ban in [`17-component-button.md`](./17-component-button.md) §1 (Button-as-toggle).
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §12 (click-target floor), [`11-shape-and-motion.md`](./11-shape-and-motion.md) §1 (radius intent), §3 (motion recipes), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3.4 (mutation loading), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md) §3 (Field composition), [`19-component-select.md`](./19-component-select.md) §2 (closed-set-parity rule), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md).

---

## 1. Purpose and non-purpose

Three primitives, three purposes. Never substitute one for another.

| Primitive | Purpose | NOT for |
|-----------|---------|---------|
| Checkbox | Independent boolean (single) OR multi-value group (0..N selected) | Exclusive choice; immediate-effect setting toggle |
| Radio | Exclusive choice inside a group (exactly 1 of N) | Independent boolean; more than 7 options (use Select) |
| Switch | Immediate-effect boolean setting toggle (persists on change without submit) | Form field that requires submit; multi-value group; destructive action |

Decision tree:

```
Is the change destructive or requires confirmation?
  yes -> Button inside confirmation Dialog (17-component-button.md §7)
  no  -> Does the change persist immediately without a submit button?
           yes -> Switch
           no  -> Is the choice exclusive within a fixed group of 2..7?
                    yes -> Radio group
                    no  -> Is it a set of 0..N independent booleans?
                             yes -> Checkbox (single or group)
                             no  -> Select (see 19-component-select.md)
```

## 2. Closed-set parity (Radio and Checkbox groups)

Radio groups and Checkbox groups whose options are enumerated in [`19-component-select.md`](./19-component-select.md) §2 (closed-set-parity registry) MUST bind their options via the same `useClosedSet(<enum-name>)` helper. Inline literal option arrays fail `linter-scripts/check-closed-set-select.py` (Plan 06 Step 46) regardless of which primitive renders them.

Boolean Switches and single Checkboxes are exempt from closed-set-parity because they represent a single named boolean, not a set.

## 3. Geometry

| Property | Checkbox | Radio | Switch |
|----------|----------|-------|--------|
| Control block-size x inline-size | 20 x 20 px | 20 x 20 px | 32 x 20 px (track), 16 x 16 px (thumb) |
| Border radius | `--radius-sm` (4 px) | 50% | `--radius-full` on track and thumb |
| Border | `1px solid var(--border)` | `1px solid var(--border)` | none; track uses `--muted` / `--primary` fill |
| Hit target | 40 x 40 px minimum ([`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §12) | 40 x 40 px | 40 x 40 px |
| Gap control-to-label | `--space-2` (8 px) | `--space-2` | `--space-3` (12 px) |
| Label typography | Body/M | Body/M | Body/M |
| Description typography | Body/S, `var(--muted-foreground)` | Body/S, `var(--muted-foreground)` | Body/S, `var(--muted-foreground)` |
| Focus ring | Per [`08-token-registry.md`](./08-token-registry.md) §9 (matches Input/Button) | Same | Same, wraps track |

The 40 x 40 px hit target is enforced via a transparent padded wrapper around the visible 20 x 20 (or 32 x 20 for Switch) control. Enforced by `linter-scripts/check-click-target-floor.py` (Plan 06 Step 46).

## 4. States

Shared state table (all three primitives):

| State | Trigger | Visual delta | ARIA |
|-------|---------|--------------|------|
| `default` | Idle | Base styling | native `checked=false` |
| `hover` | Pointer over, not disabled | Border shifts to `color-mix(in oklab, var(--border) 60%, var(--foreground))` (Checkbox/Radio); track shifts one step toward primary (Switch off) | none |
| `focus-visible` | Keyboard focus | Focus ring per [`08-token-registry.md`](./08-token-registry.md) §9 | none |
| `checked` | Value selected | Fill `var(--primary)`; check/dot/thumb `var(--primary-foreground)` | native `checked=true` |
| `indeterminate` (Checkbox only) | Group-parent partial selection | Fill `var(--primary)`; horizontal bar glyph | `aria-checked="mixed"` |
| `disabled` | `disabled` prop | Opacity 0.5, `not-allowed` cursor | native `disabled` |
| `busy` (Switch only, §7) | Immediate-effect mutation in flight | Spinner replaces thumb; track fill uses `--muted` outline | `aria-busy="true"` |
| `invalid` (Checkbox/Radio, form use) | Field-level Validation | Border `var(--destructive)`; error copy in Field shell | `aria-invalid="true"` |

Never render `indeterminate` on Radio or Switch. Enforced by a type-level constraint in the component API.

## 5. Field composition (Checkbox and Radio in forms)

Checkbox and Radio in a form context compose inside the Field shell from [`18-component-input.md`](./18-component-input.md) §3, with these deltas:

- The Field label sits ABOVE the control(s), not to the left, for Radio groups and Checkbox groups. Single Checkboxes place the label to the right of the control.
- Radio groups use `<fieldset><legend>` for accessibility; visually the legend renders like Field label typography.
- Helper and error rules inherit from Input; error timing rules inherit from Select §4 (render on change-that-touches, not on blur, because Radio/Checkbox lack a meaningful blur event).
- Validation source rules inherit from Input §7.1: Zod schema at the form boundary OR server `Data.FieldErrors[fieldName]`. Client-only regex validation is banned identically.
- Server-side field errors bind identically via `Data.FieldErrors[fieldName]`.

Switch is NEVER a form field (see §7). It never composes inside a Field shell.

## 6. Group semantics

**Radio group** (`role="radiogroup"`):

- Exactly 2..7 options. Fewer than 2 is a boolean (use Checkbox or Switch); more than 7 is a Select.
- Arrow keys move selection AND focus (native radio semantics), not just focus. Enter/Space is a no-op inside a radio group; selection is committed by arrow key.
- Tab moves focus INTO the group at the checked option (or first enabled option if none checked) and OUT to the next form field. It does NOT walk between radios.
- Required groups render the required marker on the legend, not per option.

**Checkbox group** (`role="group"` with `aria-labelledby`):

- 1..N options; N above 7 SHOULD render inside a scrollable region with a header, not a Select (unlike Radio, "many booleans" is legitimate for feature flags).
- Tab walks each Checkbox individually.
- "Select all" MUST be an `aria-checked="mixed"`-capable parent Checkbox that toggles all children; it never appears as a plain link or Button.
- Deselecting all is allowed unless a Zod `.min(1)` rule renders a Validation error at submit.

## 7. Switch semantics (immediate-effect)

Switch is the ONLY primitive in this file that mutates state on change without a submit. Non-negotiable rules:

- Every Switch change is a server mutation call. There is NO "local state that persists on Save" Switch pattern; that is a Checkbox in a form.
- The mutation MUST send `Idempotency-Key` per [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md).
- Optimistic UI: the visual flips immediately on user action; the actual value only commits after the mutation succeeds. Enter `busy` state during the round-trip.
- Failure: revert to previous position, render an inline toast per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2 with `ErrorCode`, `RequestId`, and a "Retry" action that re-fires the SAME `Idempotency-Key`.
- Destructive intent (e.g. "Disable all environments") MUST NOT be a Switch. Route to a confirmation Dialog per [`17-component-button.md`](./17-component-button.md) §7.
- A Switch inside a permission-gated surface with insufficient permission renders as HIDDEN, not `disabled`, per the "hide vs disable" rule in [`13-navigation-ia.md`](./13-navigation-ia.md) §3.
- Rate-limit response (`429 RateLimited`): the Switch reverts, the surface renders a `<RetryAfterBanner>`; do not queue the toggle for retry after the countdown.

## 8. Copy rules

- Checkbox and Radio labels: sentence case, no trailing punctuation, no "Yes/No" pairs (that is a Switch or a Confirmation Dialog).
- Switch labels state what the switch DOES when on ("Send notifications", not "Notifications: on"). The on/off state is announced via `aria-checked`, not label text.
- Descriptions (optional Body/S below the label) explain the consequence of the on state.
- Never suffix the label with "(required)"; the fieldset's required marker on the legend carries that.

## 9. Keyboard contract

- Checkbox: Space toggles; Enter is a no-op (Enter submits the enclosing Form).
- Radio: Space selects the focused radio; arrow keys move-and-select within the group per §6; Enter is a no-op inside a group.
- Switch: Space toggles; Enter toggles (matches WAI-ARIA switch pattern); focus-visible ring wraps the whole track.

## 10. Telemetry

- Checkbox and Radio in forms: values captured by the enclosing Form's `FormSubmitted` log line per Form contract (Plan 06 Step 20); no per-toggle log.
- Switch: emits a `SettingToggled` log line at LogLevel=info on every server-committed change (not on optimistic flip, not on failure-revert), with fields `SettingKey`, `PreviousValue`, `NextValue`, `RequestId`, `TargetEntityType`, `TargetEntityId`. Failures emit a separate `SettingToggleFailed` line at LogLevel=warn with `ErrorCode` and `RequestId`.
- Switches bound to role/permission mutations emit `AssignmentIntent` per [`19-component-select.md`](./19-component-select.md) §11 in addition to `SettingToggled`.

## 11. Anti-patterns (forbidden)

- Using a Switch for a form field that persists on Save (that is a Checkbox).
- Using a Checkbox for a destructive setting toggle (that is a Button + Dialog).
- Using a Radio group for a 0..1 selection (either it's mandatory 1-of-N, or it's a single Checkbox).
- Rendering `indeterminate` on Radio or Switch.
- Placing a Switch in a form with a submit button.
- Firing a Switch mutation without `Idempotency-Key`.
- Queuing a rate-limited Switch toggle to retry silently after `Retry-After`.
- Disabling a permission-gated Switch instead of hiding it.
- Combining `disabled` with a tooltip on any of the three (see [`17-component-button.md`](./17-component-button.md) §5).
- Using an inline literal option list for a Radio or Checkbox group whose values are in the closed-set registry (§2).

## 12. Acceptance

- AC-CHC-001: Every Radio group has 2..7 options; groups with 1 option OR more than 7 options fail a component-level test.
- AC-CHC-002: Every Switch mutation sends `Idempotency-Key`; missing header fails a mutation-call unit test with a fake client.
- AC-CHC-003: Switch optimistic UI reverts on failure and re-uses the same `Idempotency-Key` on retry; verified by a Playwright test that intercepts the mutation, fails it, and asserts the retry request header equals the first attempt.
- AC-CHC-004: `SettingToggled` fires only on server-committed change; optimistic flips and failures do NOT emit it. `SettingToggleFailed` fires on failure with `ErrorCode`. Verified by a component test with a fake logger.
- AC-CHC-005: A permission-gated Switch renders HIDDEN, not disabled, when the caller lacks permission. Enforced by a route-level a11y test.
- AC-CHC-006: `aria-checked="mixed"` is set on group-parent Checkboxes only; setting it on Radio or Switch fails a lint rule enforced by ESLint rule `no-mixed-on-non-checkbox` (Plan 06 Step 46).
- AC-CHC-007: Focus ring on all three matches Input/Button/Select exactly; snapshot test asserts computed style.
- AC-CHC-008: Hit target is 40 x 40 px minimum; enforced by `linter-scripts/check-click-target-floor.py`.
- AC-CHC-009: Radio groups use `<fieldset><legend>`; a Radio group rendered without a legend fails the a11y suite.
- AC-CHC-010: Rate-limited Switch reverts and surfaces `<RetryAfterBanner>` without queueing; verified by a Playwright test.
- AC-CHC-011: Closed-set-parity extends to Radio/Checkbox groups per §2; inline option literals for registered enums fail `linter-scripts/check-closed-set-select.py`.
