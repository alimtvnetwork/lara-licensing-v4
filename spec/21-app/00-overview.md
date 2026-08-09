# App: LaraLicensingV1

**Version:** 1.1.0
**Updated:** 2026-07-19
**AI Confidence:** Draft
**Ambiguity:** Low
**Verdict:** 100.0, Band A. Plain-language summary in [`../25-app-audit/00-verdict-plain.md`](../25-app-audit/00-verdict-plain.md).

---

## Overview

`LaraLicensingV1` is a multi-tenant licensing platform. A Laravel REST API generates, verifies, and manages software licenses for external apps (Windows desktop, web, CLI). A React (this Lovable TanStack Start project) front end delivers the admin console, reseller panel, and end-user verification screens.

The verbatim source dictation lives in [`01-initial-sepc.md`](./01-initial-sepc.md). All spec files in this folder derive from it.

---

## Scope

**In scope:**

- License generation with categories (Daily, Weekly, Monthly, Yearly, Lifetime, Dev, Key).
- License variations by `User` count and `Machine` count.
- Serial number generation with reseller prefixes and embedded category/version.
- Hash key and verify key exchange for end-user verification.
- Role-based access (Admin, Reseller, AppBuilder, EndUser).
- Authentication via JWT and OAuth2 client-credentials.
- REST endpoints with PascalCase JSON payloads.
- UI surfaces for admin, reseller, and end-user verification.

**Out of scope (this version):**

- Subscription billing.
- Client-side anti-tamper beyond standard best effort (accepted risk).
- Mobile-native SDKs.

---

## Actors

| Actor | Description |
|-------|-------------|
| `Admin` | Full control over roles, resellers, license categories, packages. |
| `Reseller` | Manages own prefixes, generates licenses within allotted quota. |
| `AppBuilder` | Integrates the licensing API into their own software; calls verify endpoints. |
| `EndUser` | Enters serial number in the app; never talks to the API directly. |
| `LicensingServer` | The Laravel API itself. |

---

## Contents

| File | Purpose |
|------|---------|
| [`01-initial-sepc.md`](./01-initial-sepc.md) | Verbatim source dictation, not editable. |
| [`02-authentication-jwt.md`](./02-authentication-jwt.md) | JWT issue/refresh/revoke flow. |
| [`03-authentication-oauth.md`](./03-authentication-oauth.md) | OAuth2 client-credentials + authorization-code flow. |
| [`04-roles.md`](./04-roles.md) | Role-permission matrix. |
| [`05-license-categories.md`](./05-license-categories.md) | Category enum and lifetime semantics. |
| [`06-license-variations.md`](./06-license-variations.md) | User and Machine parameters. |
| [`07-serial-generation.md`](./07-serial-generation.md) | Serial format, prefixes, embedded metadata. |
| [`08-hash-key.md`](./08-hash-key.md) | Hash key inputs, algorithm, configurable length. |
| [`09-verify-key.md`](./09-verify-key.md) | Server-side verify key generation and final check. |
| [`10-endpoints.md`](./10-endpoints.md) | REST endpoint catalog. |
| [`11-api-contracts/`](./11-api-contracts/00-overview.md) | Typed request, response, validation, status, and observability contracts. |
| [`17-self-update-endpoint.md`](./17-self-update-endpoint.md) | Self-update wire contract: `/App/UpdateManifest`, `/App/UpdateAsset/{Version}/{Platform}`, `/Admin/AppUpdates/UploadTicket`, `/Admin/AppUpdates`. Named Draft/Staged/Published/Yanked states, closed Platform enum, MUST-abort table A1..A10, and the client update sequence diagram (probe, download, verify, rename, handoff). SSOT per [`../25-app-audit/A1-diff.md`](../25-app-audit/A1-diff.md). |
| [`21-error-management-binding.md`](./21-error-management-binding.md) | Per-endpoint log level and retry class bindings to `spec/03-error-manage/`. |
| [`22-log-line-contract.md`](./22-log-line-contract.md) | Structured log field schema, redaction rules, human formatter shape. |
| [`23-catch-log-rethrow-patterns.md`](./23-catch-log-rethrow-patterns.md) | Golden server and client catch-block patterns with acceptance criteria. |
| [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md) | Canonical role, resource, and path casing; UI citation status (canonical, alias, deferred v1.1). |

Diagrams (Mermaid) live under [`../23-app-db/`](../23-app-db/) per the initial spec.

---

## Cross-References

| Reference | Location |
|-----------|----------|
| App Issues | [../22-app-issues/00-overview.md](../22-app-issues/00-overview.md) |
| App DB and Diagrams | [../23-app-db/00-overview.md](../23-app-db/00-overview.md) |
| UI Design System | [../24-app-ui-design-system/00-overview.md](../24-app-ui-design-system/00-overview.md) |
| Coding Guidelines | [../02-coding-guidelines/00-overview.md](../02-coding-guidelines/00-overview.md) |

---

## Conventions

- PascalCase for all tables, fields, JSON keys and values.
- Every primary key is an auto-increment integer named `PascalCaseTableName + Id`.
- `Type`, `Status`, `Category`, `Kind` columns are Enums modeled as small integer joins.
- Boolean fields prefixed with `Is` or `Has`.
- No implementation code in this phase, spec only.

---

## Normative sources

Every clause under `spec/21-app/` is bound by, and must not contradict, the following:

- `.lovable/coding-guidelines/coding-guidelines.md`: function-length tiers, positive boolean naming, no swallowed errors, PascalCase data names, camelCase code, Enum-backed Type/Status/Kind/Category columns.
- `spec/02-coding-guidelines/01-cross-language/15-master-coding-guidelines.md`: master cross-language rules.
- `spec/03-error-manage/`: catch-log-rethrow, log levels, request-id propagation, no silent failure. Endpoint-level binding lives in [`21-error-management-binding.md`](./21-error-management-binding.md); caller-side retry rules in [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md); route-to-DTO index in [`26-route-dto-index.md`](./26-route-dto-index.md); test coverage matrix in [`27-error-code-test-matrix.md`](./27-error-code-test-matrix.md).
- `.lovable/strictly-avoid/`: SA-031 (PascalCase data), SA-041 (separate user roles), SA-042 (server-side authorization), SA-044 (no admin client auth check).

Conflicts resolve in favor of the more specific source: leaf spec file beats `spec/21-app/00-overview.md`, folder-level spec beats `.lovable/*.md`. Any unresolvable conflict is a finding and MUST be logged in `spec/25-app-audit/98-findings-index.md`.

---

## Version history

| Version | Date | Reason |
|---------|------|--------|
| 3.3.0 | 2026-07-15 | Pre-consolidation transcript-inherited tag on `00-overview.md` only. |
| 1.0.0 | 2026-07-16 | Full-folder version reset to a single normative baseline. Every `spec/21-app/**/*.md` (except the raw dictation `01-initial-sepc.md`) reset to `1.0.0`. Recorded in `.lovable/memory/history/01-decisions.md`. Closes the drift finding in `spec/25-app-audit/02-file-inventory.md`. |
| 1.1.0 | 2026-07-19 | Plan 12 (audit-v2) step 4: cross-linked `17-self-update-endpoint.md` v1.5.0 into the Contents table so the self-update endpoint contract (including the named Draft/Staged/Published states and client update sequence diagram) is discoverable from the folder root. |


