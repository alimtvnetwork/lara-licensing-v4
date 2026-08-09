# Cross-Blueprint Audit

**Version:** 1.0.0
**Status:** Normative audit report. Verifies every route blueprint (`33-` through `42-`) and every cross-cutting catalog (`50-` through `58-`) cite their siblings correctly and share consistent copy, tokens, timings, shortcuts, events, and error codes.
**Owner:** Cross-blueprint governance. This document is the drift detector.
**Related:** every `24-app-ui-design-system/*.md` file.

---

## 1. Purpose and scope

Plan 06 added 29 new documents (`06-` through `16-` earlier, `30-` through `32-`, `33-` through `42-` route blueprints, `50-` through `58-` cross-cutting catalogs). Each was authored in a single pass. Cross-references between them accumulated implicit assumptions. This audit walks every document and:

- Verifies every `Related:` frontmatter link resolves.
- Verifies every catalog-cited rule (motion durations, empty-state variants, loading modes, shortcut bindings, copy strings, analytics events, error codes) is actually pinned in the referenced catalog.
- Flags rules that were stated in prose but not pinned normatively.
- Flags copy / verb / label drift across blueprints.
- Produces a §7 change-request list for the next patch pass.

Out of scope: adding NEW rules. This is an audit, not a rewrite; discoveries are logged in §7 and executed in a later step.

## 2. Method

- **Static link check:** every `[...](./NN-...)` link resolved by filename against the `spec/24-app-ui-design-system/` directory.
- **Rule cross-reference:** every occurrence of the phrase `per \`NN-` was extracted and the target section number checked to exist in the referenced file.
- **Term drift:** the term dictionary from `56-copy-dictionary.md` §10 (reserved words) was greped across all blueprints; misuses flagged.
- **Token drift:** motion durations (`80` / `120` / `240` / `320` / `480` ms), skeleton delay (`100` ms), min-hold (`400` ms), max-wait `md` (`2000` ms), max-wait `lg` (`8000` ms), CSV row cap (`100000`), Mod key mapping were greped for raw numbers appearing outside their owning catalog without a citation.
- **Shortcut drift:** every `Mod+`, `Alt+`, `Shift+` occurrence checked against `57-` §5 / §6.
- **Event drift:** every `analytics.track` phrase and every prose reference to an event name checked against `58-` §4.
- **Error code drift:** every `ErrorCode` mentioned in a blueprint checked against `../21-app/12-error-taxonomy.md`.

## 3. Documents in scope (32 total)

Foundations: `00`, `01`, `02`, `03`, `04`, `05`, `06`, `07`, `08`, `09`, `10`, `11`, `12`, `13`, `14`, `15`, `16`.
Components: `17` Button, `18` Input, `19` Select, `20` Choice, `21` Dialog, `22` Menu/Popover, `23` Toast/Banner, `24` Table, `25` Badge/Status, `26` FormField (was Iconography; renamed cross-ref in §7), `27` Voice, `28` A11y, `29` Responsive, `30` KPI/Chart, `31` Search, `32` Command Registry.
Route blueprints: `33` Admin Overview, `34` Admin Licenses, `35` Admin Serials, `36` Admin Users, `37` Admin Quotas, `38` Admin Features, `39` Reseller Portal, `40` Builder Console, `41` End-user Me, `42` Auth + 403/404/500.
Cross-cutting catalogs: `50` Swagger, `51` Motion, `52` Icons/Illustrations, `53` Empty-states, `54` Loading, `55` Print/Export, `56` Copy, `57` Keyboard, `58` Analytics.
Acceptance: `97-acceptance-criteria.md`, `99-consistency-report.md`.

## 4. Cross-reference matrix (normative expectations)

Every route blueprint (`33-42`) MUST cite the following catalogs in `Related:`:

- `28-a11y-conformance.md` (focus, aria-live, contrast).
- `51-motion-and-reduced-motion.md` (any animation).
- `54-loading-state-catalog.md` (skeletons / spinners).
- `56-copy-dictionary.md` (any user-visible string).
- `12-error-taxonomy.md` and `56-` §5 (any error UI).

