# Content Voice and Copy

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI copy across every surface.
**Owner:** Single normative source for tone, sentence shape, error copy structure, destructive confirmation phrasing, locale policy, and privacy rules for user-facing strings. Binds every existing component spec (`15`..`25`) that mandates copy contracts.
**Related:** [`05-team-mood-and-ux-north-star.md`](./05-team-mood-and-ux-north-star.md), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`21-component-dialog.md`](./21-component-dialog.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Voice

Operator-grade: calm, precise, second-person, active voice, no marketing register. The reader is a licensing administrator, reseller, app builder, or end user acting under time pressure. Copy MUST reduce ambiguity, not decorate it.

| Do | Do not |
|----|--------|
| "Revoke license `L-3210`?" | "Are you sure you want to revoke this license?!" |
| "Signed out." | "You've been successfully signed out. See you soon!" |
| "Could not save. Try again." | "Oops, something went wrong :(" |
| "Rate limit reached. Retry in 12 s." | "Too many requests. Please slow down." |

Banned tone signals: exclamation marks, "Oops", "Whoops", "Uh-oh", "please" (implied by default), "sorry", emoji, ALL CAPS words other than product names, marketing adjectives ("blazing", "seamless", "powerful"), and second-person plural ("we're on it").

## 2. Sentence shape

- Sentence case for every string EXCEPT proper nouns and product names (`LaraLicensingV1`, `Swagger`). Title Case is BANNED.
- One idea per sentence. Period ends every full sentence including Toast messages. Fragments used as button labels or headers do not take a period.
- Under 90 characters for Toast body, under 140 for Banner body, under 200 for Dialog body. Longer content moves to a linked detail Sheet or docs page.
- Numbers `< 10` spelled out only in prose paragraphs; every UI count, quota, and duration is numeric with `tabular-nums` per `09-typography-scale.md`.
- Time is absolute in ISO-8601 UTC for machine surfaces (audit rows, RequestId chips) and relative in prose ("12 s", "3 min", "in 2 h"); mixing the two in the same field is BANNED.

## 3. Labels

- Buttons: verb-first, imperative ("Revoke", "Renew", "Copy", "Sign out"). Nouns as Button labels are BANNED except the primary action of a Dialog that mirrors the Dialog title verb ("Revoke license" -> Button "Revoke").
- Fields: noun phrase, sentence case, no trailing colon, no "(required)" suffix (required is the default; optional is the marked case per `18-component-input.md` §2).
- Headers: noun or noun phrase; H1 = the resource or route ("Licenses", "License `L-3210`"). Verbs in H1 are BANNED.
- Menu items: verb-first when action, noun-first when navigation. Ellipsis suffix `…` when the item opens a modal per `22-component-menu-popover.md`.

## 4. Empty states

Structure (all three lines required, in order):

1. Line 1: a single noun sentence naming what is absent. ("No licenses yet.")
2. Line 2: a single actionable next step referencing the primary Button by verb. ("Create the first license from the Issue Button.")
3. Line 3 (optional): a docs link ("Read the licensing overview.") using `ArrowUpRight` external-link icon.

Filtered empty states replace line 2 with "Clear filters to see all rows." and expose the Clear filters Button per `24-component-table.md` §7.

## 5. Error copy (mandatory triad)

Every user-visible error surface (Toast error variant, Banner error variant, Dialog error footer, route-shell 500) MUST render the triad in this order:

1. **State line:** what failed, in the user's frame. ("Could not save license.")
2. **Remedy line:** what to do next, imperative. ("Try again in a moment.") If no remedy is possible ("Contact your administrator."), say so explicitly.
3. **Diagnostics chip:** `ErrorCode` + `RequestId` rendered via `<Identifier>`; `no-request-id` fallback per `23-component-toast-banner.md` §2.

Raw exception messages, stack traces, or backend framework names (`Laravel`, `TanStack`, `Vite`, `Cloudflare`) MUST NOT appear in user copy. The `ErrorCode` is the ONLY machine identifier the user sees.

Copy per `ErrorCode` (state + remedy lines only; diagnostics chip is automatic):

| ErrorCode | State line | Remedy line |
|-----------|------------|-------------|
| `Validation` | "Fix the highlighted fields." | (no remedy line; field errors carry it) |
| `Conflict` | "Another change was already applied." | "Refresh to see the current state." |
| `IdempotencyReplay` | "This action was already completed." | "The result you see is the previous outcome." |
| `NotFound` | "Not found." | "Check the identifier or return to the list." |
| `AuthzRoleDenied` | "You do not have access to this area." | "Ask an administrator to grant access." |
| `AuthzPermissionDenied` | "You do not have permission for this action." | "Ask an administrator to grant the required permission." |
| `AuthTokenExpired` | "Your session has expired." | "Sign in again to continue." |
| `AuthTokenInvalid` | "Your session is no longer valid." | "Sign in again to continue." |
| `AuthRefreshRaceLost` | "Could not refresh your session." | "Sign in again to continue." |
| `AuthSaltRotationFailed` | "Sign-in temporarily unavailable." | "Try again in a few minutes." |
| `RateLimited` | "Too many requests." | "Retry in {N} s." (RetryAfterBanner owns the countdown) |
| `QuotaExhausted` | "Quota reached." | "Request more quota or wait for the next period." |
| `EnvironmentMismatch` | "This license belongs to a different environment." | "Switch environment or use the correct license." |
| `FeatureUnknown` | "Unknown feature." | "Check the feature key." |
| `FeatureValueInvalid` | "Feature value is not valid." | "Enter a value in the allowed range." |
| `UnknownServerError` | "Something went wrong on the server." | "Try again. If it keeps happening, contact support with the RequestId below." |

Field-level validation copy uses the field name + a corrective verb ("Serial must be 16 characters."), never "Invalid input."

## 6. Destructive confirmations

Dialog title = verb + resource identifier. ("Revoke license `L-3210`?")
Dialog body = one-sentence impact statement ("Revoked licenses cannot be reactivated; a new license must be issued.") plus, when the action affects multiple resources, the count in `tabular-nums`.
Primary Button label = the exact verb from the title, no "Yes, revoke" or "Confirm revoke".
Bulk-action phrase-typing per `21-component-dialog.md` §5 uses the resource plural in sentence case ("Type `revoke 12 licenses` to confirm."). The phrase MUST match a regex documented in the surface's blueprint; localization of the phrase is OUT OF SCOPE for v1.

## 7. Locale policy (v1)

- v1 ships `en-US` only. No i18n runtime.
- Copy MUST be authored as if it will be localized: no concatenated fragments, no gendered pronouns, no idioms ("piece of cake", "on the fence"), no cultural references, no puns.
- Placeholders use named ICU-compatible tokens (`{Count}`, `{RetryAfterSeconds}`), never `%s` / positional `{0}`. This keeps a future locale extraction mechanical.
- Numbers, dates, and currency remain English formatting; do not hand-format thousands separators.

## 8. Privacy in copy

- Field values (serials, hashes, email addresses, license notes) MUST NOT appear in Toast, Banner, or log copy. Identifiers (`RequestId`, `LicenseId`, `SerialId`, `UserId`) are permitted.
- User-supplied strings displayed back to the user (e.g. a license note in a Sheet) MUST be rendered inside a container with `overflow-wrap: anywhere` and `max-lines: 6`; long content collapses behind a `Show more` disclosure.
- Never echo a password, MFA code, or secret Value in any surface, including a "for your reference" confirmation.

## 9. Copy inventory

The design-system authors keep a running inventory of every literal string in a future `spec/24-app-ui-design-system/copy-inventory.md`. v1: the inventory is deferred; per-surface blueprints (`29-per-surface-blueprints/*`) MUST list every literal string they introduce in their own Copy section. Feature code MUST NOT introduce a new string that is not present in either this file or the blueprint's Copy section.

## 10. Anti-patterns

1. Exclamation marks anywhere in the UI.
2. "Oops", "Whoops", "Uh-oh", "Sorry".
3. Title Case labels.
4. Trailing colon on Field labels.
5. "(required)" suffix; use "(optional)" for the marked case.
6. Verb Button label expanded to "Yes, verb" or "Confirm verb".
7. Raw exception messages or framework names in user copy.
8. Field values in Toast / Banner text.
9. Concatenated sentence fragments ("Item " + name + " deleted.").
10. Positional placeholders (`%s`, `{0}`).
11. Emoji as UI decoration.
12. Marketing adjectives ("blazing", "seamless", "powerful", "delightful").
13. Second-person plural ("we're working on it").
14. Time strings that mix absolute UTC with relative prose in the same field.

## 11. Acceptance criteria

- AC-CPY-001: Every `ErrorCode` in `../21-app/12-error-taxonomy.md` has exactly one row in §5; a future `check-copy-parity.py` asserts parity.
- AC-CPY-002: No shipped string contains `!`, `Oops`, `Whoops`, `sorry` (case-insensitive), `Laravel`, `TanStack`, `Cloudflare`, `Vite`, or positional placeholders; enforced by `linter-scripts/check-forbidden-strings.py` (extended set to be added in the same commit as this file's runtime binding).
- AC-CPY-003: Every Dialog primary Button label matches the verb in the Dialog title (regex parity check in future `check-destructive-copy.py`).
- AC-CPY-004: No Toast or Banner body includes a field value; verified by inspection of `use-lara-error-toast.ts` callers.
- AC-CPY-005: All placeholders are named (`{Foo}` form); no `%s` or `{0}` in shipped copy.
- AC-CPY-006: Every empty state renders the three-line structure from §4 (line 3 optional but the first two required).

## 12. Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0 on this file.
- Manual: §5 rows equal the union of `ErrorCode` values in `../21-app/12-error-taxonomy.md`.
