# Backup, Restore & Snapshot Spec Authoring (Execution)

Slug: backup-restore-spec-authoring
Steps: 30
Status: completed
Created: 2026-07-20

## Context

Execute the 30-file spec authoring cycle for `spec/26-backup-restore/`
outlined in `.lovable/plans/pending/12-backup-restore-snapshot.md`. This
plan writes the specification only, no runtime code. Each step lands
exactly one authoritative markdown artifact in `spec/26-backup-restore/`
and bumps the version. Casbin (RBAC + ABAC) is introduced as the policy
enforcement layer; first registered user auto-elevates to Super Admin;
export/import/snapshot flows are Super Admin gated and observable via
`RequestId`/`ErrorId` and the `lara-diag` channel.

Captured inputs:
- Command: `.lovable/spec/commands/08-backup-restore-spec-30-steps.md`
- Parent outline: `.lovable/plans/pending/12-backup-restore-snapshot.md`

Appended prior pending items (see final section).

## Steps

1. Create `spec/26-backup-restore/` and write `00-overview.md` (purpose, non-goals, glossary, actors, threat-model summary).
2. Write `01-actors-and-roles.md` (Super Admin bootstrap, first-user-wins invariant, role lifecycle, deputy rules).
3. Write `02-casbin-integration.md` (model.conf, policy.csv shape, DB adapter, PEP placement, migration from `has_role()`).
4. Write `03-permission-matrix.md` (authoritative role x action matrix for Backup.*, Snapshot.*, Role.Manage, System.Configure).
5. Write `04-invariants.md` (INV-BR-* normative list: atomicity, idempotency, forward secrecy, RBAC, audit, quota).
6. Write `05-scope-catalog.md` (tables, config, closed-sets, feature catalog, license artifacts, secrets envelope, files, migration state).
7. Write `06-scope-exclusions.md` (transient logs, ephemeral queues, per-node caches; justification per exclusion).
8. Write `07-manifest-schema.md` (JSON Schema: version, createdAt, appVersion, schemaHash, contentHash, encryption metadata, chunk index).
9. Write `08-archive-format.md` (container layout, tar+zstd choice, chunking, Merkle checksum tree, streaming read/write contract).
10. Write `09-encryption-and-keys.md` (HKDF, key epochs, forward secrecy on restore, re-seal, secrets envelope, rotation interaction).
11. Write `10-endpoints-overview.md` (endpoint inventory table: path, method, auth, permission, idempotency key).
12. Write `11-endpoint-export.md` (`POST /Api/V1/Admin/Backup/Export` full contract: request, envelope, job creation, progress channel).
13. Write `12-endpoint-import.md` (upload -> validate -> dry-run -> apply state machine; conflict resolution rules).
14. Write `13-endpoint-snapshot.md` (`POST/GET/DELETE /Api/V1/Admin/Snapshot[/{id}]`; retention, pinning, quota).
15. Write `14-endpoint-restore.md` (`POST /Api/V1/Admin/Snapshot/{id}/Restore` pre-flight, mode full|selective, safety rails).
16. Write `15-jobs-and-progress.md` (long-running job model, SSE + polling fallback, cancel semantics, resume rules).
17. Write `16-error-taxonomy.md` (BR-specific codes: BackupCorrupt, BackupVersionMismatch, RestoreConflict, SnapshotQuotaExceeded, ...) folded into 03-error-manage closed set.
18. Write `17-fe-routes.md` (`/admin/backup`, `/admin/backup/import`, `/admin/snapshots`, `/admin/roles`; guards & Casbin checks).
19. Write `18-fe-export-flow.md` (idle -> running -> download-ready -> failed state chart; copy dictionary; empty/loading/error states).
20. Write `19-fe-import-flow.md` (upload -> preflight report -> confirm-diff modal -> apply -> post-restore banner; conflict UX).
21. Write `20-fe-snapshots-flow.md` (list, create-with-note, pin, restore, delete; retention badges; empty/permission-denied states).
22. Write `21-fe-roles-and-casbin-ui.md` (Super Admin UI: create roles, assign policies, effective-permissions preview per user).
23. Write `22-observability.md` (RequestId propagation, ErrorId, `lara-diag` entries, audit-log rows, metrics counters).
24. Write `23-audit-and-compliance.md` (immutable audit trail, GDPR notes, export contains its own audit slice, retention).
25. Write `24-testing-matrix.md` (Pest + Vitest + Playwright test IDs mapped to AC-BR-*; fixtures & fake keys).
26. Write `25-migration-and-rollout.md` (schema migrations, seeding first Super Admin, backfill for existing tenants, feature flag).
27. Write `26-diagrams.md` (mermaid: export sequence, import state machine, snapshot restore sequence, Casbin PEP flow).
28. Write `97-acceptance-criteria.md` (AC-BR-1..N with evidence type per criterion; cite anchors in prior files).
29. Write `98-changelog.md` (initial 1.0.0 entry summarising all 30 files, cross-reference commit versions).
30. Write `99-consistency-report.md` (forbidden-terms scan, closed-set parity, permission-matrix vs policy.csv parity, cross-file link check) and MOVE plan 12 + this plan file to `.lovable/plans/completed/` with `Status: completed`.

## Verification

- After each step: file exists at declared path, front-matter version bumped in `spec/26-backup-restore/98-changelog.md` at step 29.
- Cross-file link check: `rg -n '\]\(\.\./' spec/26-backup-restore/` resolves.
- Closed-set parity: BR error codes present in both `spec/03-error-manage/` catalog and `backend/config/lara.php` list at step 17.
- Permission matrix vs Casbin policy.csv: consistency report (step 30) diff is empty.
- Plan lifecycle: after step 30, both `12-backup-restore-snapshot.md` and `13-backup-restore-spec-authoring.md` live under `.lovable/plans/completed/` with `Status: completed`; no duplicates in `pending/`.

## Appended from prior pending tasks

- Plan 05 (`05-rbac-quota-tier-environment.md`): RBAC/Quota enforcement remains low-confidence per audit-v2; will be revisited after Casbin lands via step 3 above. No merge into this plan.
- Plan 06 (`06-laravel-be-fe-and-publish.md`): Laravel publish rehearsal (Band C confidence) still pending; unaffected by this plan.
- Plan 07 (`07-ui-spec-conformance-and-code-finetune.md`): UI spec conformance sweep still pending; unaffected.
- Plan 09 (`09-fluid-ui-and-cpanel-release.md`): cPanel release automation still pending (deploy rehearsal report gap F1 open); unaffected.
- Plan 12 (`12-backup-restore-snapshot.md`): outline plan; superseded on completion of step 30 of this plan.
