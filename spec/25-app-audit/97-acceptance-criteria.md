# 25. App Audit, Acceptance Criteria

Version: 1.0.0
Updated: 2026-07-19
Owner: `spec/25-app-audit/03-plan-300-steps.md` step 5.
Scope: closed set of testable acceptance criteria (ACs) that gate the audit verdict in `01-verdict-honest.md`. Only the Self-Update axis (`AC-SU-*`) is populated in this initial revision. Additional axes (`AC-RB-*` for RBAC, `AC-DP-*` for deploy, etc.) land in later plan steps.

## Conventions

1. Every AC id is `AC-<axis>-<n>` with axis codes matching the ten audit axes in `00-overview.md`. Self-Update uses axis code `SU` (a sub-axis of Backend + Frontend, tracked separately because the self-update flow spans both).
2. Each AC states: normative behaviour (one sentence), evidence type (test file, log line, or file assertion), and the spec source it derives from.
3. ACs are testable. A criterion that cannot be evaluated by a Pest/Vitest/Playwright test, a log-line grep, or a file-presence check is not an AC and belongs in prose elsewhere.
4. Source-of-truth pointers reference `spec/21-app/17-self-update-endpoint.md` v1.5.0 unless stated otherwise.

## AC-SU: Self-Update (8 criteria)

Covers the wire contract, publish state machine, MUST-abort discipline, and observability of the self-update flow. Derived from `spec/21-app/17-self-update-endpoint.md` v1.5.0 (§Endpoints, §Publish state machine, §MUST-abort conditions, §Client update sequence, §Audit events) and reconciled with `spec/14-update/01-self-update-overview.md` via `spec/25-app-audit/A1-diff.md` v1.0.0.

| # | Criterion | Evidence | Source |
|---|-----------|----------|--------|
| AC-SU-1 | `GET /App/UpdateManifest` returns only rows whose `AppUpdates` state is `Published`; `Draft` and `Staged` rows are invisible to the endpoint, and `IsYanked=1` rows are excluded regardless of state. | Pest feature test on `SelfUpdate\ProbeController` covering all four state permutations (Draft, Staged, Published, Yanked). | S21 §Publish state machine, §Endpoints. |
| AC-SU-2 | `HEAD` and `GET` on `/App/UpdateAsset/{Version}/{Platform}` return the same `X-Sha256` value as the manifest row's asset `Sha256` column; the manifest value is the sole source of truth and the header is an observability echo. | Pest test asserting `HEAD` header == `GET` header == `AppUpdateAssets.Sha256` for a seeded published version. | S21 §Client update sequence, invariant "manifest `Sha256` as sole source of truth". |
| AC-SU-3 | `Platform` path segment is validated against the closed enum `{WindowsAmd64, LinuxAmd64, DarwinArm64}`; any other value returns envelope `ErrorCode=UnknownPlatform` with HTTP 404 and never touches storage. | Pest data-provider test iterating rejected strings (`windows`, `WINDOWS_AMD64`, `Win64`, empty). | S21 §Platform enum, `12-error-taxonomy.md`. |
| AC-SU-4 | Publishing a version is a single atomic transaction: `POST /Admin/AppUpdates` transitions the row from `Staged` to `Published` and writes the `UpdatePublished` audit event in the same DB transaction; a rollback discards both. | Pest test that forces a post-insert exception and asserts neither the manifest row nor the audit row exists. | S21 §Publish state machine invariants, §Audit precedence. |
| AC-SU-5 | For every MUST-abort row `A1..A10`, a conforming client leaves the installed binary untouched, deletes the temp file, and emits the listed audit event with `RequestId` propagated. | Playwright + fixture harness driving each abort trigger against a stub server; asserts on backup file presence, temp file absence, and audit log entry. | S21 §MUST-abort conditions, AC-SU-ABORT-001 (line 415). |
| AC-SU-6 | `POST /Admin/AppUpdates/{Version}/Yank` sets `IsYanked=1`, `YankedAt`, `YankedByUserId` atomically, emits `UpdateYanked`, and is irreversible in v1 (subsequent yank calls return `UpdateAlreadyYanked` and never clear the flag). | Pest test: yank, re-yank, assert error envelope + immutability of yank columns. | S21 §Yank flow. |
| AC-SU-7 | The rename-first-deploy step is a single filesystem `rename(2)` on the same volume; a copy fallback is a spec violation. Any rename failure after verification (row A10) emits `UpdateDeployFailed{Stage}` and restores the backup. | Frontend/CLI unit test asserting the deploy function calls `fs.rename` (never `copyFile`); integration test simulating `EXDEV` asserts abort + rollback. | S21 §Client update sequence invariant 4, `spec/14-update/03-rename-first-deploy.md`. |
| AC-SU-8 | Every self-update request and response propagates `RequestId`; the four audit events `UpdateVerified`, `UpdateVerificationFailed`, `UpdateDownloaded`, `UpdateDeployFailed` all carry the same `RequestId` for a single update attempt. | Log-scan test over a captured update run asserts one `RequestId` groups all four events. | S21 §Client update sequence, §Audit events, `13-audit-logging.md`. |

## Traceability

- Every AC-SU-* row cites S21 (line-locatable). The step-25 allowlist update in `03-plan-300-steps.md` adds the eight ids to `linter-scripts/ac-test-coverage.py`.
- Consistency check: `linter-scripts/ac-index-parity.py` (plan step 9) will confirm no orphan AC ids remain.

## Version history

| Version | Date | Change |
|---------|------|--------|
| 1.0.0 | 2026-07-19 | Recreate the file. Populate `AC-SU-1..8` from `spec/21-app/17-self-update-endpoint.md` v1.5.0 and `A1-diff.md` v1.0.0. Other axes (RBAC, deploy, DB, tests) remain pending under later plan steps. |
