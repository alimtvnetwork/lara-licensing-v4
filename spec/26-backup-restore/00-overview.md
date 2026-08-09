# Backup, Restore & Snapshot System - Overview

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`backup` · `restore` · `snapshot` · `super-admin` · `casbin` · `rbac` · `export` · `import` · `archive` · `disaster-recovery`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | ✅ |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

This document is the folder root for `spec/26-backup-restore/`. It defines the scope, actors, glossary, non-goals, and threat model summary for a system-wide Backup, Restore, and Snapshot capability that ships as part of the Admin panel and is gated to the Super Admin role. Readers finishing this file should be able to name the actors, the four flows (Export, Import, Snapshot, Restore), the storage container (archive), and the policy enforcement layer (Casbin) before opening any sibling file. Implementation content is deferred to Plan 14; this folder authors specification only.

---

## Document Inventory

| # | File | Purpose |
|---|------|---------|
| 00 | `00-overview.md` | Folder root: scope, actors, glossary, non-goals, threat-model summary (this file) |
| 01 | `01-actors-and-roles.md` | Super Admin bootstrap, first-user-wins invariant, role lifecycle |
| 02 | `02-casbin-integration.md` | Casbin model.conf, policy.csv shape, DB adapter, PEP placement, migration from `has_role()` |
| 03 | `03-permission-matrix.md` | Authoritative role x action matrix |
| 04 | `04-invariants.md` | INV-BR-* normative list |
| 05 | `05-scope-catalog.md` | What is backed up |
| 06 | `06-scope-exclusions.md` | What is not backed up |
| 07 | `07-manifest-schema.md` | Archive manifest JSON Schema |
| 08 | `08-archive-format.md` | On-disk container layout |
| 09 | `09-encryption-and-keys.md` | HKDF, key epochs, forward secrecy, re-seal |
| 10 | `10-endpoints-overview.md` | Endpoint inventory table |
| 11 | `11-endpoint-export.md` | Export contract |
| 12 | `12-endpoint-import.md` | Import contract |
| 13 | `13-endpoint-snapshot.md` | Snapshot lifecycle contract |
| 14 | `14-endpoint-restore.md` | Restore from snapshot contract |
| 15 | `15-jobs-and-progress.md` | Long-running job model |
| 16 | `16-error-taxonomy.md` | BR error codes folded into the closed set |
| 17 | `17-fe-routes.md` | Admin FE routes + guards |
| 18 | `18-fe-export-flow.md` | Export screen state chart |
| 19 | `19-fe-import-flow.md` | Import screen state chart |
| 20 | `20-fe-snapshots-flow.md` | Snapshots list + actions |
| 21 | `21-fe-roles-and-casbin-ui.md` | Role management UI |
| 22 | `22-observability.md` | RequestId / ErrorId / `lara-diag` binding |
| 23 | `23-audit-and-compliance.md` | Immutable audit trail |
| 24 | `24-testing-matrix.md` | Pest + Vitest + Playwright test IDs mapped to AC-BR-* |
| 25 | `25-migration-and-rollout.md` | Schema migrations, Super Admin seeding, feature flag |
| 26 | `26-diagrams.md` | Mermaid diagrams |
| 97 | `97-acceptance-criteria.md` | AC-BR-1..N with evidence type per criterion |
| 98 | `98-changelog.md` | Folder changelog |
| 99 | `99-consistency-report.md` | Structural health check |

Sibling files 01..26, 97..99 are declared in Plan 13; each is authored in its own step and version bump. Until authored, cross-references from other specs to any of the above are reserved via `<spec-placeholder>`.

---

## Specification

### Scope

This module specifies the wire contract and admin-panel behaviour that lets a Super Admin:

1. **Export** the full running system into a single, verifiable, encrypted archive (schema DDL, row data across all tenant-scoped shards, application config, closed-sets, feature catalog, license artifacts, secrets envelope, uploaded files, migration state).
2. **Import** an archive on a fresh or existing host with a mandatory pre-flight validation, a dry-run diff, and an atomic apply phase.
3. **Snapshot** a named point-in-time image (optionally incremental) with retention, pinning, and quota controls.
4. **Restore** from a snapshot, in either full or selective mode, with pre-flight safety rails.

All four flows are exposed through the Admin API under `/Api/V1/Admin/Backup/*` and `/Api/V1/Admin/Snapshot/*`, and are surfaced in the Admin panel under `/admin/backup`, `/admin/backup/import`, `/admin/snapshots`, and `/admin/roles`.

