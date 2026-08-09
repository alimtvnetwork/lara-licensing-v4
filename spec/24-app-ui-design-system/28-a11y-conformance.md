# Accessibility Conformance (WCAG 2.2 AA)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** Single normative source for WCAG 2.2 Level AA conformance across every shipped component (`17`..`25`) and every future per-surface blueprint (`29-per-surface-blueprints/*`). This file is the bar every route MUST clear before it can be marked "done".
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`11-shape-and-motion.md`](./11-shape-and-motion.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`20-component-choice.md`](./20-component-choice.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`26-iconography-and-assets.md`](./26-iconography-and-assets.md), [`27-content-voice.md`](./27-content-voice.md).

---

## 1. Conformance target

Target: WCAG 2.2 Level AA against the shipped light and dark themes, at the fluid viewport range in `06-fluid-design-foundations.md` (360..1920 CSS px), using the assistive tech baseline in §9.

Level AAA is NOT a target. AAA-only Success Criteria (contrast 7:1, sign language, extended audio description) are OUT OF SCOPE for v1 and MUST NOT be used to justify design decisions.

## 2. Perceivable

### 2.1 Color contrast (SC 1.4.3, 1.4.11)

- Body text on any surface MUST meet 4.5:1 contrast; large text (≥ 18.66 px regular or ≥ 14 px 700) MUST meet 3:1.
- Interactive component boundaries (Button border, Input border, focus ring, checked Checkbox mark) MUST meet 3:1 against adjacent color.
- Placeholder text MUST meet 4.5:1 and MUST NOT be the only cue for field purpose (`18-component-input.md` §2 already bans placeholder-as-label).
- Disabled controls are EXEMPT per SC 1.4.3 note but MUST still meet 3:1 boundary contrast so their disabled state is discernible.
- Badge tone tokens in `25-component-badge-status.md` §2 are pre-validated against `--surface` and `--surface-raised`; rendering Badge on any other background REQUIRES a re-validation entry in `08-token-registry.md`.

### 2.2 Non-color cues (SC 1.4.1)

- State MUST be communicated by at least two of: label, icon, shape, position. Color alone is BANNED.
- Sort direction on Table headers: mandatory `ArrowUp` / `ArrowDown` glyph (`24-component-table.md` §6).
- Required Field: mandatory "(required)" in the accessible name via `aria-required="true"`; NOT a color-only asterisk. `18-component-input.md` §2 marks optional; required is default.
- Toast tone: mandatory leading icon (`26-iconography-and-assets.md` §5).

### 2.3 Resize and reflow (SC 1.4.4, 1.4.10)

