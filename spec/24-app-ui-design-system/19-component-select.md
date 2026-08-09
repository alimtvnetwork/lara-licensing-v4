# Component: Select

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for the Select primitive: closed-set option binding, ordering, empty-set behavior, disabled options, group headers, keyboard contract, and the closed-set-parity rule that ties every Select in the app to a normative enum spec file. Steps 16-20 primitives and Steps 21-28 blueprints cite this file rather than shadcn documentation.
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`10-spacing-and-rhythm.md`](./10-spacing-and-rhythm.md) §8 (form density), §10 (popover paddings), [`11-shape-and-motion.md`](./11-shape-and-motion.md) §4 (popover motion), [`17-component-button.md`](./17-component-button.md) (trigger shape), [`18-component-input.md`](./18-component-input.md) §3 (Field composition), §7 (Validation timing), [`../21-app/05-license-categories.md`](../21-app/05-license-categories.md), [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md), [`../21-app/44-environments.md`](../21-app/44-environments.md), [`../21-app/04-roles.md`](../21-app/04-roles.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/45-license-features.md`](../21-app/45-license-features.md).

---

## 1. Purpose and non-purpose

Select captures a single-value choice from a closed, spec-defined set. It is NOT:

- A free-form Input (use Input).
- A multi-value choice (use a MultiSelect primitive, out of scope for Plan 06 v1; use Checkbox group instead).
- A boolean toggle (use Checkbox or Switch).
- An auto-suggest lookup against an open corpus (use a Combobox primitive, out of scope for Plan 06 v1).

## 2. Closed-set parity rule (foundation)

Every Select in the app MUST bind its options to a normative enum declared in `spec/21-app/` or `spec/23-app-db/`. The following registry is exhaustive for v1:

| Select purpose | Option source (spec file) | Runtime source (Zod schema) | Ordering |
|----------------|---------------------------|-----------------------------|----------|
| License category | [`../21-app/05-license-categories.md`](../21-app/05-license-categories.md) ordinals 1..7 | `LicenseCategoryOrdinalSchema` in `src/lib/lara-license.ts` | Ascending ordinal (1..7) |
| License tier | [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md) `Tier1..Unlimited` | `LicenseTierIdSchema` in `src/lib/lara-license.ts` | Spec table order |
| Environment | [`../21-app/44-environments.md`](../21-app/44-environments.md) `Production/Staging/Development` | `EnvironmentIdSchema` in `src/lib/lara-license.ts` | `Production`, `Staging`, `Development` (canonical) |
| Role | [`../21-app/04-roles.md`](../21-app/04-roles.md) `Admin/Reseller/AppBuilder/EndUser` | `AppRoleSchema` in `src/lib/lara-me.ts` | Spec table order |
| Permission key | [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §2 (v1.2.0) | `PermissionKeyType` runtime enum (Plan 05 Step 41) | Spec table order; deprecated keys rendered as disabled options with a "(deprecated)" suffix per §6 |
| Feature key | [`../21-app/45-license-features.md`](../21-app/45-license-features.md) `Features` catalog | `FeatureKeySchema` in `src/lib/lara-features.ts` | Alphabetical by key |
| Quota request status | [`../21-app/42-quota-requests.md`](../21-app/42-quota-requests.md) `Pending/Approved/Denied/Cancelled` | `QuotaRequestStatusSchema` in `src/lib/lara-quota.ts` | Spec state-machine order |
| License lifecycle status filter | [`../21-app/15-license-lifecycle.md`](../21-app/15-license-lifecycle.md) states | `LicenseStatusSchema` | Spec state-machine order |
| Audit action filter | [`../21-app/28-audit-action-enum.md`](../21-app/28-audit-action-enum.md) | `AuditActionSchema` | Alphabetical |

Adding a Select whose options are not in this registry requires:

1. A row here binding purpose to spec file and runtime schema.
2. A migration if the underlying set is stored in the database.
3. A parity test in `tests/closed-set-parity.test.ts` asserting the runtime schema equals the spec table verbatim.

Enforced by `linter-scripts/check-closed-set-select.py` (Plan 06 Step 46): every `<Select>` in `src/**` MUST bind options via a helper (`useClosedSet(<enum-name>)`) that resolves to a row above. Inline literal `options={[...]}` arrays for these sets fail the linter.

## 3. Trigger geometry

The Select trigger inherits Input geometry (Field shell from [`18-component-input.md`](./18-component-input.md) §3), with these deltas:

- Trailing icon slot ALWAYS renders a `ChevronsUpDown` glyph (16 px, `var(--muted-foreground)`).
- The trigger renders the current option's label using Body/M; if a leading icon is bound to the option (rare, e.g. Role icon), it renders in the leading slot.
- Empty state (no value): placeholder text in `var(--muted-foreground)`. Placeholder copy is "Select a <thing>..." (with ellipsis character `…`, not three dots). Placeholder is NOT a substitute for a label; the visible Field label above still renders per §4.

## 4. Label, helper, and error

Every Select composes inside a Field shell per [`18-component-input.md`](./18-component-input.md) §3. Label rules, helper rules, and error rules all inherit from Input; the deltas are:

- Error timing: Select errors render on close of the listbox (not on blur of the trigger, because clicking an option briefly loses focus). If the user opens and closes the listbox without selecting, treat as touched.
- Empty-set option handling: see §5.
- Server-side field errors bind identically to Input via `Data.FieldErrors[fieldName]` per [`18-component-input.md`](./18-component-input.md) §7.3.

## 5. Empty-set behavior

Three empty scenarios exist and MUST be handled distinctly:

- **Empty because the set is legitimately empty in this context** (e.g. Feature Select in a form for a tier with zero features): render the trigger as `disabled` with `aria-disabled="true"` and helper copy "No <things> available for this <parent>." Do NOT render an empty listbox; the user should not open it.
- **Empty because the caller lacks read permission**: the parent surface renders `AuthzPermissionDenied` per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §4.2; the Select never renders. Never infer authorization from an empty option list per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §5.4.
- **Empty because options are still loading**: the trigger renders a skeleton bar per [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md) §3.1 (Form skeleton row). Do NOT show a placeholder like "Loading..." inside the trigger; skeleton is the loading contract.

## 6. Disabled options

Individual options MAY be disabled with `aria-disabled="true"` when:

- The option is deprecated per its spec file (e.g. `Audit.Read` in `40-permissions.md` v1.2.0). Rendered with a `(deprecated)` suffix in `var(--muted-foreground)` and NOT selectable. Existing values equal to a deprecated option MUST still render as the trigger value (with the suffix) so historical records remain readable.
- The option is not applicable in the current context (e.g. `Tier1` when the reseller's quota only permits `Tier2+`). Rendered with a tooltip explaining the precondition; the tooltip becomes reachable because `aria-disabled` (not native `disabled`) is used.

Native `disabled` attribute on `<option>` (in a `role="option"` element) is banned; it removes focus reachability and hides the tooltip. Per [`17-component-button.md`](./17-component-button.md) §5 and [`18-component-input.md`](./18-component-input.md) §6.

## 7. Grouping

Options MAY be grouped under a group header when the closed set has natural sub-categories (e.g. `PermissionKey` grouped by domain: `Licenses.*`, `Serials.*`, `Users.*`, etc.). Rules:

- Group header uses Label/S, `var(--muted-foreground)`, `text-transform: none`, sticky within the listbox scroll.
- Group ordering follows the spec file's section order; within a group, option ordering follows §2's Ordering column.
- Groups are visual only; keyboard navigation (§9) does not skip group headers, it announces them via `aria-labelledby` on `role="group"`.

## 8. Listbox geometry and motion

- Popover uses `--radius-md` and elevation-1 per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §2 (overlays get shadow; base surfaces do not).
- Max block-size: `min(320px, calc(100vh - --shell-topbar - 2 * --space-4))`; internal scroll beyond that.
- Enter motion: `attach-and-detach` recipe per [`11-shape-and-motion.md`](./11-shape-and-motion.md) §3 with `@starting-style`.
- Positioning: prefers below the trigger; flips above when clipped by viewport. Never overlaps the trigger; gap `--space-1` (4px).
- Width: min inline-size equals the trigger inline-size; MAY grow to fit the widest option up to `1.5 * trigger inline-size`.

## 9. Keyboard contract

Follows the WAI-ARIA Authoring Practices listbox pattern:

- `Enter`, `Space`, `ArrowDown`, `ArrowUp` open the listbox from the trigger.
- Once open: `ArrowDown`/`ArrowUp` move active-descendant highlight; `Home`/`End` jump to first/last enabled option; `Enter`/`Space` select and close; `Escape` closes without selecting; `Tab` selects the highlighted option and moves focus to the next form field (matches native `<select>` behavior).
- Typeahead: typing letters focuses the first option whose label starts with the typed prefix. Reset after 500 ms of no typing.
- Group headers are not focusable; typeahead and arrow navigation skip them.

## 10. Multi-role and multi-tenant safety

Selects that render user identities (Assign role, Assign reseller) MUST filter their option list server-side; never send an unauthorized identity to the client and hide it in CSS. The v0.141.0 identity gate rule ("`me.ResellerId` verified server-side") extends here: the Select's option source is always a server response scoped to the caller's row-scope, never a client-side filter over a global list.

## 11. Telemetry

Select emits no per-open or per-select log line by default. Two exceptions:

- Selects bound to `Roles.Assign` or `Permissions.Assign` mutations emit `AssignmentIntent` at LogLevel=info on selection change, with fields `TargetUserId`, `AssignedValue` (the role or permission key), `RequestId` (null until the confirming submit fires).
- Selects bound to destructive tier changes (downgrade) emit `DestructiveIntent` at LogLevel=info on selection change.

Values from other Selects (category, tier, environment, feature) are captured in the enclosing form's `FormSubmitted` log line per Form contract (Plan 06 Step 20) with their PascalCase field names, not values.

## 12. Anti-patterns (forbidden)

- Inline literal `options` for any purpose listed in §2; use `useClosedSet(...)`.
- Placeholder as sole label.
- Rendering "Loading..." text inside the trigger instead of a skeleton.
- Empty listbox opened with "No results" text; the trigger MUST be disabled instead per §5.
- Native `disabled` attribute on `role="option"` elements.
- Client-side filtering of identity options to hide unauthorized rows.
- Combobox behavior (typing to filter a large open set) inside a Select; that is a distinct primitive, out of scope for v1.
- Multi-select checkboxes inside a Select popover.

## 13. Acceptance

- AC-SEL-001: Every Select in `src/**` binds its options via a `useClosedSet(...)` helper that resolves to a row in §2; drift fails `linter-scripts/check-closed-set-select.py` (Plan 06 Step 46).
- AC-SEL-002: Every closed set in §2 has a parity test in `tests/closed-set-parity.test.ts` asserting the runtime schema equals the spec table verbatim; missing tests fail CI.
- AC-SEL-003: Deprecated options render with `(deprecated)` suffix and are `aria-disabled`, but historical values equal to a deprecated key still render as the current trigger value; enforced by a component-level test.
- AC-SEL-004: Empty-because-context uses `disabled` trigger + helper copy per §5; empty-because-permission renders `AuthzPermissionDenied` at the parent surface, not an empty Select.
- AC-SEL-005: Listbox motion uses `attach-and-detach` with `@starting-style`; hard-coded `@keyframes` fail the motion audit (Plan 06 Step 39).
- AC-SEL-006: Keyboard behavior matches WAI-ARIA listbox pattern; verified by a Playwright a11y test hitting Enter/Space/Arrow/Home/End/Escape/Tab/typeahead.
- AC-SEL-007: Identity Selects (Role, Permission, Reseller assignment) source their option list from a scoped server response; a client-side filter over a global list fails a code review checklist item enforced by `linter-scripts/check-identity-select-source.py` (Plan 06 Step 46).
- AC-SEL-008: `AssignmentIntent` and `DestructiveIntent` log lines fire on selection change for the Selects listed in §11; verified by a component-level test with a fake logger.
- AC-SEL-009: Focus ring on the trigger matches Input and Button focus rings exactly; snapshot test asserts computed style.
- AC-SEL-010: Group headers are not focusable and are announced via `aria-labelledby` on `role="group"`; verified by the a11y suite.
- AC-SEL-011: `linter-scripts/check-closed-set-select.py` runs on every PR and rejects new inline `options` literals for any registered purpose; PRs adding a new purpose MUST add a row to §2 in the same PR.