### Actors

| Actor | Description | Capability class |
|-------|-------------|------------------|
| Super Admin | The first registered user of a fresh instance auto-elevates to this role. Sole holder of `Backup.*`, `Snapshot.*`, `Role.Manage`, `System.Configure`. | Full |
| Admin | Any user granted the `Admin` role by a Super Admin via the role UI. Default capability is `Backup.Read` (list-only); other capabilities are explicit grants. | Delegated |
| Operator | Console-only actor used by CI, migration jobs, and cron. Never holds `Backup.Import` or `Snapshot.Restore` unless a maintenance flag is on. | Machine |
| Auditor | Read-only visibility over the audit log slice; no ability to trigger any Backup or Snapshot flow. | Read-only |
| User | Any non-admin authenticated principal. No visibility into this module at all (route guards deny, envelope returns `Forbidden`). | None |

### Glossary

| Term | Definition |
|------|------------|
| **Archive** | The on-disk container produced by Export or Snapshot. Encrypted, checksummed, streamable. Layout in `08-archive-format.md`. |
| **Manifest** | The JSON header of an Archive. Declares version, timestamp, appVersion, schemaHash, contentHash, encryption metadata, chunk index. Schema in `07-manifest-schema.md`. |
| **Snapshot** | A named Archive with retention metadata, optionally incremental against a parent. Lives in a managed store with quota. |
| **Export** | An on-demand Archive intended for out-of-system transport (download). Not retained by the server after handoff unless explicitly pinned. |
| **Import** | The three-phase state machine (`Uploaded -> Validated -> DryRun -> Applied` or `Failed`) that ingests an Archive on the target host. |
| **Restore** | The Snapshot-scoped variant of Import; source is an in-system Snapshot rather than an uploaded Archive. |
| **PEP** | Policy Enforcement Point. The single call site (`Gate::authorize` shim delegating to Casbin) that decides whether an actor may execute a capability. |
| **PDP** | Policy Decision Point. Casbin itself, backed by the DB adapter (`user_roles` + `casbin_rules`). |
| **First-user-wins** | Invariant that the first row inserted into `users` on a fresh install auto-inserts a matching `user_roles` row with `role = 'super_admin'`. |
| **Forward secrecy** | Property that a restored Archive is re-sealed with a new key epoch, so a leaked historical key cannot decrypt the running instance. |

### Non-goals

The following are explicitly out of scope for this module and belong elsewhere:

- **Per-row / point-in-time database log shipping** (WAL / binlog streaming). This is DR-tier and lives in the infrastructure layer, not the application.
- **Continuous replication or hot standby.** Snapshot is coarse-grained (per operator action), not a replica.
- **User-scoped exports** (a single user's data). GDPR user-data export is a separate flow specified in the audit module.
- **Automatic scheduled backups.** Scheduling belongs to the Jobs module; this spec authors the primitives that scheduling will call.
- **Cross-version schema migration on Import.** Import assumes source and target `appVersion` are compatible per the policy in `07-manifest-schema.md`; mismatch produces `BackupVersionMismatch`.
- **Casbin plugin runtime code.** This spec fixes the model and policy shape; the plugin is authored in Plan 14 (implementation).

### Threat model summary

| Threat | Mitigation | Owner file |
|--------|------------|------------|
| Malicious Import overwrites live data | Mandatory dry-run gate + operator confirmation + `Restore.Confirm` capability separate from `Restore.Trigger` | `12-endpoint-import.md`, `14-endpoint-restore.md` |
| Archive theft (at rest or in transit) | HKDF-derived per-Archive key sealed by KMS; ciphertext streamed with per-chunk AEAD tags | `09-encryption-and-keys.md` |
| Historical key leak decrypts new data | Every Import/Restore rotates to a new key epoch; old epoch retained only long enough to read the Archive | `09-encryption-and-keys.md` |
| Privilege escalation via role edit | `Role.Manage` is Super-Admin-only; Casbin PEP evaluated on every mutation; audit row is immutable | `02-casbin-integration.md`, `23-audit-and-compliance.md` |
| Silent partial Restore | Two-phase apply: (1) stage into shadow schema, (2) atomic swap; failure aborts to pre-Restore state | `14-endpoint-restore.md` |
| Log-side leakage of secrets in Archive | `DetailsRedactor` applied to all diagnostic surfaces; Archive manifest never logged in full | `22-observability.md` |
| Enumeration of Snapshot IDs by non-admin | Route guards + Casbin `Snapshot.Read`; 404 (not 403) response envelope to avoid oracles | `13-endpoint-snapshot.md`, `17-fe-routes.md` |
| RequestId / ErrorId collision hiding failures | UUIDv4 minted per request; per-Archive `contentHash` bound into audit row | `22-observability.md` |

### Invariants (summary; normative list in `04-invariants.md`)

- **INV-BR-A (atomicity):** Import and Restore either complete fully or leave the target host bit-identical to its pre-flow state.
- **INV-BR-B (idempotency):** Re-invoking Import with the same manifest `contentHash` on a target already at that state is a no-op that returns 200 with an `AlreadyApplied` marker.
- **INV-BR-C (forward secrecy):** No key epoch is reused across Restore operations.
- **INV-BR-D (RBAC):** Every mutating endpoint checks Casbin PEP; no bypass path exists.
- **INV-BR-E (audit):** Every flow writes exactly one immutable audit row keyed by `RequestId`, retained per the compliance schedule in `23-audit-and-compliance.md`.
- **INV-BR-F (quota):** Snapshot creation is denied when the storage quota would be exceeded; Export is not quota-gated but is size-capped per role.

### Bootstrap: first user becomes Super Admin

On a fresh install (`users` table is empty at request time), the registration endpoint inserts the user row and, in the same DB transaction, inserts a `user_roles` row with `role = 'super_admin'` and seeds the minimal Casbin `g` grouping row that maps the user's UUID to the `super_admin` subject. Any subsequent registration follows the normal `role = 'user'` default. The bootstrap is race-safe via a `SELECT ... FOR UPDATE` on a sentinel row in `system_bootstrap`. Full contract in `01-actors-and-roles.md`.

### Wire summary (informative; normative in per-endpoint files)

| Flow | Method | Path | Capability |
|------|--------|------|------------|
| Export create | POST | `/Api/V1/Admin/Backup/Export` | `Backup.Export` |
| Export status | GET | `/Api/V1/Admin/Backup/Export/{JobId}` | `Backup.Read` |
| Import upload | POST | `/Api/V1/Admin/Backup/Import` | `Backup.Import` |
| Import apply | POST | `/Api/V1/Admin/Backup/Import/{JobId}/Apply` | `Backup.Import` |
| Snapshot create | POST | `/Api/V1/Admin/Snapshot` | `Snapshot.Create` |
| Snapshot list | GET | `/Api/V1/Admin/Snapshot` | `Snapshot.Read` |
| Snapshot delete | DELETE | `/Api/V1/Admin/Snapshot/{Id}` | `Snapshot.Delete` |
| Snapshot restore | POST | `/Api/V1/Admin/Snapshot/{Id}/Restore` | `Snapshot.Restore` |

All requests and responses use the canonical `ApiEnvelope` shape from `spec/03-error-manage/`. All error surfaces route through `LaraException` and are logged via the `lara-diag` channel with `RequestId` + `ErrorId` propagation per `22-observability.md`.

---

## Cross-References

- [Actors and roles](./01-actors-and-roles.md)
- [Casbin integration](./02-casbin-integration.md)
- [Permission matrix](./03-permission-matrix.md)
- [Invariants](./04-invariants.md)
- [Endpoints overview](./10-endpoints-overview.md)
- [Error taxonomy](./16-error-taxonomy.md)
- [Acceptance criteria](./97-acceptance-criteria.md)

<spec-placeholder reason="Activate when 26-backup-restore siblings land.">
- [Scope catalog](./05-scope-catalog.md)
- [Manifest schema](./07-manifest-schema.md)
- [Archive format](./08-archive-format.md)
- [Encryption and keys](./09-encryption-and-keys.md)
- [Observability](./22-observability.md)
- [Audit and compliance](./23-audit-and-compliance.md)
</spec-placeholder>

External:

- [Error management module](../03-error-manage/00-overview.md)
- [App spec root](../21-app/00-overview.md)
- [Coding guidelines](../02-coding-guidelines/00-overview.md)

---

## Version history

| Version | Date | Change |
|---------|------|--------|
| 1.0.0 | 2026-07-20 | Initial folder root: scope, actors, glossary, non-goals, threat model, wire summary. Plan 13 step 1. |
