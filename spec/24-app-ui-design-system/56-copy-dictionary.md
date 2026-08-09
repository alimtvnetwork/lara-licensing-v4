# Copy Dictionary

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Single normative source for button verbs, form labels, error strings, empty-state copy, toast copy, singular / plural rules, and destructive-phrase-typing values.
**Owner:** Copy governance. Every user-visible string in the app MUST source from this dictionary.
**Related:** [`27-content-voice.md`](./27-content-voice.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`26-component-form-field.md`](./26-component-form-field.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`53-empty-state-catalog.md`](./53-empty-state-catalog.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/28-audit-action-enum.md`](../21-app/28-audit-action-enum.md).

---

## 1. Purpose and scope

Pins the closed set of user-visible strings so runtime copy stays consistent across surfaces: `Save` never becomes `Save changes` in one place and `Update` in another, `Revoke` never softens to `Deactivate`, and error copy stays factual without blame or reassurance theatre.

Out of scope: marketing copy (LaraLicensingV1 has no marketing surfaces in v1); locale translations (single-locale v1); log lines (owned by `../21-app/22-log-line-contract.md`); tooltip help text longer than one sentence (owned by per-blueprint documents).

## 2. Voice rules (bind to `27-content-voice.md`)

- Direct, operational, second-person (`you` / `your`).
- No exclamation marks. No emoji. No em dashes. No welcome copy. No reassurance theatre (`Don't worry, ...`, `Just ...`).
- No first-person plural (`We were unable to ...`).
- Sentence case for buttons, labels, section headings, dialog titles. Title case is BANNED except for the brand mark.
- Present tense for state (`Active`, `Revoked`), past tense for confirmation Toasts (`License issued`), imperative for buttons (`Issue license`, `Revoke`, `Copy`).
- No trailing period on button labels, chip labels, badge labels, or single-sentence toast titles. Trailing period MANDATORY on multi-sentence body copy and on error `Message` strings.
- Numbers: numerals for counts (`3 users`); the words zero / one / two only in explanatory prose.
- Time: relative time ONLY in dashboards and feed rows (`3 minutes ago`); tables, exports, certificates, and audit log rows use absolute UTC ISO 8601 per `55-` §6.

## 3. Button verb registry (closed set)

| Action | Verb | Never |
|---|---|---|
| Create resource | `Add {resource}` (short catalogs) or `Issue {resource}` (licenses, serials) or `Invite {resource}` (users) or `Register {resource}` (clients) or `Publish {resource}` (updates) or `Request quota` | `New`, `Create`, `Make` |
| Save changes | `Save` | `Save changes`, `Update`, `Apply` |
| Discard changes | `Discard` | `Cancel changes`, `Revert`, `Undo` |
| Close a dialog | `Close` | `Dismiss`, `OK`, `Done` |
| Cancel a dialog action | `Cancel` | `No`, `Nope`, `Nevermind` |
| Confirm destructive action | `Revoke` / `Delete` / `Rotate secret` / `Deny` (matches the specific action; generic `Confirm` BANNED) | `Confirm`, `Yes`, `OK` |
| Retry a failed request | `Retry` | `Try again`, `Reload` |
| Refresh a live view | `Refresh` | `Reload`, `Update` |
| Copy to clipboard | `Copy` (state changes to `Copied` for 2 s after success per `52-` §5) | `Copy to clipboard` |
| Reveal a secret | `Reveal` (opposite: `Hide`) | `Show`, `Unmask` |
| Download | `Download {format}` (e.g. `Download PDF`, `Download CSV`) | `Export`, `Save as` |
| Upload | `Upload` | `Import` (RESERVED for structured bulk operations, not a general verb) |
| Filter | `Filter` (opens filter panel); `Reset filters` (in empty-state per `53-` §6) | `Apply filters`, `Clear`, `All` |
| Search | `Search` (placeholder + button label) | `Find`, `Lookup` |
| Sign in / out | `Sign in`, `Sign out`, `Sign out everywhere` | `Log in`, `Log out`, `Logout` |
| Assign / unassign a role | `Assign role`, `Remove role` | `Grant`, `Revoke role` (reserved for license revoke) |
| Approve a request | `Approve`, `Adjust`, `Deny` (three siblings on quota decision) | `Accept`, `Reject` |
| Rebind a device | `Request rebind` (end-user); `Rebind` (admin / builder) | `Move device`, `Switch device` |
| Rotate a secret | `Rotate secret` (opens Reveal-once card) | `Regenerate`, `Reset` |
| Sign an update | `Publish update` (Builder scope; combines sign + publish per `40-` §5) | `Sign`, `Push`, `Ship` |

`New {resource}` is BANNED because it is a noun phrase, not a verb; users click a button to DO something.

## 4. Form label registry

Every form Field label MUST be from this list; per-blueprint additions require appending a row here.

| Label | Field kind | Notes |
|---|---|---|
| `Email` | text (email autocomplete) | Never `Email address`, never `E-mail` |
| `Password` | text (password autocomplete) | Never `Passphrase` |
| `One-time code` | text (`autocomplete="one-time-code"`, `inputmode="numeric"`) | Per `42-` §3 |
| `Serial` | text (masked, no autocomplete) | Per `41-` §5 |
| `Reason` | textarea | OPTIONAL for security actions per `41-` §5 |
| `Justification` | textarea | REQUIRED for quota Approve / Adjust / Deny per `37-` §4 |
| `Delta` | number (integer) | Quota request Delta; positive integer |
| `Tier` | select (closed set from `43-` §3) | Never rendered as free text |
| `Environment` | select (closed set from `44-` §2) | Never rendered as free text |
| `Feature key` | text (regex-validated per `45-` §3) | Immutable after creation per `38-` §5 |
| `Feature label` | text | Editable |
| `Expiry` | date (ISO 8601 UTC) | Dual-form helper `2026-07-22 (Jul 22, 2026 UTC)` |
| `Machine binding count` | number (integer) | 0..N |
| `Reseller` | select | Reseller display uses `Label` field, never `ResellerId` |
| `Role` | multi-select (closed set from `04-roles.md`) | |
| `Label` | text | Human-readable name on Client, Update, Reseller |
| `Version` | text (SemVer regex per `40-` §5) | |
| `Channel` | select (`Stable`, `Beta`, `Dev`) | Closed set per `40-` §5 |
| `Redirect URI` | text (URL) | Same-origin path only per `42-` §2 |
| `Description` | textarea | OPTIONAL; max 480 chars |
| `Reveal confirmation` | checkbox `I have copied the install command` | Mandatory gate per `41-` §5 |
| `Phrase confirmation` | text (exact match) | Values per §7 |

## 5. Error copy registry

Every `ErrorCode` in `../21-app/12-error-taxonomy.md` maps to exactly ONE user-visible `Message` string. Runtime MAY append a redacted RequestId suffix but MUST NOT rephrase the message.

| ErrorCode | Message |
|---|---|
| `AuthFailed` | `Sign in failed. Check your email and password.` (generic; distinguishing unknown-email vs wrong-password BANNED per `42-` §3) |
| `Unauthorized` | `Your session expired. Sign in again to continue.` |
| `Forbidden` | `You do not have access to this action.` (extended reason ONLY when the caller has permission to see it per `40-` §11) |
| `LicenseNotFound` | `License not found.` (never `You do not have access to this license`; enumeration oracle per `41-` §3) |
| `SerialNotFound` | `Serial not found.` |
| `ClientNotFound` | `Client not found.` |
| `DeviceNotFound` | `Device not found.` |
| `QuotaExhausted` | `Quota exhausted. Request more quota before issuing another license.` |
| `QuotaAlreadyDecided` | `This request was already decided. Refresh to see the current decision.` |
| `EnvironmentMismatch` | `This action is not allowed in the current environment.` |
| `PreconditionFailed` | `Someone edited this record while you had it open. Refresh and try again.` |
| `RateLimited` | `Too many requests. Wait {RetryAfterSec} seconds and try again.` (renders the number when `Error.RetryAfterSec` is present) |
| `ValidationFailed` | `Some fields need attention.` (per-field errors rendered inline per `26-` §5) |
| `InternalError` | `Something failed on our side. Try again in a moment.` (never `Sorry`, never `Oops`) |
| `OAuthStateInvalid` | `Sign in was interrupted. Start over from the sign-in page.` |
| `ClientSecretAlreadyRotated` | `This secret was rotated by someone else. Refresh to see the current secret.` |

- Error `Message` copy is READ-ONLY at runtime. Do NOT interpolate user-controlled data into these strings.
- Copy longer than 120 chars BANNED.
- Suffixing with `(Request {RequestIdShort})` is permitted for support-ticket triage; the short form is the last 8 chars of the RequestId.

## 6. Toast copy registry

Per `23-` §5 Toasts fire on mutation success, mutation failure, and long-running background events. Every Toast MUST be from this list.

| Trigger | Variant | Title | Body (optional) |
|---|---|---|---|
| License issued | success | `License issued` | `Certificate is ready to download.` |
| License renewed | success | `License renewed` | new expiry rendered in body |
| License revoked | success | `License revoked` | reason echoed in body |
| Serial issued | success | `Serial issued` | `Serial value shown once; copy it now.` |
| Serial verified | success | `Serial verified` | product name in body |
| Serial revoked | success | `Serial revoked` | |
| Client registered | success | `Client registered` | `Client secret shown once; copy it now.` |
| Client secret rotated | success | `Client secret rotated` | previous secret invalidated warning |
| Update published | success | `Update published` | version + channel in body |
| Update retracted | success | `Update retracted` | version + channel in body |
| Quota request submitted | success | `Quota request submitted` | `An admin will review it shortly.` |
| Quota request approved | success | `Request approved` | new balance in body |
| Quota request adjusted | success | `Request adjusted` | new balance in body |
| Quota request denied | success | `Request denied` | reason echoed in body |
| Role assigned | success | `Role assigned` | |
| Role removed | success | `Role removed` | |
| Feature added | success | `Feature added` | |
| Feature deprecated | success | `Feature deprecated` | `Existing licenses keep this feature.` |
| Sign out everywhere | success | `Signed out of all sessions` | `Redirecting...` |
| Copy succeeded | inline (no toast) | -- | Button label swaps to `Copied` for 2 s |
| Copy failed | error | `Copy failed` | `Select the text and copy it manually.` |
| Any mutation failure | error | `{Action} failed` | maps to `Error.Message` from `12-error-taxonomy.md` per §5 |
| Network offline | warning | `You are offline` | `Actions will retry when the connection returns.` (banner, not toast) |
| Long-running (2 s) | info | `Still working...` | Banner per `54-` §3 max-wait-md |

## 7. Destructive phrase-typing values

Per `21-` §7 destructive actions require the user to type an exact phrase to enable the confirm button.

| Action | Phrase | Notes |
|---|---|---|
| Revoke license | `REVOKE` | Uppercase; NOT the LicenseId |
| Revoke serial | `REVOKE` | |
| Delete user | `DELETE` | Actual verb varies (Delete vs Disable); phrase mirrors the verb |
| Disable user | `DISABLE` | |
| Deny quota request | `DENY` | Per `37-` §4 |
| Rotate client secret | Client `Label` (human-readable) | Per `40-` §4 |
| Retract update | SemVer version string of the update | Per `40-` §5 |
| Sign out everywhere | `SIGN OUT` | Per `41-` §5 |
| Deprecate feature | `FeatureKey` (immutable value) | Per `38-` §5 |

- Phrase-typing on UUIDs BANNED (users can be tricked into typing the wrong UUID from the URL bar).
- Phrase-typing MUST NOT be case-insensitive (the phrase is the phrase; case sensitivity is deliberate friction).
- Copy that says `Type YES to confirm` BANNED (too easy to autocomplete); the phrase is always specific to the action.

## 8. Singular / plural

Every countable label MUST render the correct singular / plural. The app uses an inline `pluralize(n, singular, plural)` helper; the helper's inputs live in this dictionary.

| Singular | Plural | Zero (if different from plural) |
|---|---|---|
| `license` | `licenses` | `licenses` |
| `serial` | `serials` | `serials` |
| `user` | `users` | `users` |
| `device` | `devices` | `devices` |
| `session` | `sessions` | `sessions` |
| `feature` | `features` | `features` |
| `environment` | `environments` | `environments` |
| `tier` | `tiers` | `tiers` |
| `client` | `clients` | `clients` |
| `update` | `updates` | `updates` |
| `request` | `requests` | `requests` |
| `role` | `roles` | `roles` |
| `override` | `overrides` | `overrides` |
| `product` | `products` | `products` |

- Zero uses the plural form (`0 licenses`, `1 license`, `2 licenses`).
- `You have no licenses` is preferred over `You have 0 licenses` in empty-state copy per `53-` §5.
- Locale-aware CLDR plural rules deferred (single-locale v1); the two-form rule above is sufficient for English.

## 9. Confirmation and detail patterns

- Confirmation Dialog title mirrors the action verb: `Revoke license?`, `Delete user?`, `Rotate client secret?`. Question mark MANDATORY.
- Confirmation Dialog body: one sentence stating the consequence, one sentence stating the reversibility, one sentence stating the phrase-typing gate.
- Success confirmation Toast: past-tense state (`License revoked`), NEVER past-tense action (`Revoked license`).
- Detail-page section headings: sentence case, singular unless the section is a list (`Machine bindings`, not `Machine binding`).
- Empty-state copy from §5 in `53-` catalog.

## 10. Reserved words

The following English words carry specific meaning in the app; they are RESERVED and MUST NOT be used for anything else in the UI:

- `Revoke`: license or serial state transition; never for role removal, never for update retraction.
- `Retract`: update state transition only.
- `Rotate`: client secret rotation only.
- `Adjust`: quota decision only (a `Delta` that is neither the full request nor zero).
- `Deny`: quota decision only.
- `Deprecate`: feature state only; never `Sunset`, `Retire`, or `Archive`.
- `Environment`: the deployment scope (Production / Staging / Development); never a synonym for `Tenant`.
- `Tier`: license tier (Free / Standard / Premium / Enterprise); never `Plan` or `Package`.
- `Feature`: feature-flag scope; never `Capability` or `Function`.

## 11. Prohibited copy

- `Oops`, `Uh oh`, `Whoops`, `Sorry`, `We are sorry`, `Please try again` (verb missing) BANNED.
- `Just`, `Simply` BANNED (they minimise the reader's task).
- `Enter your credentials` BANNED (say `Sign in with your email and password`).
- `Awesome`, `Great job`, `You did it`, `Success!` BANNED (celebratory tone; per `51-` §12 motion is informative not decorative).
- `Are you sure?` BANNED on confirmation dialogs (the question-mark title from §9 already asks; a second `Are you sure` insults the reader). Use the phrase-typing gate as the second signal.
- `Please contact support` without a working link BANNED (either link support or drop the sentence).
- `Click here` BANNED; anchor text must describe the destination.

## 12. Copy source location

- Runtime source: `src/lib/copy.ts` (auto-generated from this document by a build step; hand-editing that file BANNED).
- Every consumer imports via `import { copy } from '@/lib/copy'` then reads `copy.buttons.revoke`, `copy.errors.LicenseNotFound`, `copy.toasts.LicenseIssued.title`, etc.
- Inline literals (`<Button>Revoke</Button>`) are BANNED for any string in this dictionary; a linter (§14) enforces the ban.

## 13. Anti-patterns (BANNED)

1. Title case on buttons, labels, or headings (except brand mark).
2. Trailing period on button labels or badge labels.
3. Reassurance theatre in error copy.
4. Exclamation marks.
5. Emoji in UI copy.
6. Em dashes.
7. First-person plural.
8. Distinguishing unknown-email vs wrong-password.
9. Copy longer than 120 chars in error `Message` strings.
10. Interpolating user-controlled data into error `Message` strings.
11. Reserved word (§10) used for anything else.
12. Phrase-typing on a UUID.
13. Case-insensitive phrase-typing.
14. `Click here` anchor text.
15. Copy sourced from anywhere other than `src/lib/copy.ts`.
16. Locale-aware plural rules for English in v1 (deferred; two-form rule is sufficient).
17. Two different verbs for the same action across surfaces.
18. Success Toast in past-tense action form (`Revoked license`) instead of past-tense state (`License revoked`).
19. `Sorry`, `Oops`, `Uh oh`, `Just`, `Simply`, `Please try again` (verb missing).
20. Marketing / welcome copy in a console app.

## 14. Linter (`check-copy-dictionary.py`)

New linter under `linter-scripts/check-copy-dictionary.py`:

- Scans `src/**/*.tsx` for `<Button>`, `<Label>`, `<DialogTitle>`, `<AlertTitle>` children that are inline string literals.
- Scans for `toast.success(...)` / `toast.error(...)` calls with inline titles.
- Cross-references every string with the §3 / §4 / §5 / §6 / §7 / §8 registries via `src/lib/copy.ts` keys.
- Fails on: inline string literal outside `copy.*`, prohibited word from §11, title-case violation, trailing period on button label, phrase-typing value not in §7, ErrorCode message string mismatch between `12-error-taxonomy.md` and this document.
- Runs in CI and via `./linter-scripts/run.sh check-copy-dictionary`.

## 15. Acceptance criteria

- AC-COPY-001: Every user-visible string in the app is sourced from `src/lib/copy.ts` generated from this document.
- AC-COPY-002: Button verbs follow §3 (one canonical verb per action).
- AC-COPY-003: Error `Message` strings follow §5 exactly; runtime does not rephrase.
- AC-COPY-004: Destructive phrase-typing values follow §7; no phrase-typing on UUIDs.
- AC-COPY-005: Reserved words in §10 are used for their reserved meaning only.
- AC-COPY-006: Prohibited copy in §11 is absent from the shipped bundle.
- AC-COPY-007: `check-copy-dictionary.py` passes.

## 16. Open items

- CLDR plural rules for future locales (deferred; single-locale v1).
- Copy-editor sign-off for §5 error messages and §6 toast titles (production-grade review before v1 GA).
- Support docs URL configured at build time (`import.meta.env.VITE_DOCS_URL`) so `Learn more` links point somewhere real.