Every route blueprint that ships a mutation MUST cite:

- `58-analytics-event-catalog.md` §4 for the events fired.
- `../21-app/28-audit-action-enum.md` for the audit row written.
- `57-keyboard-shortcut-registry.md` if it binds a shortcut.

Every route blueprint that renders an empty state MUST cite `53-empty-state-catalog.md` and specify the variant (First-run / Filter-reset / Permission-scope).

Every route blueprint that offers export or print MUST cite `55-print-export-stylesheet.md`.

Every route blueprint that renders an illustration MUST cite `52-icon-illustration-registry.md`.

## 5. Findings (as of v0.166.0 authoring pass)

### 5.1 Missing `Related:` back-links

- F1 (severity: low) `33-route-blueprint-admin-overview.md`: does not list `58-analytics-event-catalog.md`, though the KPI tiles emit `List.Loaded`.
- F2 (low) `34-route-blueprint-admin-licenses.md`: does not list `55-print-export-stylesheet.md`, though the row menu offers `Download PDF`.
- F3 (low) `35-route-blueprint-admin-serials.md`: does not list `52-icon-illustration-registry.md`, though the empty state renders an illustration.
- F4 (low) `36-route-blueprint-admin-users.md`: does not list `57-keyboard-shortcut-registry.md`, though `Mod+N` (invite) is bound.
- F5 (low) `37-route-blueprint-admin-quota-approvals.md`: does not list `56-copy-dictionary.md`, though `Approve` / `Adjust` / `Deny` are governed there.
- F6 (low) `38-route-blueprint-admin-features.md`: does not list `54-loading-state-catalog.md` explicitly (uses skeletons per §3 List mode).
- F7 (low) `39-route-blueprint-reseller-portal.md`: does not list `58-analytics-event-catalog.md`.
- F8 (low) `40-route-blueprint-builder-console.md`: does not list `52-` or `58-`.
- F9 (low) `41-route-blueprint-enduser-me.md`: does not list `55-` or `57-`.
- F10 (low) `42-route-blueprint-auth-and-403-404-500.md`: does not list `58-` (event `Route.Errored` / `Route.NotFound`) or `52-` (illustration on 404 / 500 / permission-scope 403).

### 5.2 Term drift (reserved word misuse per `56-` §10)

- F11 (medium) Prose in `40-` Builder Console uses `Retire` for update state; canonical verb is `Retract` per `56-` §10. Occurrences: 3.
- F12 (medium) Prose in `36-` Admin Users uses `Deactivate` for user state; canonical verb pair is `Disable` (Button) / `Disabled` (state) per `56-` §4 / §7. Occurrences: 2.
- F13 (medium) Prose in `39-` Reseller Portal uses `Plan` in one sentence about tier UI; canonical term is `Tier` per `56-` §10. Occurrences: 1.
- F14 (medium) Prose in `33-` Admin Overview uses `Kill switch` for license revoke in a KPI label; canonical verb is `Revoke` per §10. Occurrences: 1.
- F15 (low) `27-content-voice.md` predates `56-` and duplicates the voice rules; §2 of `56-` is now authoritative. Add a `Superseded by 56-` banner to `27-`.

### 5.3 Token drift

- F16 (medium) `24-component-table.md` §7 states pagination sizes `25 / 50 / 100`; `54-` §3 assumes `List.PageSize` values match `24-`. `58-` §4.3 `PageSize` enum needs the same three values. Verify no blueprint hardcodes a fourth size.
- F17 (medium) `54-` §3 pins skeleton delay `100 ms` and min-hold `400 ms`; `51-` motion §5 pins `80 / 120 / 240 / 320 / 480` durations. `54-`'s `100 ms` is NOT in `51-`'s closed set. Resolution: `54-` §3 documents these thresholds as loading-specific timings (not motion durations) and adds a §5 cross-reference to `51-`; no conflict but the exception must be spelled out.
- F18 (low) `55-` §6 pins CSV row cap `100000`; `58-` §6 `RowCountBucket` tops at `10001+`. Since 100000 falls in `10001+`, no conflict, but a comment in `58-` §6 would clarify.

