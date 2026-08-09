# Component: Input

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for the Input primitive (single-line text) and its close variants (email, password, search, number). TextArea inherits every rule here except the multiline geometry noted in §11. Steps 15-20 primitives and Steps 21-28 blueprints cite this file rather than shadcn documentation.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md) §3 (Body role), §6 (Identifier rule), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §8 (form density), [`11-shape-and-motion.md`](./11-shape-and-motion.md) §1 (radius), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2 (Validation surface), [`17-component-button.md`](./17-component-button.md) §5 (`aria-disabled` semantics), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md) §Validation, [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Purpose and non-purpose

Input captures a single line of free-form or lightly-constrained user text. It is NOT:

- A closed-set choice (Select, Radio, Checkbox).
- An identifier renderer (`<Identifier>` from [`09-typography-scale.md`](./09-typography-scale.md) §6 renders read-only identifiers; Input never renders `LicenseCode` or `SerialValue` in a read-only surface).
- A rich text editor.

## 2. Geometry

| Property | Value | Source |
|----------|-------|--------|
| Block size | `40px` | [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §8 |
| Inline padding | `--space-3` (12px) | §8 |
| Border | `1px solid var(--border)` | tokens |
| Border radius | `--radius-md` | [`11-shape-and-motion.md`](./11-shape-and-motion.md) §1 |
| Typography role | Body/M | [`09-typography-scale.md`](./09-typography-scale.md) §3 |
| Font family | `var(--font-sans)` except `type="password"` and numeric inputs displaying identifiers, which use `var(--font-mono)` |
| Numeric variant | `font-variant-numeric: tabular-nums` when `inputMode="numeric"` or `type="number"` |

A dense variant with block-size `32px` and Label/S typography is allowed only inside a Table row filter row per [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §8 Compact density; it is banned in forms.

## 3. Anatomy

Every Input is composed inside a **Field** shell:

```
<div class="field" data-invalid={invalid}>
  <label for={id}>Label text</label>
  <span class="field-required" aria-hidden>*</span>   {/* if required */}
  <div class="input-affix">
    <span class="leading-icon" aria-hidden />         {/* optional */}
    <input id={id} aria-describedby={describedByIds} />
    <span class="trailing-icon-or-action" />          {/* optional */}
  </div>
  <p class="field-helper" id={helperId}>Helper text</p>
  <p class="field-error" id={errorId} role="alert">Error text</p>
</div>
```

- `label` is always visible (no floating-label pattern). Visually-hidden labels are permitted only for the topbar search Input; every other Input MUST render its label.
- The required marker `*` is `aria-hidden` because the requirement is announced via `aria-required="true"` on the `<input>`.
- Helper and Error occupy the same 20 px block area; only one renders at a time. The block area is reserved by `min-block-size: 20px` on the wrapper to prevent layout shift on error.
- `aria-describedby` on `<input>` MUST list the helper id when helper renders, and the error id when error renders. Never both.

## 4. Label and helper copy rules

- Label: Sentence case, no trailing colon, no trailing punctuation. Max 40 chars.
- Helper: One sentence, states expectation ("Must be a valid email address."). Not the error message. Optional.
- Error: One sentence, states the failure and how to fix it ("Enter a valid email address, for example name@example.com."). Never blames.
- No abbreviations without expansion on first use in a form.
- Copy MUST be i18n-ready per [`09-typography-scale.md`](./09-typography-scale.md) §8: no concatenation, no runtime interpolation of untranslated strings.

## 5. States

| State | Trigger | Visual delta | ARIA |
|-------|---------|--------------|------|
| `default` | Idle | Base styling | `aria-invalid="false"` |
| `hover` | Pointer over, not disabled | Border shifts to `color-mix(in oklab, var(--border) 60%, var(--foreground))` | none |
| `focus-visible` | Keyboard focus | Focus ring per [`08-token-registry.md`](./08-token-registry.md) §9: `outline: 2px solid var(--ring); outline-offset: 2px`; border color shifts to `var(--ring)` | none |
| `disabled` | `disabled` prop true | Opacity 0.5; cursor `not-allowed`; background `var(--muted)` | native `disabled` (see §6) |
| `readonly` | `readOnly` prop true | Background `var(--muted)`; border stays; cursor `default` | `aria-readonly="true"` |
| `invalid` | `aria-invalid="true"` and error is rendered | Border color `var(--destructive)`; error icon in trailing slot | `aria-invalid="true"`, `aria-describedby={errorId}` |
| `busy` | async validation in flight | Trailing slot shows spinner glyph; input remains editable | `aria-busy="true"` on wrapper |

Never render the invalid state pre-emptively; see §7.2 for the timing rule.

## 6. Disabled vs readOnly vs aria-disabled

- `disabled`: value cannot be edited, control is skipped by Tab, value is NOT submitted with the form. Use only when the field is not applicable to the current mode (e.g. reseller admin editing a license they cannot re-tier).
- `readOnly`: value cannot be edited but IS submitted with the form and IS tab-reachable. Use when the value is a computed or previously-set identifier the user should see and copy.
- `aria-disabled="true"` without native `disabled`: reserved for the case where the field is temporarily inactive with a precondition tooltip. Rare on Input; more common on Button per [`17-component-button.md`](./17-component-button.md) §5.

Never combine `disabled` with a tooltip; per [`17-component-button.md`](./17-component-button.md) §5 the tooltip never fires. Enforced by `linter-scripts/check-disabled-tooltips.py`.

## 7. Validation

### 7.1 Error source

Every Input error MUST originate from one of:

- A Zod schema at the form boundary (client-side pre-flight).
- A server response with `ErrorCode = Validation` (or feature-specific `FeatureUnknown`, `FeatureValueInvalid`, `EnvironmentMismatch`) whose `Data.FieldErrors[fieldName]` array populates the Input's error slot per [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md) §Validation.

Client-fabricated errors (regex checks not backed by a Zod schema) are banned; every rule that matters lives in the Zod schema so form and server share validation semantics.

### 7.2 Timing

- Do NOT render an error while the user is typing.
- Render an error on: (a) blur if the field has been touched AND is invalid, (b) form submit attempt for every invalid field, (c) server response for server-side field errors.
- Clear the error on next input (`onChange`) once the field becomes valid; the error MAY stay visible while the field is still invalid but MUST NOT block continued typing.
- Server-side field errors clear on the next successful submit or on `onChange` if the field's value differs from the value that produced the server error.

### 7.3 `FieldErrors` shape

Server envelope's `Data.FieldErrors` follows this shape (declared in the API contracts and cited here for UI binding):

```
FieldErrors: { [fieldName]: string[] }  // PascalCase field names, human-readable strings
```

The Field component renders `FieldErrors[fieldName][0]`; additional strings in the array are dropped in the UI but MUST be visible in the RequestId-linked server log line per [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

### 7.4 Form-level banner

If a submit fails with `ErrorCode = Validation` AND `Data.FieldErrors` is empty (server-level validation with no field mapping), the surface renders a form-level banner per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2, not a field-level error. Never invent a field mapping the server did not provide.

## 8. Password variant

- `type="password"` renders with `var(--font-mono)` to prevent letter-shape ambiguity.
- A trailing "Show password" toggle (icon-only Button, `ghost`/`neutral`, size `sm` inline within the input-affix wrapper) is REQUIRED on Auth screens and OPTIONAL elsewhere.
- Password managers MUST be permitted; do NOT set `autocomplete="off"` on password Inputs.
- `aria-live` announcements when toggling visibility: none. The visibility state is announced via the toggle Button's `aria-pressed`.

## 9. Numeric variant

- Use `inputMode="numeric"` with `type="text"` for identifiers and quota values (avoids native spinner UI).
- Use `type="number"` ONLY for fields that need browser-native increment/decrement (rare in this app).
- Tabular numerics per §2. Right-align the value only inside a Table cell filter; forms keep left alignment for reading rhythm.
- Locale-aware separators: the Input stores unformatted numeric strings; the display formatting (thousands separators) is applied on blur and stripped on focus. The stored value is always canonical (no separators).

## 10. Search variant

- `type="search"` renders with a leading `Search` icon and a trailing `X` clear button that appears only when the field is non-empty.
- Debounced value change events (250 ms) are the caller's responsibility, not the Input's; the Input surfaces raw `onChange` synchronously.
- The topbar search Input MAY visually-hide its label; every other search Input MUST render its label.

## 11. TextArea

TextArea inherits every rule above with these deltas:

- Block size auto-expands from `80px` minimum to `240px` maximum; scroll internally beyond that.
- `--radius-md`, same border and focus rules.
- Character count indicator (Label/S, `--muted-foreground`) renders inline-end of the helper row when `maxLength` is set; it turns `var(--destructive)` at 90% capacity.
- Resize handle: `resize: vertical` allowed; `resize: both` and `resize: horizontal` are banned.

## 12. Keyboard contract

- `Tab` moves focus in DOM order; `Shift+Tab` reverses.
- `Enter` inside a single-line Input submits the enclosing Form when the Form declares an implicit submit button per Form contract (Plan 06 Step 20).
- `Enter` inside a TextArea inserts a newline; `Ctrl+Enter` or `Cmd+Enter` submits.
- `Escape` inside a Dialog-hosted Input dismisses the Dialog only if the Input is empty or unchanged; otherwise it clears the current selection and the second `Escape` dismisses. This prevents accidental data loss.

## 13. Telemetry

Input does NOT emit a per-keystroke log line (privacy and volume). Input DOES contribute to two logs owned by other components:

- Form submit fires `FormSubmitted` per Form contract (Plan 06 Step 20) with `FieldNames` (field names that had non-empty values, NEVER their values).
- Server-side field errors fire `FormValidationFailed` per Form contract with `FieldNames` (invalid) and `RequestId`.

Never log field values. `linter-scripts/check-log-line-secrets.py` (Plan 06 Step 46) enforces this by scanning client logger call sites for any argument that resolves to an Input value.

## 14. Anti-patterns (forbidden)

- Floating labels (label as placeholder that animates on focus).
- Placeholder as the sole label.
- Client-only regex validation not backed by a Zod schema.
- Rendering an error while the user is still typing.
- Logging Input values.
- Combining `disabled` with a tooltip.
- Using Input to render read-only identifiers (use `<Identifier>` per [`09-typography-scale.md`](./09-typography-scale.md) §6).
- Numeric Inputs with formatted values in the DOM value (formatting is display-only).

## 15. Acceptance

- AC-INP-001: Every Input in `src/**` uses the Field composition from §3; a bare `<input>` outside a Field fails `linter-scripts/check-input-composition.py` (Plan 06 Step 46).
- AC-INP-002: Every Input has a visible `<label>` OR a documented visually-hidden label exception (topbar search); enforced by the a11y suite (Plan 06 Step 45).
- AC-INP-003: `aria-describedby` lists the helper id when helper renders and the error id when error renders, never both; enforced by a component-level test.
- AC-INP-004: Error rendering timing follows §7.2; enforced by a Playwright test that types into a field and asserts no error appears mid-typing.
- AC-INP-005: Field errors originate from Zod OR server `Data.FieldErrors`; client-fabricated regex validation not tied to a Zod schema fails `linter-scripts/check-validation-source.py` (Plan 06 Step 46).
- AC-INP-006: Password inputs use `var(--font-mono)` and permit password managers; `autocomplete="off"` on `type="password"` fails the same linter.
- AC-INP-007: Numeric Inputs store canonical unformatted strings; a formatted DOM value in a numeric Input fails a component-level test.
- AC-INP-008: Input never logs its value; enforced per §13.
- AC-INP-009: TextArea `resize` is `vertical` or `none`, never `both` or `horizontal`; enforced by a CSS lint rule (Plan 06 Step 46).
- AC-INP-010: Focus ring uses `var(--ring)` and matches Button focus ring exactly; snapshot test asserts computed style.
