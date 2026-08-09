# A1. Self-Update Spec Diff

Version: 1.0.0
Updated: 2026-07-19
Owner: `spec/25-app-audit/03-plan-300-steps.md` step 1.

## Purpose

Reconcile the two self-update specs before backend controllers are scaffolded (steps 11 to 25 of Plan 12). Both files are authoritative in their own scope; this diff pins where they must be kept in sync and where each file is the single source of truth (SSOT).

Files compared:

- **S21**: `spec/21-app/17-self-update-endpoint.md` v1.3.0 (server / wire contract).
- **S14**: `spec/14-update/01-self-update-overview.md` (client behaviour, no version banner).

## 1. Scope split (agreed SSOT)

| Concern | SSOT | Rationale |
|---|---|---|
| HTTP endpoints, paths, methods, auth | S21 | Server contract. |
| Envelope, ErrorCode strings, HTTP status mapping | S21 | Enforced by `12-error-taxonomy.md`. |
| Platform enum (`WindowsAmd64`, `LinuxAmd64`, `DarwinArm64`) | S21 | Closed set. |
| Publish state machine (Ticket, Upload, Finalize) | S21 | DB-bound. |
| Client update strategies (source vs binary) | S14 | Client-side. |
| Rename-first-deploy sequence | S14 (delegates to `03-rename-first-deploy.md`) | OS-specific. |
| MUST-abort rows A1..A10 | S21 (server); client must implement | S21 wire semantics drive them. |
| Version comparison rules (skip-if-current) | S14 | CLI behaviour. |

## 2. Deltas (must resolve before step 2)

| # | Topic | S21 says | S14 says | Delta | Resolution owner |
|---|---|---|---|---|---|
| D1 | Publish state machine label | Steps `UploadTicket -> PUT upload -> POST AppUpdates` (no named states) | Silent | Plan 12 step 2 requires named states Draft, Staged, Published. Neither doc uses that vocabulary today. | S21 (step 2 rewrite). |
| D2 | Channel policy in v1 | Stable-only per §"v1.0 rollout policy"; Beta enum reserved | Silent | S14 must acknowledge Stable-only default so CLI docs do not promise Beta. | S14 (follow-up, out of step 1). |
| D3 | Platform enum coverage | Closed set of three: WindowsAmd64, LinuxAmd64, DarwinArm64 | Only mentions Windows vs Linux/macOS categories | S14 must reference S21 enum by name, not by category, when discussing binary suffix. | S14 (follow-up). |
| D4 | Abort A5 (InsecureTransport) | S21 requires TLS at every hop | S14 does not enumerate transport requirements | S14 must cite S21 §MUST-abort. | S14 (follow-up). |
| D5 | Abort A9 (disk preflight) | Requires free disk >= `SizeBytes * 2` | Not surfaced in overview error table | S14 error table needs a row referencing A9. | S14 (follow-up). |
| D6 | Audit events | Names: `UpdatePublished`, `UpdateDownloaded`, `UpdateVerified`, `UpdateVerificationFailed`, `UpdateYanked` | Not surfaced | S14 need not enumerate but must link. | S14 (follow-up). |
| D7 | ErrorCode casing | PascalCase, no SCREAMING_SNAKE_CASE alias | Not surfaced | No conflict; note only. | none. |
| D8 | Version-unchanged warning | Not covered | S14 warns but does not fail | Confirm the client MAY warn and still emit a benign `UpdateDownloaded`. | S14. |
| D9 | Signature verification | Optional but pinned when enabled (A8) | Not surfaced | S14 must reference §Signature verification. | S14 (follow-up). |
| D10 | Yank irreversibility | Immediate, no un-yank in v1 | Not surfaced | S14 must document that an in-flight download continues, but a re-invoked `update` sees the row gone. | S14 (follow-up). |
| D11 | Rate limits | Manifest 600/min/IP, Asset 20/min/IP | Not surfaced | Client should back off on 429 with `Retry-After`; already covered by `use-retry-after-countdown.ts`. Confirm reference. | S14. |
| D12 | Idempotency | AC-SU-ADMIN-005 requires idempotent re-POST of `/Admin/AppUpdates` | Not applicable (client) | Note only. | none. |

Deltas D2..D11 do NOT block step 1; they are logged here so step 2 (raising S21 to v1.1.0) can decide which cross-refs to add and a later step can amend S14 without re-diffing from zero.

## 3. Terminology reconciliation for step 2

Step 2 will introduce these named states in S21:

- **Draft**: an `AppUpdateAssets` row exists with `IsFinalized = 0` and `UploadTicketExpiresAt > NOW()`.
- **Staged**: every platform's upload succeeded (storage layer verified `X-Sha256` and `Content-Length`), but no `AppUpdates` row exists yet. This is a computed state; not a column.
- **Published**: `AppUpdates` row exists with `IsYanked = 0` and every referenced `AppUpdateAssets.IsFinalized = 1`.

Transitions:

```text
(nothing)
  -> Draft         via POST /Admin/AppUpdates/UploadTicket
  -> Staged        via PUT {UploadUrl} (implicit, no row change)
  -> Published     via POST /Admin/AppUpdates (transactional finalize)
  -> Yanked        via POST /Admin/AppUpdates/{Version}/Yank (terminal)
```

The `Staged -> Published` transition is atomic (§Publish state machine step 3 in S21). No client can observe `Staged` via `GET /App/UpdateManifest`.

## 4. Fields covered by S21 that step 8 (field-name audit) must verify

- `Product`, `Channel`, `LatestVersion`, `MinRequiredVersion`, `PublishedAt`, `Assets[]`.
- `Assets[].Platform`, `Url`, `SizeBytes`, `Sha256`, `SignatureUrl`.
- DB columns: `AppUpdates.IsYanked`, `YankedAt`, `YankedByUserId`, `PublishedByUserId`; `AppUpdateAssets.IsFinalized`, `UploadTicketExpiresAt`, `Sha256`, `Platform`.

Every field is PascalCase in both wire and DB; no snake_case aliases exist. Recorded here for step 10 (`A2-field-name-audit.md`).

## 5. Acceptance

Passing step 1 requires only that this file exists, enumerates every delta, and names an owner or "none" for each. Step 2 consumes §3 verbatim.

## 6. Cross-refs

- `spec/25-app-audit/03-plan-300-steps.md` steps 1..10.
- `spec/21-app/17-self-update-endpoint.md` v1.3.0.
- `spec/14-update/01-self-update-overview.md`.
- `spec/21-app/12-error-taxonomy.md` v1.2.0 (ErrorCode PascalCase rule).
- `spec/23-app-db/01-schema.md` §"Self-Update".