### 5.4 Shortcut drift

- F19 (medium) `24-component-table.md` §5 mentions `PageUp` / `PageDown` for pagination BEFORE `57-` §6.1 pinned them; verify §5 wording matches `57-` §6.1 exactly.
- F20 (medium) `32-command-registry.md` §3 mentions `Mod+K` as the palette shortcut; `57-` §5 pins this globally. Add explicit `per 57- §5` citation in `32-` §3.
- F21 (low) `41-` End-user Me §5 uses phrase `SIGN OUT` for Sign out everywhere; matches `56-` §7. Aligned.

### 5.5 Analytics drift

- F22 (medium) Blueprints `34-` through `42-` predate `58-` and reference generic events like `license.issued`. Canonical names are `License.Issue` (`Started` + `Resolved`) per `58-` §4.4. Every blueprint needs a search-replace to canonical names in a coming patch step.
- F23 (medium) `40-` Builder Console describes a `client.secret.rotated` event; canonical is `Client.RotateSecret` per `58-` §4.4.
- F24 (low) `38-` Features TierMatrix toggle emits an event; canonical is `Feature.TierMatrixToggled` per `58-` §4.4. Confirmed.

### 5.6 Error code drift

- F25 (low) `41-` End-user Me §5 references `NotFound`; canonical per `../21-app/12-error-taxonomy.md` is `LicenseNotFound` / `SerialNotFound`. Fix the citation.
- F26 (low) `42-` Auth §3 references `AuthFailed` correctly. Aligned.

### 5.7 Empty-state variant

- F27 (low) `35-` Admin Serials cites "empty state" without naming the variant. Per `53-` §3 every empty state MUST be one of three variants. Add explicit variant tag (First-run vs Filter-reset vs Permission-scope) in a coming patch.
- F28 (low) `39-` Reseller Portal empty state for zero licenses is a First-run variant per `53-` §4; add tag.

### 5.8 Loading mode

- F29 (low) `33-` Admin Overview KPI tiles use Mode B (List) skeletons per `54-` §3; add explicit tag.
- F30 (low) `41-` End-user Me detail page uses Mode A (Route-shell) skeleton per `54-` §3; add explicit tag.

### 5.9 Illustration usage

- F31 (medium) `52-` §7 bans illustrations in dashboards; verify `33-` and `39-` do not render illustrations in Overview surfaces. Both pass on inspection.

### 5.10 Print / export

- F32 (low) `35-` Admin Serials mentions "generate serial certificate" in prose; the printable route list in `55-` §3 pins the 5 canonical routes; add explicit route path `/serials/{Id}/certificate.pdf` to `35-` and to `55-` §3 if missing.

### 5.11 Copy source

- F33 (medium) Blueprints authored before `56-` include inline literal `<Button>Save</Button>` example snippets. Per `56-` §12 inline literals BANNED. Replace with `copy.buttons.save` in every example snippet on the next patch.

## 6. Aligned rules (spot-checked, no drift)

- Motion durations across `51-` / `54-` / `58-`: aligned.
- Reserved words `Revoke` / `Retract` / `Rotate` / `Adjust` / `Deny` / `Deprecate` correctly used in `34-` / `40-` / `37-` / `38-`.
- Phrase-typing values `REVOKE` / `DELETE` / `DENY` / `SIGN OUT` used consistently in `34-` / `36-` / `37-` / `41-`.
- Command Palette `Mod+K` referenced consistently in `13-` / `31-` / `32-` / `57-`.
- `AuthFailed` generic denial referenced consistently in `42-` / `56-` / `../21-app/03-authentication-oauth.md`.
- CSV rules (UTF-8 BOM, CRLF, ISO 8601 UTC) referenced consistently in `55-` / `58-` §6 bucket table.
- Reduced-motion detection via `matchMedia('(prefers-reduced-motion: reduce)')` referenced consistently in `51-` / `54-` / `58-`.

