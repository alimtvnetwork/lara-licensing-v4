# Components and States

**Version:** 0.23.0
**Updated:** 2026-07-15
**Status:** Active
**Category:** UI / Frontend

## 1. Commands

| Variant | Use |
|---------|-----|
| Primary | One principal mutation per region. |
| Secondary | Non-destructive alternative or navigation. |
| Ghost | Toolbar and low-emphasis commands. |
| Destructive | Revoke, delete, deactivate, and block. |
| Icon | Familiar compact commands with tooltip and accessible name. |

Buttons have stable heights of 32px (compact) or 40px (default). Loading keeps the label width stable, disables repeat submission, and shows progress. Disabled controls remain explainable through adjacent help text or a tooltip.

## 2. Forms

Labels remain visible above controls. Placeholder text is an example, never the only label. Validation appears inline after blur and on submit, with a summary focused at the top when multiple fields fail. Password, secret, serial, and hash fields support reveal or copy only when the contract permits it.

Dangerous transitions require a confirmation dialog naming the affected entity and irreversible effect. Typing a confirmation phrase is reserved for bulk or irreversible revocation.

## 3. Tables and Lists

Admin, reseller, and builder datasets use tables at desktop widths. Every table defines:

- a visible caption or page heading association;
- sortable column state with accessible direction;
- filters encoded in URL search parameters;
- pagination that preserves filters;
- loading, empty, error, and partial-data states;
- a row action menu rather than multiple text buttons;
- a horizontal overflow container when columns cannot collapse safely.

Identifiers use monospace and a copy icon. Dates render in the viewer's locale with the UTC value available in a tooltip. Mobile may transform rows into repeated record cards, never nested cards.

## 4. Status System

| Domain state | Tone | Required label |
|--------------|------|----------------|
| Draft, Unbound | Neutral | Exact state name. |
| Active, Bound, Verified | Success | Exact state name. |
| Expired | Neutral | `Expired`. |
| Suspended, Expiring, RateLimited | Warning | Exact state or retry time. |
| Revoked, AbuseBlocked, Failed | Destructive | Exact state or error code. |

Status badges are compact labels with an icon or shape cue. They are not interactive unless implemented as a filter control.

## 5. Feedback and Overlays

- Toasts confirm transient success and non-blocking failures. Error toasts include `ErrorCode`; rate-limit toasts include `Retry-After`.
- Inline alerts communicate persistent route or form conditions.
- Dialogs handle confirmations and focused short forms.
- Drawers handle supporting detail that does not require a shareable route.
- Full record detail and edit workflows use routes, not oversized dialogs.

`RequestId` appears in an Advanced disclosure and has a copy command. No toast disappears before 6 seconds when it contains an error or retry instruction.

## 6. KPI and Chart Rules

KPI cards contain a label, value, comparison period, and optional trend. Charts always include a textual summary, labeled axes, and a non-color distinction between series. Revenue remains visibly marked as unavailable until a contract supplies it.

## Cross-References

- [License lifecycle](../21-app/15-license-lifecycle.md)
- [Rate limiting](../21-app/14-rate-limiting.md)
- [Audit logging](../21-app/13-audit-logging.md)
- [Visual foundations](./01-visual-foundations.md)