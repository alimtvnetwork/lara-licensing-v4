# Component: Button

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for the Button primitive: variants, intents, sizes, states, loading behavior, destructive-action rules, keyboard contract, and the required log line for every activation. Every other component that renders a button (Dialog confirm, Toast action, Menu item acting as button, Sheet CTA) MUST inherit this contract rather than restate it. Steps 14-20 primitives and Steps 21-28 blueprints cite this file, not shadcn documentation.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md) §3 (Label role), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §12 (click-target floor), [`11-shape-and-motion.md`](./11-shape-and-motion.md) §1 (radius) and §3 (mutation motion), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md) §7 (page-actions), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3.4 (mutation loading) and §4.6 (retry semantics), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Purpose and non-purpose

Button represents a single, discrete user intent to perform an action. It is NOT:

- A navigation primitive (use `<a>`/`<Link>` styled as button-shaped only via the `link-as-button` composition rule in §12).
- A toggle (use Checkbox, Switch, or ToggleGroup).
- A dropdown trigger by itself (Menu owns dropdown grammar; Button MAY be Menu's trigger).

A control that primarily changes selection state or navigates is not a Button; miscategorization is the most common component drift and this rule is enforced by ESLint `button-vs-link` (Plan 06 Step 46).

## 2. Variant + intent grammar

Button's shape is decomposed into two orthogonal axes: **variant** (visual weight) and **intent** (semantic color role). Every Button props combination MUST specify exactly one variant and exactly one intent.

### 2.1 Variants

| Variant | Visual weight | Use when |
|---------|---------------|----------|
| `solid` | Filled background, high contrast | Primary action of a surface (page-actions, dialog confirm, form submit). Exactly one per surface per [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md) §7. |
| `outline` | Transparent background, `1px` border | Secondary actions in an action row; retry buttons in error surfaces. |
| `ghost` | No background, no border, foreground-only | Tertiary actions inside dense tables, table row menus, icon-only overflow triggers. |
| `link` | Underlined text, no padding, no min-size | Inline text-flow actions (e.g. "Undo" inside a toast body, "Learn more" inside a description). Bypasses the click-target floor by design; MUST be surrounded by enough passive whitespace that touch users can hit it. |

### 2.2 Intents

| Intent | Foreground | Background (solid) | Border (outline) | Semantic meaning |
|--------|------------|--------------------|--------------------|------------------|
| `neutral` | `var(--foreground)` | `var(--secondary)` | `var(--border)` | Default; every action that is neither the surface primary nor destructive. |
| `primary` | `var(--primary-foreground)` | `var(--primary)` | `var(--primary)` | The single canonical action per surface (issue license, save, sign in). |
| `destructive` | `var(--destructive-foreground)` | `var(--destructive)` | `var(--destructive)` | Actions that delete, revoke, or otherwise cannot be trivially undone. Governed by §7. |

`ghost` intent uses only the foreground column; background is transparent, border is none.

`link` intent uses only the foreground column; the underline sits on the `text-decoration-color: currentColor` line.

### 2.3 Legal variant/intent combinations

All 12 combinations are legal, but the following are strongly discouraged and require an inline comment justifying the exception:

- `ghost` + `destructive`: hard to notice; use `outline` + `destructive` unless the button lives inside a confirmation dialog whose title already conveys destructive intent.
- `link` + `destructive`: allowed only inside a confirmation dialog body (e.g. "Delete permanently"), never in a page-actions slot.

## 3. Sizes

| Size | Block size | Inline padding | Typography role | Icon size | Use when |
|:----:|:----------:|:--------------:|:---------------:|:---------:|----------|
| `sm` | `32px` | `--space-3` (12px) | Label/S | 16px | Table row actions, dense toolbars, filter chips. |
| `md` | `40px` (**default**) | `--space-4` (16px) | Label/M | 16px | Everything by default. Matches the [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §12 click-target floor. |
| `lg` | `48px` | `--space-5` (20px) | Label/L | 20px | Auth screens (sign-in submit), hero CTAs on public shell, primary confirm inside dialogs on mobile. |

Icon-only buttons MUST use size `md` or larger (block-size 40px+) to satisfy the touch-target floor. `sm` icon-only is a spec violation and fails `linter-scripts/check-icon-button-size.py` (Plan 06 Step 46).

## 4. States

| State | Trigger | Visual delta | ARIA |
|-------|---------|--------------|------|
| `default` | Idle | Base variant/intent styling | none |
| `hover` | Pointer over, not pressed, not disabled | Background shifts via `color-mix(in oklab, currentColor 6%, transparent)` for `solid`; foreground shifts via `color-mix(in oklab, currentColor 8%, var(--foreground))` for `ghost`/`link` | none |
| `focus-visible` | Keyboard focus | Focus ring per [`08-token-registry.md`](./08-token-registry.md) §9: `outline: 2px solid var(--ring); outline-offset: 2px` | none |
| `active` | Pointer/keyboard press | Transform `scale(0.98)`; duration 100 ms `ease-out`. Suppressed under `prefers-reduced-motion`. | none |
| `disabled` | `disabled` prop true | Opacity 0.5; pointer-events none; cursor `not-allowed` | `aria-disabled="true"` (see §5) |
| `loading` | `loading` prop true | Icon slot replaced by spinner glyph; label unchanged; pointer-events none | `aria-busy="true"`, `aria-disabled="true"` |
| `success flash` (optional) | Post-mutation success signal | Icon momentarily swapped to check glyph for 800 ms | none |

Hover is suppressed on touch devices via `@media (hover: hover)`.

## 5. Disabled vs aria-disabled

Two distinct disabled semantics exist and MUST be chosen deliberately:

- **`disabled` HTML attribute**: the control is not focusable, not tab-reachable, and screen readers announce it as unavailable. Use ONLY when the disabled state is not user-actionable and needs no explanation (e.g. a form submit while its fields are pristine).
- **`aria-disabled="true"` with `disabled` NOT set**: the control remains focusable and tab-reachable but ignores activation. Use when the reason for disabling needs a tooltip or when the user should be able to focus it and read a precondition. This is the default per [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md) §7 for page-actions with unmet preconditions.

Never combine `disabled` with a tooltip; the tooltip will never appear because focus cannot land on a natively-disabled control. That combination fails `linter-scripts/check-disabled-tooltips.py` (Plan 06 Step 46).

## 6. Loading behavior

Governed by [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3.4.

- Enter loading synchronously on activation (`onClick` fires -> set loading true in the same tick).
- Spinner replaces the leading icon slot, or occupies the leading slot if no icon existed. Label text stays visible for context.
- `aria-busy="true"` and `aria-disabled="true"` set; pointer-events none.
- After 4 seconds still loading, an inline caption "Still working..." renders below the action row (owned by the surface, not the Button itself; Button emits a `still-working` event at 4 s).
- On resolution: loading false, optional 800 ms success flash for `primary` intent only, then focus SHOULD move to the next logical element per the surface's blueprint (form advances to next field; dialog closes and returns focus to the trigger).
- If the mutation fails: loading false, focus returns to the Button, and the surface renders the error per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2. Button MUST NOT render its own error message; the surface owns error presentation.

## 7. Destructive actions

Destructive intent (`intent="destructive"`) is gated by the following contract:

1. A destructive Button MUST live inside a confirmation Dialog (Plan 06 Step 18) OR at the end of an overflow Menu, never as a top-level page-action or table row primary action per [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md) §7.
2. The label MUST use an explicit destructive verb ("Revoke", "Delete", "Remove"); "OK" and "Confirm" are forbidden.
3. Activation MUST fire the mutation only after a two-step confirmation (Dialog primary click OR Menu -> Dialog). Inline single-click destruction is a spec violation.
4. The mutation contract MUST include an `Idempotency-Key` per [`../21-app/11-api-contracts/08-idempotency-envelope-hardening.md`](../21-app/11-api-contracts/08-idempotency-envelope-hardening.md); Button MUST NOT be reused for non-idempotent destructive actions.

## 8. Keyboard contract

- `Enter` and `Space` activate the Button when focused. Both keys MUST be handled; missing `Space` handling fails the a11y suite.
- `Tab` moves focus to the next tabbable element; `Shift+Tab` to the previous.
- On mount inside a Dialog, the primary Button receives autofocus unless the Dialog contains a form; in that case the first form field wins per Dialog contract (Plan 06 Step 18).
- Icon-only buttons MUST provide `aria-label` matching the tooltip text; the tooltip and `aria-label` MUST be identical.

## 9. Telemetry (log line contract)

Every Button activation that triggers a mutation (any Button whose `onClick` calls a server function) MUST emit a client log line at `info` per [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md):

| Field | Value |
|-------|-------|
| `Event` | `ButtonActivated` |
| `Route` | current route path |
| `ButtonId` | stable identifier declared via the `data-log-id` prop (kebab-case) |
| `Intent` | one of `neutral`, `primary`, `destructive` |
| `UserId` | from `me` |
| `RequestId` | populated by the resulting mutation's `X-Request-Id` (may be null if activation failed before the network call) |

Buttons whose `onClick` performs pure client-side work (open dialog, toggle disclosure) do NOT emit `ButtonActivated`; the resulting Dialog or disclosure emits its own event per its own component contract. This split prevents log noise while preserving observability at the mutation edge.

## 10. Composition rules

### 10.1 Icon + label

- Leading icon: 8px inline gap before label. Trailing icon: 8px after.
- At most one leading and one trailing icon.
- Icons MUST come from the icon set defined in the component catalog opener (this file's §11); mixing icon families (lucide-react + heroicons) is a spec violation.

### 10.2 Loading + icon

If the Button has a leading icon, loading replaces it. If the Button has only a trailing icon, loading replaces the trailing icon (rare but allowed). If the Button has both leading and trailing icons, loading replaces the leading icon and hides the trailing icon until resolution.

### 10.3 Button groups

A row of buttons MUST render with `gap: --space-3` (12px), no dividers, no shared border. Grouped buttons that share a border (segmented control) are NOT Buttons; they are the ToggleGroup primitive (out of scope for Plan 06 v1).

### 10.4 Full-bleed buttons

Mobile forms (`shell-public` sign-in) MAY render the primary submit as `inline-size: 100%`. Desktop MUST use intrinsic size; a full-bleed desktop button fails the visual regression baseline.

## 11. Icon set binding

The project uses `lucide-react` exclusively. Any Button icon MUST import from `lucide-react`. Mixing icon families is a spec violation enforced by `linter-scripts/check-icon-source.py` (Plan 06 Step 46).

## 12. Link-as-button composition

An `<a>` (or TanStack `<Link>`) styled to look like a Button MUST NOT set `role="button"`; it remains a link semantically. Use the `linkButtonClass()` helper (declared in the runtime implementation, Plan 06 Step 21+) to apply Button variant/intent classes to an anchor without swapping semantics. A styled anchor with `role="button"` fails the a11y suite because middle-click, Ctrl+click, and "open in new tab" break when the browser treats it as a button.

## 13. Anti-patterns (forbidden)

- Two `primary` Buttons in the same action row.
- `destructive` intent as a top-level page-action.
- Icon-only Button without `aria-label`.
- Button whose `onClick` throws without a surrounding surface-level error boundary.
- Button styled to look like a link (blue underlined text) but using `<button>`; use `link` variant instead.
- `disabled` combined with a tooltip.
- Button that mutates state on hover, pointer-enter, or long-press. Activation is `click`/`Enter`/`Space` only.

## 14. Acceptance

- AC-BTN-001: Every Button in `src/**` uses the Button primitive from `src/components/ui/button.tsx` (post-Step 21 implementation); direct usage of a bare `<button>` for anything other than a11y test fixtures fails `linter-scripts/check-button-usage.py` (Plan 06 Step 46).
- AC-BTN-002: Every mutation-triggering Button carries a stable `data-log-id` and emits `ButtonActivated` per §9; the test suite asserts the log line for a representative sample (Plan 06 Step 44).
- AC-BTN-003: No surface renders two `primary` Buttons in the same action row; enforced by the visual regression harness (Plan 06 Step 44).
- AC-BTN-004: Every destructive Button lives inside a confirmation Dialog or overflow Menu; `linter-scripts/check-destructive-context.py` (Plan 06 Step 46) enforces this by AST scan.
- AC-BTN-005: Icon-only Buttons declare `aria-label`; the a11y suite fails if any lack it.
- AC-BTN-006: `disabled` and tooltip never co-occur on the same Button; enforced per §5.
- AC-BTN-007: Loading Buttons set `aria-busy="true"` and preserve label text; enforced by a component-level test.
- AC-BTN-008: Focus ring uses `var(--ring)` per [`08-token-registry.md`](./08-token-registry.md) §9; snapshot test asserts computed style.
- AC-BTN-009: All Button icons resolve to `lucide-react`; drift fails `linter-scripts/check-icon-source.py` (Plan 06 Step 46).
- AC-BTN-010: Every `<a>` or `<Link>` styled with `linkButtonClass()` does NOT set `role="button"`; enforced by AST scan.
- AC-BTN-011: Button size `sm` icon-only is absent from `src/**`; enforced by `linter-scripts/check-icon-button-size.py`.
- AC-BTN-012: The success-flash animation is suppressed under `prefers-reduced-motion`; enforced by the motion sweep (Plan 06 Step 39).
