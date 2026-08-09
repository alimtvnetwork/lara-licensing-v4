# Plan 12: Backup, Restore & Snapshot System (Spec Authoring)

**Status:** completed
**Version:** 0.1.0
**Created:** 2026-07-20 (v0.457.0)
**Scope:** Author the specification only. Implementation is a separate,
later plan the user will authorize after the spec lands.

---

## Goal

Produce a complete, audit-grade specification for a system-wide **Backup,
Restore & Snapshot** capability, gated to the Super Admin role, including:

- Full-system export (schema + row data + config + secrets envelope + files).
- Full-system import (restore) with pre-flight validation, dry-run, and
  atomic apply.
- Named snapshots (point-in-time, retention policy, diff/incremental option).
- First-registered-user auto-becomes Super Admin bootstrap rule.
- Super Admin can create other roles via the **Casbin** plugin (RBAC + ABAC),
  with each role's view/edit/execute matrix authoritatively declared in the
  spec.
- Admin panel UI wire-contract (BE endpoints + FE screens) for
  export/import/snapshot flows, including long-running job progress,
  cancel/resume, and observability (RequestId/ErrorId, `lara-diag` logs).

## Casbin confirmation

Confirmed: this project does **not** ship a Casbin plugin today. Current
RBAC lives in `backend/app` + `user_roles` table + `has_role()` SECURITY
DEFINER pattern from the project's user-roles memory. Introducing Casbin
is an in-scope architectural decision for this spec; the plan will
document the migration path from the current guard to a Casbin policy
enforcement point (PEP) with the DB adapter.

## Spec location

New folder: `spec/26-backup-restore/` (next free slot after 25-app-audit).
Cross-linked from `spec/21-app/00-overview.md` and consolidated guidelines.

## Spec size

**30 steps / 30 spec files** as the user requested. Each step produces or
amends one authoritative artifact. No implementation code is written in
this plan — only markdown, mermaid, JSON schemas, and OpenAPI fragments.

## 30-step spec-authoring outline

### Group A: Foundations (steps 1-5)
1. `00-overview.md` - purpose, non-goals, glossary, actors, threat model summary.
2. `01-actors-and-roles.md` - Super Admin bootstrap rule, role hierarchy, first-user-wins invariant, role lifecycle.
3. `02-casbin-integration.md` - model.conf, policy.csv shape, DB adapter, PEP placement, migration from `has_role()`.
4. `03-permission-matrix.md` - authoritative role x action matrix (Backup.Read, Backup.Export, Backup.Import, Snapshot.Create, Snapshot.Restore, Role.Manage, etc.).
5. `04-invariants.md` - normative INV-BR-* list (atomicity, idempotency, forward secrecy, RBAC, audit).

### Group B: Data model (steps 6-10)
6. `05-scope-catalog.md` - what is backed up: tables, config, closed-sets, feature catalog, license artifacts, secrets envelope, uploaded files, migration state.
7. `06-scope-exclusions.md` - what is NOT backed up (transient logs, ephemeral queues, per-node caches) and why.
8. `07-manifest-schema.md` - JSON schema of the archive manifest (version, createdAt, appVersion, schemaHash, contentHash, encryption metadata, chunk index).
9. `08-archive-format.md` - on-disk container layout (zip vs tar+zstd), chunking, checksum tree, streaming read/write contract.
10. `09-encryption-and-keys.md` - KDF (HKDF), key epochs, forward secrecy on restore, re-seal rules, secrets envelope, key rotation interaction.

### Group C: Backend contract (steps 11-17)
11. `10-endpoints-overview.md` - endpoint inventory table (path, method, auth, permission, idempotency).
12. `11-endpoint-export.md` - `POST /Api/V1/Admin/Backup/Export` full contract (request, envelope, job creation, progress channel).
13. `12-endpoint-import.md` - `POST /Api/V1/Admin/Backup/Import` upload + validate + dry-run + apply state machine.
14. `13-endpoint-snapshot.md` - `POST /Api/V1/Admin/Snapshot`, `GET .../{id}`, `DELETE .../{id}`, retention & pinning.
15. `14-endpoint-restore.md` - `POST /Api/V1/Admin/Snapshot/{id}/Restore`, pre-flight, mode (full | selective).
16. `15-jobs-and-progress.md` - long-running job model, SSE/polling contract, cancel semantics, resume rules.
17. `16-error-taxonomy.md` - BR-specific error codes (BackupCorrupt, BackupVersionMismatch, RestoreConflict, SnapshotQuotaExceeded, ...) folded into the existing 03-error-manage closed set.

### Group D: Frontend contract (steps 18-22)
18. `17-fe-routes.md` - `/admin/backup`, `/admin/backup/import`, `/admin/snapshots`, `/admin/roles`; route guards, Casbin permission checks.
19. `18-fe-export-flow.md` - screen states (idle -> running -> download-ready -> failed), copy dictionary keys, empty/loading/error states.
20. `19-fe-import-flow.md` - upload -> preflight report -> confirm-diff modal -> apply -> post-restore banner; conflict UX.
21. `20-fe-snapshots-flow.md` - list, create-with-note, pin, restore, delete; retention badges.
22. `21-fe-roles-and-casbin-ui.md` - Super Admin UI for creating roles, assigning policies, previewing effective permissions per user.

### Group E: Cross-cutting & closure (steps 23-30)
23. `22-observability.md` - RequestId propagation, ErrorId, `lara-diag` daily channel entries, audit-log rows.
24. `23-audit-and-compliance.md` - who did what/when, immutable audit trail, export contains its own audit slice.
25. `24-testing-matrix.md` - Pest + Vitest + Playwright test IDs mapped to AC-BR-*.
26. `25-migration-and-rollout.md` - schema migrations, seeding first Super Admin, backfill for existing tenants.
27. `26-diagrams.md` - mermaid: export sequence, import state machine, snapshot restore sequence, Casbin PEP flow.
28. `97-acceptance-criteria.md` - AC-BR-1..N with evidence type per criterion.
29. `98-changelog.md` - initial 1.0.0 entry.
30. `99-consistency-report.md` - cross-file consistency scan, forbidden-terms scan, closed-set parity, permission-matrix parity vs Casbin policy.csv.

## Deliverable of this plan (not the spec itself)

This plan file. The 30 spec files land in a follow-up plan cycle once the
user confirms this outline. No spec content is written yet.

## Handoff

- After user confirmation: create `spec/26-backup-restore/` and execute
  steps 1-30 in order, one step per version bump.
- Implementation plan (backend + frontend + Casbin adapter + migration)
  will be Plan 13, authorized separately.
