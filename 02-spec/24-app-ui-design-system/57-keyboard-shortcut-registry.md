# Keyboard-Shortcut Registry

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Single normative source for global shortcuts, per-surface shortcuts, Command Palette parity, and conflict resolution across platforms (mac, win, lin, ios, android).
**Owner:** Keyboard governance. Every runtime shortcut MUST cite one row here.
**Related:** [`14-navigation-ia.md`](./14-navigation-ia.md), [`17-component-button.md`](./17-component-button.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`24-component-table.md`](./24-component-table.md), [`26-component-form-field.md`](./26-component-form-field.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`32-command-registry.md`](./32-command-registry.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`52-icon-illustration-registry.md`](./52-icon-illustration-registry.md), [`53-empty-state-catalog.md`](./53-empty-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

Pins the closed set of keyboard shortcuts, the Mod-key mapping (`Mod` renders `⌘` on mac and `Ctrl` on win / lin), the scope hierarchy (global > surface > component), and the conflict-detection linter. Every runtime `key.bind(...)` MUST cite a row in §5 or §6.

Out of scope: browser and OS shortcuts (`⌘L`, `⌘W`, `⌘R` etc.); the app MUST NOT rebind or capture any browser default per §11 rule 1. Screen-reader shortcuts (managed by the assistive tech).

## 2. Platforms

- macOS: `Mod` = `⌘` (Command). `Alt` renders `⌥`. `Shift` renders `⇧`.
- Windows and Linux: `Mod` = `Ctrl`. `Alt` renders `Alt`. `Shift` renders `Shift`.
- iOS and Android: physical keyboards may be attached. Shortcuts follow the platform Mod convention (`⌘` on iPadOS with Magic Keyboard, `Ctrl` on Android). Touch-only devices do not surface shortcut hints per §9.
- Detection: `navigator.userAgentData?.platform` OR `navigator.platform` regex against `Mac`. The result is cached at app boot; toggling platform mid-session is not supported.

## 3. Scope hierarchy

Three scopes, evaluated in this order on each `keydown`:

- **Component:** an active focused control captures the key (input Field consumes typing keys, Menu consumes arrow keys, Dialog consumes Escape). Component scope always wins; unbinding native input behaviour BANNED.
- **Surface:** a route or panel-level shortcut (e.g. `Mod+N` on `/admin/licenses` opens new-license). Registered per-route via a `useSurfaceHotkey` hook; unbinds on route unmount.
- **Global:** shortcuts active across every authenticated route (e.g. `Mod+K` opens Command Palette). Registered once in `__root.tsx`.

- A component-scope binding overriding a global binding is BANNED unless explicitly listed in §7.
- Two bindings for the same key at the same scope is a lint failure per §12.
- Bindings that fire when a modal Dialog is open MUST be scoped to that Dialog; global and surface bindings pause while a modal Dialog is open.

## 4. Modifier grammar

- Written form: `Mod+K`, `Mod+Shift+K`, `Alt+Enter`, `Escape`, `?`. Rendered form uses the platform mapping in §2.
- Single-key shortcuts (no modifier) permitted ONLY for `?`, `Escape`, `Enter`, arrow keys, `Tab`, `/` (focus search), and digits `1..9` within specific surfaces per §6. All other single-key shortcuts BANNED because they conflict with typing.
- Chord sequences (`g` then `l`) BANNED in v1 (they are learnable but non-discoverable and add a state machine per surface).
- `Shift` modifies direction (Shift+Enter = new line, Shift+Tab = focus previous). `Shift` MUST NOT be combined with `Mod` on an action that also exists without `Shift` unless the two variants are semantically related (Save vs Save as, Sign out vs Sign out everywhere).

## 5. Global shortcuts (closed set)

| Shortcut | Action | Copy |
|---|---|---|
| `Mod+K` | Open Command Palette | `Search commands and pages` |
| `?` (when no input focused) | Open Keyboard Shortcuts Dialog | `Show keyboard shortcuts` |
| `Escape` | Close Dialog / Menu / Popover / Palette; when nothing is open, blur the focused input | Context-dependent |
| `g` + click nav item (mouse) | -- | reserved; see chord discussion in §4 |
| `Mod+/` | Focus global search Field in App bar (if present) | `Focus search` |
| `Alt+Shift+D` | Toggle theme (light / dark / system) | `Toggle theme` |
| `Alt+Shift+S` | Sign out (opens confirmation Dialog per `56-` §7) | `Sign out` |

- 6 global shortcuts. Global scope MUST stay under 10 entries so the Keyboard Shortcuts Dialog fits in one A4 print.
- `Mod+K` MUST match the Command Palette hint rendered in the App bar Button per `32-` §3.

## 6. Surface shortcuts

Each surface registers its own bindings via `useSurfaceHotkey`. Unbinds on unmount. Bindings paused while a Dialog is open.

### 6.1 Any list surface (Tables per `24-`)

| Shortcut | Action |
|---|---|
| `/` | Focus the filter-bar Search Field |
| `Mod+N` | Trigger the primary create action (Add / Issue / Invite / Register / Publish) |
| `Mod+A` | Select all rows on the current page (multi-select tables only) |
| `Escape` | Clear selection if any, else clear filters if any, else no-op |
| `Enter` on a focused row | Navigate to that row's detail page |
| `Space` on a focused row | Toggle multi-select checkbox (multi-select tables only) |
| Arrow keys | Move focus between rows / cells per `24-` §5 |
| `1..9` | Jump to page N of pagination (single-digit only) |
| `PageUp` / `PageDown` | Prev / next page |
| `Mod+E` | Trigger CSV export (see `55-` §6) |
| `Mod+P` | Trigger PDF certificate download (detail pages only, per `55-` §5) |

### 6.2 Detail routes

| Shortcut | Action |
|---|---|
| `Mod+Enter` | Save (when a form is dirty) |
| `Mod+Shift+Enter` | Save and continue editing |
| `Escape` | Discard changes prompt (only if form is dirty; else navigate up) |
| `Mod+Backspace` | Open destructive-action Dialog (Revoke / Delete / Deprecate) |
| `Mod+D` | Duplicate (where duplicate is offered; NOT on License / Serial per `34-` §7 and `35-` §7) |

### 6.3 Form Dialogs

| Shortcut | Action |
|---|---|
| `Enter` in a single-line Field | Submit the form (only if primary Button is enabled) |
| `Mod+Enter` in a multi-line textarea | Submit |
| `Escape` | Cancel and close (blocked mid-mutation per `54-` §8) |

### 6.4 Command Palette (per `32-`)

| Shortcut | Action |
|---|---|
| Arrow up / down | Move highlight |
| `Enter` | Execute highlighted item |
| `Mod+Enter` | Execute in new tab (for routes only; not for actions) |
| `Tab` | Cycle result categories |
| `Escape` | Close |

## 7. Component overrides

The only bindings that override a global scope are:

- Escape inside a Dialog closes the Dialog before falling through to global Escape. Correct behaviour; not an override, just standard modal stack.
- `Mod+K` inside a search Field of the Command Palette itself is a no-op (the palette is already open); this is not a rebinding, just a swallow.
- `?` inside a text input is passed through as literal character; the Keyboard Shortcuts Dialog opens ONLY when no input is focused.

Any other component-level override BANNED.

## 8. Rendering shortcut hints

- App bar Command Palette Button renders `Mod+K` hint on the right-hand side (small pill with the rendered form per §2).
- Menu items with a shortcut render the shortcut right-aligned per `22-` §5 using the small pill.
- Buttons with a shortcut render the shortcut ONLY inside their tooltip (never inline on the Button face; the label is the action, the shortcut is the accelerator).
- Rendering the raw `Mod` string in the UI BANNED; the runtime maps to `⌘` or `Ctrl` per §2.
- Rendering the shortcut on a touch-only device BANNED (no physical keyboard). Detection: `matchMedia('(hover: none) and (pointer: coarse)').matches` at App bar mount; re-detects on `resize` per `29-` §3.

## 9. Keyboard Shortcuts Dialog

- Opened by `?` global shortcut per §5.
- Full list of §5 + the currently-active surface's §6 bindings, grouped by scope with headings.
- Search Field filters the list live; matching is by shortcut key AND by action copy.
- Print-friendly (fits on 1 A4 page per `55-` §3); the Dialog uses the certificate print stylesheet fragment.
- Closed by `Escape` or the `Close` Button.
- Focus trap per `21-` §6.

## 10. Accessibility

- Every shortcut MUST have an alternative activation path (Button, Menu item, or Command Palette entry). Shortcut-only actions BANNED because they exclude users who cannot use two-modifier combos.
- Focus management follows `28-` §6: after any shortcut-triggered navigation, focus lands on the destination's `h1` per `28-` §6 rule 3.
- Sticky-keys and switch-access users benefit from single-modifier bindings; the app avoids three-modifier combos (`Mod+Alt+Shift+X`) entirely.
- Screen readers: shortcuts are announced via `aria-keyshortcuts` on the associated Button / Menu item / Link. Example: `<button aria-keyshortcuts="Meta+N">Issue license</button>` on mac.
- `aria-keyshortcuts` uses the W3C WAI-ARIA syntax with `Meta` on mac and `Control` on win / lin (NOT the rendered form).

## 11. Conflict + platform hazards

Bindings that would conflict with browser defaults are BANNED. The current list:

- `Mod+T` (new tab), `Mod+W` (close tab), `Mod+N` (new window on mac; the app uses `Mod+N` for "new resource" scoped to a LIST surface only and pauses the binding when the focus is outside the list body per §6.1),
- `Mod+L` (focus URL bar), `Mod+R` (reload), `Mod+F` (find in page),
- `Mod+P` (print) is CLAIMED by the app for certificate PDF download on detail pages per §6.1; the browser's print dialog is still reachable via the browser menu and via `Mod+Shift+P` if the browser supports it. This is the ONE exception; documented explicitly so runtime does not accidentally bind another action to `Mod+P`.
- `Mod+E` is CLAIMED by the app for CSV export on list surfaces per §6.1 (browsers do not use this globally).

- The `Mod+N` and `Mod+P` claims MUST be documented in the Keyboard Shortcuts Dialog with a warning line so users know these override some browser or OS defaults on their platform.

## 12. Linter (`check-hotkey-registry.py`)

New linter under `linter-scripts/check-hotkey-registry.py`:

- Scans `src/**/*.tsx` for `useHotkey(...)`, `useSurfaceHotkey(...)`, and any raw `keydown` `Mod+X` handlers.
- Cross-references every binding with §5 / §6 registries.
- Fails on: binding not in registry, two bindings for the same key at the same scope, single-key shortcut outside the whitelist in §4, three-modifier combo, rebinding of a browser default outside the §11 claim list, `aria-keyshortcuts` missing on any button / menu-item associated with a bound action, shortcut hint rendered on a touch-only device.
- Runs in CI and via `./linter-scripts/run.sh check-hotkey-registry`.

## 13. Anti-patterns (BANNED)

1. Rebinding browser defaults outside §11 claims.
2. Single-key shortcuts outside the §4 whitelist.
3. Chord sequences (`g l`, `g s`).
4. Three-modifier combos.
5. Shortcut-only actions with no Button / Menu / Palette alternative.
6. Rendering `Mod` literal string in the UI.
7. Rendering shortcut hints on touch-only devices.
8. Component override of a global shortcut outside §7.
9. Missing `aria-keyshortcuts` on the associated interactive element.
10. Global shortcut list exceeding 10 entries.
11. Two bindings for the same key at the same scope.
12. Escape closing a Dialog mid-mutation (banned per `54-` §8).
13. Shortcut that mutates data WITHOUT a confirmation Dialog for destructive actions.
14. Different shortcut for the same action across two surfaces.
15. Shortcut hint rendered on the Button face (must live in the tooltip).

## 14. Acceptance criteria

- AC-HOTKEY-001: Every shortcut in the built app is registered via `useHotkey` / `useSurfaceHotkey` citing a §5 or §6 row.
- AC-HOTKEY-002: Platform mapping (`Mod` -> `⌘` / `Ctrl`) is applied at render time.
- AC-HOTKEY-003: Every shortcut has an alternative activation path (Button, Menu item, or Command Palette).
- AC-HOTKEY-004: `aria-keyshortcuts` is present on every interactive element that binds a shortcut.
- AC-HOTKEY-005: `?` opens the Keyboard Shortcuts Dialog which lists global + current-surface bindings and is print-friendly.
- AC-HOTKEY-006: Touch-only devices do not render shortcut hints.
- AC-HOTKEY-007: `check-hotkey-registry.py` passes.

## 15. Open items

- Chord sequences (`g l`, `g s`, `g u`) deferred; v1 keeps discovery via Command Palette.
- Per-user rebinding UI deferred.
- Shortcut telemetry (which shortcuts fire in the wild) deferred; tracked under the coming analytics event catalog.
- iPadOS-specific bindings (`⌘Space` for Command Palette) considered but not adopted in v1; `Mod+K` covers both platforms consistently.