- Every surface MUST reflow at 320 px CSS width and 400% zoom without horizontal scrolling, EXCEPT for `<table>` primitives which MAY scroll horizontally within their scroll container (Table's RecordCard fallback per `24-component-table.md` §11 satisfies reflow below 720 px).
- Text MUST remain fully visible at 200% text-only zoom; container queries in `06-fluid-design-foundations.md` MUST NOT collapse content below the "one primary action visible" threshold.

### 2.4 Text spacing (SC 1.4.12)

Content MUST NOT clip when a user applies: line-height 1.5 × font-size, paragraph spacing 2 × font-size, letter-spacing 0.12 × font-size, word-spacing 0.16 × font-size. Fixed-height text containers with `overflow: hidden` are BANNED for prose.

### 2.5 Content on hover / focus (SC 1.4.13)

Any content revealed on hover or focus (Menu trigger, Popover, inline validation error) MUST be dismissable (Escape), hoverable (pointer can move into it without dismissal), and persistent (does not disappear before the user reads it). Tooltip-on-disabled is BANNED per `17-component-button.md` §8.

## 3. Operable

### 3.1 Keyboard (SC 2.1.1, 2.1.2, 2.1.4)

- Every interactive control MUST be reachable and operable by keyboard alone.
- No focus trap outside modal Dialog / Sheet. Modal Dialog focus trap is REQUIRED per `21-component-dialog.md` §4; non-modal Popover MUST NOT trap focus per `22-component-menu-popover.md`.
- Character key shortcuts (`/` focuses search, `.` opens row menu, `⌘K` opens command palette) MUST be either disabled by default, remappable, or only active when a specific control has focus. v1 satisfies "active only on non-input focus" (SC 2.1.4 exception).

### 3.2 Focus visible and focus appearance (SC 2.4.7, 2.4.11, 2.4.12, 2.4.13)

- Every focusable element MUST render a visible focus indicator with:
  - Minimum area: 2 CSS px solid ring at `--ring` (OKLCH `l 0.72 c 0.14 h 258`) with 2 px offset.
  - Contrast: ≥ 3:1 against both the focused control and the adjacent surface.
  - Not entirely obscured by author content (SC 2.4.11): sticky headers, sticky footers, and RetryAfterBanner MUST leave a 4 px focus gutter and the focus scroll padding MUST be set via `scroll-margin-block: var(--space-6)`.
- `:focus-visible` (not `:focus`) drives the ring so pointer clicks do not paint rings; keyboard, screen-reader, and switch input DO paint them.
- Removing focus rings via `outline: none` without an equivalent replacement is BANNED.

### 3.3 Target size (SC 2.5.8)

- Minimum activation target: 24 × 24 CSS px. Preferred: 40 × 40 (matches Menu item height in `22-component-menu-popover.md`).
- Exceptions permitted only for inline links in prose (SC 2.5.8 exception) and for controls positioned so the 24 px target extends into transparent whitespace around a smaller visible glyph (spacing exception).
- Table row overflow-menu Buttons (`24-component-table.md` §8) MUST be at least 32 × 32 including padding; the visible `MoreHorizontal` glyph at 20 px sits inside that target.

### 3.4 Pointer inputs and gestures (SC 2.5.1, 2.5.2, 2.5.4)

- No multi-point or path-based gesture is required by any v1 surface. Drag-reorder is out of scope (`24-component-table.md` §1).
- Down-event activation is BANNED for any destructive or navigation action; activation MUST occur on up-event (`click`, not `pointerdown`) so users can abort by moving away.
- Motion-actuated features (device tilt, shake) are BANNED.

### 3.5 Timing (SC 2.2.1, 2.2.2)

- Auto-dismiss Toast durations in `23-component-toast-banner.md` §2 (4/6/8/10 s) satisfy SC 2.2.1 because Toast content is redundant with an in-surface signal (banner or updated data). Toast MUST NOT be the sole carrier of information the user needs to act on.
- Session timeout MUST warn the user at least 60 s before expiry and offer a "Stay signed in" affordance; expired-token errors (`AuthTokenExpired`) route through the normal error surface with the copy from `27-content-voice.md` §5.
- No content moves, blinks, or auto-updates faster than every 5 s except: `RefreshCw` rotation during active fetch (per `26-iconography-and-assets.md` §8), `Loader2` spinner, and RetryAfterBanner countdown text.

### 3.6 Seizure safety (SC 2.3.1, 2.3.3)

Nothing flashes more than 3 times per second. `prefers-reduced-motion: reduce` disables all non-essential motion per `11-shape-and-motion.md` and `26-iconography-and-assets.md` §8.

## 4. Understandable

### 4.1 Language (SC 3.1.1, 3.1.2)

- Root `<html lang="en-US">` at v1 (per `27-content-voice.md` §7).
- Any embedded content in another language MUST set `lang` on its wrapper. v1 has no such content.

### 4.2 Predictability (SC 3.2.1, 3.2.2, 3.2.4)

- Focus alone MUST NOT trigger navigation or a data mutation. Switch-on-focus semantics are BANNED (`20-component-choice.md` §7 Switch mutates on change, not on focus).
- Same-purpose controls use the same label, icon, and position across surfaces (`26-iconography-and-assets.md` §5 concept map).

### 4.3 Input assistance (SC 3.3.1, 3.3.2, 3.3.3, 3.3.4, 3.3.7, 3.3.8)

- Every Field renders a persistent visible label; placeholder-as-label is BANNED (`18-component-input.md` §2).
- Validation errors are announced via `aria-describedby` on the Field and via a live region on submit-time summaries.
- Destructive and legally-significant actions (revoke, delete, expire) MUST be reversible OR confirmable per `21-component-dialog.md` §5. Revoke is confirmable and NOT reversible (spec-declared); Dialog copy states this per `27-content-voice.md` §6.
- Redundant entry (SC 3.3.7): forms MUST NOT ask for the same information twice within a flow. `AuthSaltRotationFailed` retry re-uses the previously entered credential set.
- Accessible authentication (SC 3.3.8): no cognitive-function test in the auth path (no unassisted transcription, no puzzle CAPTCHAs). Rate limiting per `../21-app/12-error-taxonomy.md` satisfies abuse prevention.

## 5. Robust

### 5.1 Parsing and name/role/value (SC 4.1.2, 4.1.3)

- Every custom component MUST expose accessible name, role, and value per WAI-ARIA 1.2 patterns cited in the component's own spec (`19` combobox, `20` checkbox/radio/switch, `21` dialog, `22` menu, `24` table).
- Status changes (mutation success, query resolution, rate-limit ready) MUST announce via `role="status"` (polite) or `role="alert"` (assertive) per `23-component-toast-banner.md` §2.
- Duplicate `id` attributes are BANNED. Every `aria-labelledby` / `aria-describedby` target MUST exist in the DOM at reference time.

## 6. Per-component contract summary

| Component | Key ACs (from its own file) | This file adds |
|-----------|-----------------------------|----------------|
| Button (`17`) | verb-first label, no tooltip-on-disabled | 24 × 24 min target, `:focus-visible` ring |
| Input (`18`) | persistent label, `aria-describedby` for helper/error | text-spacing tolerance (§2.4) |
| Select (`19`) | combobox ARIA, closed-set parity | typeahead reset, 3:1 border contrast |
| Choice (`20`) | `aria-checked`, no mutate-on-focus | Switch failure toast announced via `role="alert"` |
| Dialog (`21`) | focus trap, initial focus on Cancel or phrase Input | Escape closes non-destructive; destructive Dialog Escape rules per its §4 |
| Menu / Popover (`22`) | `role="menu"` / non-modal `role="dialog"`, no focus trap on Popover | Down-event ban for menuitem activation |
| Toast / Banner (`23`) | `role="status"` vs `role="alert"` | not sole carrier (§3.5), reduced-motion (§3.6) |
| Table (`24`) | `role="table"`, `aria-sort` on `<th>`, roving tabindex | row-action 32 × 32 target, `scroll-margin-block` on focused row |
| Badge (`25`) | `<span>` non-interactive, icon `aria-hidden` | tone token contrast pre-validated (§2.1) |
| Iconography (`26`) | three accessible-name modes | icon-only Button 24 × 24 target |
| Voice (`27`) | error triad State + Remedy + chip | validation announcement via live region (§4.3) |

## 7. Route-level checklist

Every new route MUST pass these checks before it can be marked done:

1. Only ONE `<h1>` per route; heading levels descend without skipping.
2. `<main>` landmark wraps the primary content region; every landmark has an accessible name if there are multiple of the same type.
3. Skip-link `<a href="#main">Skip to main content</a>` is the first tab stop.
4. All forms have a submit target reachable by Enter from any Field.
5. Every Field's `<label>` is programmatically associated (`for` + `id` or nested).
6. Every icon-only Button has `aria-label`.
7. Every route's terminal states (403, 404, 500) render per `16-route-shell-states.md` with focus placed on the primary recovery action.
8. Route logs `RoutePresented` at info with `Route`, `A11yViolations: 0` after future axe integration (see AC-A11Y-006).

## 8. Testing

### 8.1 Automated

- Future `tests/a11y-axe.test.ts` runs `axe-core` on every route in `src/routes/` under jsdom; MUST produce zero violations at `impact: serious` and `critical`.
- `linter-scripts/check-color-contrast.py` (future) parses `08-token-registry.md` and asserts every documented pair meets the target ratio.
- ESLint `jsx-a11y` rules MUST run in CI at `error` level: `aria-role`, `aria-props`, `aria-proptypes`, `role-has-required-aria-props`, `label-has-associated-control`, `no-static-element-interactions`, `click-events-have-key-events`.

### 8.2 Manual

- Keyboard-only pass on every new route: reach every control, activate every primary action, submit every form.
- Screen-reader pass with the §9 baseline; verify announcements for mutation success, validation errors, and rate-limit ready.
- Zoom pass at 200% text-only and 400% page zoom; verify reflow and target-size preservation.

## 9. Assistive tech baseline

v1 acceptance is measured against, at minimum, one browser + screen reader pair per OS family:

- macOS: Safari + VoiceOver.
- Windows: Chrome + NVDA.
- iOS: Safari + VoiceOver.
- Android: Chrome + TalkBack.

Bugs specific to older assistive tech (JAWS versions older than the current major, IE-era ARIA polyfills) are OUT OF SCOPE.

## 10. Anti-patterns

1. `outline: none` without a replacement focus indicator.
2. Color-only differentiation for state or sort direction.
3. Placeholder text used as the sole field label.
4. `aria-hidden="true"` on the root of a component that contains focusable descendants.
5. Positive `tabindex` values other than `0` and `-1`.
6. `role="button"` on a non-`<button>` element without matching key handlers.
7. Autofocus on a route landing page's first Field (steals focus from skip-link).
8. `title` attribute used as the only accessible name.
9. Mutation-on-focus for Switch or Select.
10. Sticky footer that obscures the focus ring of a Button at the bottom of the viewport.
11. Auto-updating content faster than every 5 s outside the §3.5 allowlist.
12. Multi-point or path-based gestures required for any action.

## 11. Acceptance criteria

- AC-A11Y-001: Every documented text/background pair in `08-token-registry.md` meets 4.5:1 (body) or 3:1 (large text / boundary); enforced by future `check-color-contrast.py`.
- AC-A11Y-002: Every route in `src/routes/` produces zero axe-core violations at `serious` or `critical` impact; enforced by future `tests/a11y-axe.test.ts`.
- AC-A11Y-003: Focus indicator on every focusable control meets 3:1 contrast against both the control and the adjacent surface; visually inspected during route review.
- AC-A11Y-004: `outline: none` appears in the codebase only in tandem with a documented replacement (`:focus-visible` ring); enforced by grep in future `check-focus-ring.py`.
- AC-A11Y-005: All motion respects `prefers-reduced-motion: reduce` per `11-shape-and-motion.md` and `26-iconography-and-assets.md` §8; verified by media-query snapshot tests.
- AC-A11Y-006: Every route emits `RoutePresented` at info with `A11yViolations` count (0 after axe integration lands).
- AC-A11Y-007: `jsx-a11y` ESLint rules listed in §8.1 run at `error` and pass in CI.
- AC-A11Y-008: No positive `tabindex` other than `0` and `-1` anywhere in shipped code; enforced by grep in future `check-tabindex.py`.

## 12. Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- Manual: every AC in §11 traces to at least one component file's AC block or to a future linter file registered in `.lovable/pending-tasks/`.