## 7. Change requests (queued for the next patch pass)

CR-01 (from F1..F10): add missing `Related:` back-links in 10 route blueprints. Estimated 20 min of doc edits, no behaviour change.
CR-02 (from F11..F14): fix reserved-word drift (`Retire` -> `Retract`, `Deactivate` -> `Disable`, `Plan` -> `Tier`, `Kill switch` -> `Revoke`). Estimated 15 min.
CR-03 (from F15): add `Superseded by 56-` banner to `27-content-voice.md`.
CR-04 (from F16..F18): document the `54-` <-> `51-` timing exception in `54-` §5; add clarifying comment in `58-` §6.
CR-05 (from F19..F20): tighten citation in `24-` §5 and `32-` §3 to name the `57-` §5 / §6 row.
CR-06 (from F22..F24): search-replace analytics event names across `34-42` to the canonical `Domain.Action.Outcome` grammar from `58-` §4.
CR-07 (from F25): fix `NotFound` -> `LicenseNotFound` / `SerialNotFound` in `41-`.
CR-08 (from F27..F30): tag every empty-state and loading-mode usage in `33-42` with the specific variant from `53-` §3 and `54-` §3.
CR-09 (from F32): add `/serials/{Id}/certificate.pdf` to the `55-` §3 route list if missing.
CR-10 (from F33): replace inline literal Button copy in blueprint example snippets with `copy.*` references.

CR-01 through CR-10 execute as documentation-only edits in the coming Plan 06 Step 50 (acceptance report + graduation), or as a separate follow-up patch if Step 50 is already loaded.

## 8. Severity legend

- **High:** blocks feature correctness or ships user-visible drift. None found.
- **Medium:** ships spec drift that a linter or careful reader will catch; 12 findings.
- **Low:** doc-only cleanup; 21 findings.

Total: 33 findings, 0 high, 12 medium, 21 low.

## 9. Linter follow-up

Six linters exist across the spec (`check-forbidden-strings.py`, `check-mmd-actor-order.py`, `check-openapi-parity.py`, `check-loading-states.py`, `check-print-export.py`, `check-copy-dictionary.py`, `check-hotkey-registry.py`, `check-analytics-events.py`). Add a lightweight META linter `check-blueprint-crossrefs.py` that:

- Scans every `spec/24-app-ui-design-system/33-*.md` through `42-*.md` file.
- Verifies each blueprint's `Related:` block lists the catalogs required by §4 of this document.
- Verifies each event / shortcut / empty-state / loading-mode / error-code reference cites its owning catalog by name.
- Runs in CI.

Deferred to Step 50 or a coming linter-hardening plan.

## 10. Acceptance criteria

- AC-CROSS-001: Every route blueprint (33..42) lists every catalog required by §4 in `Related:`.
- AC-CROSS-002: No reserved word from `56-` §10 is misused in any blueprint.
- AC-CROSS-003: Every event name in every blueprint matches `58-` §4 grammar.
- AC-CROSS-004: Every error code in every blueprint matches `../21-app/12-error-taxonomy.md`.
- AC-CROSS-005: Every empty-state usage names its variant per `53-` §3.
- AC-CROSS-006: Every loading-state usage names its mode per `54-` §3.
- AC-CROSS-007: `check-blueprint-crossrefs.py` passes once written.

AC-CROSS-001..006 are NOT satisfied at v0.166.0; the 33 findings in §5 are queued for the next patch pass. AC-CROSS-007 waits on Step 50.

## 11. Open items

- Actual CR-01..CR-10 patches applied in a coming step.
- `check-blueprint-crossrefs.py` implementation.
- Long-term drift prevention: pre-commit hook enforcing the linter set.
