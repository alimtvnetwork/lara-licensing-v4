# Search and Command Palette

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** Single normative source for the two global keyboard-first discovery surfaces: the scoped route SearchInput (`/` shortcut) and the global Command Palette (`⌘K` / `Ctrl K`). Every future per-surface blueprint that exposes a search field or contributes a command MUST cite this file.
**Related:** [`13-navigation-ia.md`](./13-navigation-ia.md), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`24-component-table.md`](./24-component-table.md), [`26-iconography-and-assets.md`](./26-iconography-and-assets.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Purpose and non-purpose

| Surface | Purpose | NOT for |
|---------|---------|---------|
| SearchInput | Filter the current route's Table `q` parameter (`24-component-table.md` §3). Scoped to one resource type. | Cross-resource navigation. Executing commands. |
| Command Palette | Global entry point to (a) navigation, (b) recent items, (c) direct-lookup by identifier, (d) permitted commands. | Free-text server search. Replacing the primary nav. Executing destructive mutations directly (destructive commands route through the confirmation Dialog per `21-component-dialog.md` §5). |

Global full-text search across resources is OUT OF SCOPE for v1. The Palette's "direct-lookup" mode resolves exact identifiers only (license id, serial value, user id).

## 2. Keyboard model

- `/` focuses the current route's SearchInput when no input element already has focus. Overridden inside a Field (typing `/` inserts the character).
- `⌘K` (macOS) / `Ctrl K` (Windows/Linux) opens the Command Palette from any focus context EXCEPT when a Dialog with a text Field is open (the Dialog wins).
- Escape closes the Palette without executing anything.
- The Palette is the ONLY global keyboard shortcut in v1. Additional shortcuts (`g h` grid navigation, `?` help) are OUT OF SCOPE for v1.

Per `28-a11y-conformance.md` §3.1, single-character shortcuts are permitted because they are active only when no input has focus (SC 2.1.4 exception).

## 3. SearchInput (route-scoped)

### 3.1 Anatomy

Renders as an Input variant per `18-component-input.md` §5 (search variant): leading `Search` icon at `--icon-sm`, clear-`X` trailing Button when the field has value, `role="searchbox"`, `aria-controls` referencing the route's Table `id`.

### 3.2 Behavior

- Debounce 300 ms per `24-component-table.md` §7 (SearchInput is the ONE filter that debounces).
- Value MUST persist in the URL as `q` per `24-component-table.md` §3.
- Field-clear Button removes `q` from the URL entirely (does not send `q=`).
- Enter submits immediately (bypasses debounce). Escape clears when the field has value; Escape on empty field blurs.
- Placeholder MUST name the scope, sentence case, no wildcard hint ("Search licenses" not "Search licenses (\*)").

### 3.3 Announcement

Result count MUST be announced via a polite live region on debounce settle: `"42 results"` / `"1 result"` / `"No results"`. Per-keystroke announcement is BANNED.

## 4. Command Palette

### 4.1 Anatomy

Rendered as a modal Dialog per `21-component-dialog.md` §4 with content specialization:

```
+---------------- Palette Dialog ----------------+
| [Search] Type a command or search...    ⌘K    |
+------------------------------------------------+
| GROUP: Navigation                              |
|   > Licenses            Go              ⏎     |
|   > Users               Go              ⏎     |
|                                                |
| GROUP: Recent                                  |
|   > License L-3210      Open            ⏎     |
|                                                |
| GROUP: Commands                                |
|   > Issue license…      Open dialog     ⏎     |
|   > Revoke license…     Destructive     ⏎     |
|                                                |
| GROUP: Direct lookup                           |
|   > License id: L-3210  Open            ⏎     |
+------------------------------------------------+
| Escape to close   ↑↓ to move   ⏎ to select    |
+------------------------------------------------+
```

Geometry: bottom Sheet at XS/SM per `29-responsive-matrix.md` §4; centered modal min-inline 560 px max-inline 720 px at ≥ MD; height clamps to `min(560px, 80vh)`; result list scrolls, header (input) and footer (hints) do not.

Focus enters the Palette Input on open. The Dialog `role="dialog"` and `aria-modal="true"` per `21-component-dialog.md` §4; the result list is a `role="listbox"` with `role="option"` items; the Input is `role="combobox"` with `aria-controls` referencing the listbox id.

### 4.2 Result groups (fixed order)

1. **Navigation** — permitted routes from `13-navigation-ia.md`.
2. **Recent** — the last five items the user opened this session (client-only, stored in `sessionStorage.lara.palette.recent`).
3. **Commands** — invocable actions the user has permission for (see §5).
4. **Direct lookup** — resolves the raw query as an exact identifier when it matches a known identifier pattern (see §7).

Empty groups MUST be hidden entirely. Group headers are `role="presentation"` with visually separated Label; they are NOT selectable.

### 4.3 Ranking

- Prefix match on the item's Label ranks above substring match.
- Exact identifier match promotes Direct lookup above all other groups.
- Within a group, order is: prefix hits alphabetized, then substring hits alphabetized. Fuzzy matching (Levenshtein, character skip) is OUT OF SCOPE for v1.
- Ranking is deterministic; there is no learned or user-history weighting in v1.

### 4.4 Keyboard within the Palette

| Key | Action |
|-----|--------|
| ArrowDown / ArrowUp | Move highlighted item; wrap at ends of the list, NOT across groups without wrap. |
| Home / End | Jump to first / last visible item. |
| Enter | Execute highlighted item. |
| Escape | Close Palette; if input has value, first press clears the query, second press closes. |
| Tab | BANNED as a selection key (moves shell focus outside the modal is prevented; inside the modal Tab moves through Input then footer hints). |

Typeahead beyond arrow navigation is BANNED (avoid ambiguity with the search input).

### 4.5 States

| State | Trigger | Render |
|-------|---------|--------|
| Idle | No query text | Recent + Navigation + top three Commands the user has permission for. |
| Query in flight | Direct-lookup identifier detected (§7) | Skeleton row in Direct-lookup group; other groups render normally. Query resolves within 500 ms or renders a "Still searching..." row. |
| Empty | Query has no matches in any group | Single row "No results for `<query>`" (no styling as an option, `aria-live="polite"`). |
| Forbidden | Direct-lookup returned 403 | Direct-lookup group renders "You do not have permission to open this resource." per `27-content-voice.md` §5; other groups unaffected. |
| Error | Direct-lookup returned 5xx | Direct-lookup group renders inline error with `ErrorCode` + `RequestId`; other groups unaffected. |
| Rate-limited | Direct-lookup returned 429 | Direct-lookup group renders `RetryAfterBanner` inline. |

The Palette itself MUST NOT be blocked by a Direct-lookup error; navigation and commands remain usable.

## 5. Commands registry

Every command MUST live in a `spec/24-app-ui-design-system/30-command-registry.md` (deferred; §5.3 is the placeholder registry until then). Ad hoc commands wired directly into feature code are BANNED.

### 5.1 Command shape

- `CommandId`: `Domain.Action` in PascalCase, matches an operationId when the command executes an API call (`../21-app/26-route-dto-index.md`).
- `Label`: verb-first per `27-content-voice.md` §3; ellipsis `…` suffix when the command opens a modal.
- `Permission`: exact permission key from `../21-app/40-permissions.md`. Commands the caller lacks permission for MUST be filtered OUT of the result list (HIDDEN, not disabled, per `28-a11y-conformance.md` §4.3 and `13-navigation-ia.md` §3).
- `Kind`: one of `Navigate` (routes to a URL), `Dialog` (opens a Dialog per §5.2), `Sheet` (opens a Sheet), `External` (opens `ArrowUpRight` link).
- `Destructive`: boolean; if true, MUST route through the confirmation Dialog per `21-component-dialog.md` §5 with `Idempotency-Key` generated on Dialog open (never on Palette selection).

### 5.2 Destructive commands

- Selecting a destructive command from the Palette closes the Palette AND opens the confirmation Dialog; the Dialog's Cancel Button returns focus to the Palette trigger (route element), NOT to the Palette itself. Reopening the Palette after cancel is a fresh open.
- The Palette MUST NOT fire the mutation directly. There is no "hold Enter to confirm" or "type YES here" pattern in the Palette. All destructive confirmation lives in the Dialog per `21-component-dialog.md` §5.

### 5.3 v1 command placeholder registry

Full registry is deferred to a dedicated file. v1 commit ships these commands (subject to per-surface-blueprint refinement):

| CommandId | Label | Permission | Kind | Destructive |
|-----------|-------|-----------|------|-------------|
| `Admin.Licenses.Issue` | Issue license… | `Admin.Licenses.Write` | Dialog | false |
| `Admin.Licenses.Revoke` | Revoke license… | `Admin.Licenses.Revoke` | Dialog | true |
| `Admin.Serials.Rebind` | Rebind serial… | `Admin.Serials.Write` | Dialog | false |
| `Admin.Users.Invite` | Invite user… | `Admin.Users.Write` | Dialog | false |
| `Reseller.Quota.Request` | Request more quota… | `Reseller.Quota.Request` | Dialog | false |
| `Builder.Keys.Rotate` | Rotate builder key… | `Builder.Keys.Write` | Dialog | true |
| `Nav.Admin.Licenses` | Licenses | `Admin.Licenses.Read` | Navigate | false |
| `Nav.Admin.Users` | Users | `Admin.Users.Read` | Navigate | false |
| `Nav.Admin.Audit` | Audit | `Admin.Audit.Read` | Navigate | false |
| `Nav.Reseller.Portal` | Reseller portal | `Reseller.Portal.Read` | Navigate | false |
| `Nav.Builder.Portal` | Builder portal | `Builder.Portal.Read` | Navigate | false |
| `Nav.Me.Portal` | My account | `EndUser.Portal.Read` | Navigate | false |
| `Nav.ApiDocs` | API documentation | `Api.Swagger.Read` | Navigate | false |

Permission strings above are provisional and MUST match `../21-app/40-permissions.md`; drift is caught by a future `check-command-permission-parity.py`.

## 6. Recent items

- Stored client-side in `sessionStorage` under key `lara.palette.recent`.
- Schema: `{ items: Array<{ Kind: 'License' | 'Serial' | 'User' | 'Client', Id: string, Label: string, VisitedAt: string }> }`; capped at 5 items; LRU eviction; JSON-serialized.
- Items are added when a route resolves for a specific identifier (loader emits `RecentItemVisited` event).
- Field VALUES are NOT stored (serial value strings, email addresses); only identifiers and Kind + Label per `27-content-voice.md` §8.
- Recent is cleared on sign-out.

## 7. Direct lookup

- Trigger patterns matched against the raw query (case-insensitive):
  - License id: `L-` followed by 4..12 alphanumeric characters.
  - Serial value: 16 alphanumeric characters, no dashes.
  - User id: `U-` followed by 4..12 alphanumeric characters.
- Non-matching queries do NOT hit the API; the Direct-lookup group is hidden.
- A matching query fires a single `useQuery` against the domain-specific lookup endpoint (`Admin.Licenses.Get`, `Admin.Serials.LookupByValue`, `Admin.Users.Get`). Debounce 200 ms.
- On success, the group renders one Result row `Open <Kind> <Identifier>` invoking a `Navigate` action.
- Serial-value lookup is subject to the abuse-prevention rate limit in `../21-app/12-error-taxonomy.md`; a `429` renders §4.5 Rate-limited.

## 8. Telemetry

Emit via `logger.info`:
- `PaletteOpened`: `Route`, `TriggerKind` (`Shortcut`, `NavButton`).
- `PaletteClosed`: `Route`, `DurationMs`, `SelectedCommandId` (nullable), `Reason` (`Escape`, `Backdrop`, `Selection`).
- `PaletteQueryChanged` throttled at 500 ms with `QueryLength` ONLY (NOT the query string, which may contain identifiers users typed to look up).
- `PaletteResultSelected`: `CommandId`, `Group`, `Rank`.
- `PaletteEmptyResult`: `QueryLength`.
- `SearchInputSubmitted`: `Route`, `QueryLength`, `ResultCount`.

Query strings themselves are NEVER logged (privacy per `27-content-voice.md` §8).

## 9. Anti-patterns

1. Executing a destructive mutation directly on Palette Enter.
2. Fuzzy matching in v1 ranking.
3. Learning / weighting Recent by frequency in v1.
4. Announcing search results per keystroke.
5. Cross-resource full-text search in v1.
6. Palette items that are disabled with a tooltip (permission-lacking items MUST be hidden).
7. Storing field values (serial strings, emails) in Recent.
8. Logging the raw query string.
9. Native `title` tooltip on Palette items.
10. Palette overriding an open Dialog's text Field focus with `⌘K`.
11. Additional single-character shortcuts beyond `/` in v1.
12. Group headers that are focusable or selectable.

## 10. Acceptance criteria

- AC-PAL-001: `⌘K` / `Ctrl K` opens the Palette from any non-Dialog-text-input focus context; opening while a Dialog text Field has focus is BANNED.
- AC-PAL-002: `/` focuses the current route's SearchInput only when no input already has focus.
- AC-PAL-003: Commands the caller lacks permission for are absent from Palette results (verified by future `tests/palette-permission-filter.test.tsx`).
- AC-PAL-004: Selecting a `Destructive: true` command closes the Palette and opens the confirmation Dialog; the mutation does NOT fire from the Palette.
- AC-PAL-005: Recent stores at most 5 items and holds no field VALUES; verified by future `tests/palette-recent.test.ts`.
- AC-PAL-006: Direct lookup regexes fire the API ONLY when the query matches an identifier pattern; verified by future test.
- AC-PAL-007: `PaletteQueryChanged` logs `QueryLength` only; the raw query is NEVER logged.
- AC-PAL-008: SearchInput announces the debounced result count via a polite live region exactly once per settle.
- AC-PAL-009: `check-command-permission-parity.py` (future) asserts every §5.3 permission is present in `../21-app/40-permissions.md`.

## 11. Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- Manual: §5.3 permission strings are a strict subset of the permissions declared in `../21-app/40-permissions.md`.
