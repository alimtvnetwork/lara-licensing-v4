# Scope Exclusions

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`scope` · `exclusions` · `transient` · `queues` · `caches` · `logs` · `sessions` · `idempotency` · `closure`

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

Companion to [`05-scope-catalog.md`](./05-scope-catalog.md).
Enumerate every `public.*` table, storage bucket, filesystem path,
and in-memory artifact that Export and Snapshot archives
deliberately **DO NOT** include, each with a one-sentence
justification.

`INV-BR-SC-2` from `05-scope-catalog.md` requires closure: every
`public.*` table is either in one of `SC-A..F` or listed here.
The step 30 consistency report diffs `pg_tables` against
`(SC-A..F union this file)`; a non-empty diff fails the report.

Categories below correspond to reasons for exclusion. Adding an
exclusion requires (a) a row in the appropriate category table,
(b) a one-sentence justification, and (c) confirmation that no
downstream feature requires the artifact to survive Restore.

---

## `EX-A` · Transient auth and session state

Restoring these would resurrect revoked sessions, bypass password
rotation, or leak stale bearer tokens. Users re-authenticate after
Restore by design.

| Table                                | Justification                                                                      |
|--------------------------------------|------------------------------------------------------------------------------------|
| `root.auth_sessions`                 | Session tokens are ephemeral; forward secrecy requires post-Restore re-login.      |
| `shard.auth_sessions`                | Same as `root.auth_sessions`, per-shard scope.                                     |
| `root.personal_access_tokens`        | Sanctum tokens are per-device; Restore invalidates them by policy.                 |
| `root.password_reset_tokens`         | One-shot, time-bound; restoring resurrects expired reset links.                    |
| `root.impersonation_index`           | Impersonation grants are point-in-time and must be re-authorised after Restore.    |

---

## `EX-B` · Idempotency and dedupe ledgers

These tables are correctness aids for request replay, not source of
truth. Restoring stale rows would either block legitimate retries
(collision on old key) or bypass fresh idempotency guards.

| Table                                | Justification                                                                      |
|--------------------------------------|------------------------------------------------------------------------------------|
| `root.idempotency_records`           | Request-scoped dedupe; keys expire per `spec/idempotency/` policy.                 |
| `shard.quota_requests`               | In-flight quota reservations; not durable state, replayed from `shard.licenses`.   |

---

## `EX-C` · Ephemeral queues and jobs

Queue state is transient by contract; restoring in-flight jobs
would double-execute side effects (emails, webhooks, license
mutations).

| Table                                | Justification                                                                      |
|--------------------------------------|------------------------------------------------------------------------------------|
| `public.jobs`                        | Laravel queue; jobs are re-enqueued from source state after Restore.               |
| `public.job_batches`                 | Batch progress is per-run; reruns produce new batches.                             |
| `public.failed_jobs`                 | Failure log for diagnostics only; not required for correctness.                    |

---

## `EX-D` · Per-node caches and locks

Cache and lock tables are per-node and rebuilt on demand.
Restoring stale entries risks serving stale data or holding
non-existent locks.

| Table                                | Justification                                                                      |
|--------------------------------------|------------------------------------------------------------------------------------|
| `public.cache`                       | Rebuilt on read; TTL-scoped.                                                       |
| `public.cache_locks`                 | Node-scoped mutex table; invalid across hosts.                                     |

---

## `EX-E` · Diagnostic and audit logs

Logs are append-only, high-volume, and shipped to external sinks.
Including them would balloon archive size and duplicate what the
log pipeline already retains.

| Table                                | Justification                                                                      |
|--------------------------------------|------------------------------------------------------------------------------------|
| `public.telescope_entries` (if present) | Debug telemetry only; shipped to Telescope UI, not durable business state.      |
| `public.lara_diag_events` (if present)  | `lara-diag` channel mirror; retained via log pipeline per observability spec.   |

Note: the immutable audit trail (`public.audit_log` and shard
equivalent) is **in scope** under `SC-F` per
[`23-audit-and-compliance.md`](<spec-placeholder file="23-audit-and-compliance.md" />).
Diagnostic logs (this row) and audit rows (in scope) are distinct.

---

## `EX-F` · Object-storage buckets outside file-object scope

`SC-H` covers only object-storage entries referenced by domain
tables via a `file_id` column. Everything else in object storage
is out of scope.

| Bucket / Path                        | Justification                                                                      |
|--------------------------------------|------------------------------------------------------------------------------------|
| `tmp/`                               | Scratch space; wiped on Restore.                                                   |
| `logs/`                              | Log archives; retained by log pipeline.                                            |
| `backups/` and `snapshots/`          | Archive storage itself; excluded to prevent recursive backups.                     |
| Any bucket not referenced by a `file_id` column | Not addressable via schema; would break `SC-H` content-address invariant.|

---

## `EX-G` · Filesystem paths on the app node

The app runs on a serverless Worker (see `<useful-context>` server
runtime notes). These paths are per-instance and never included:

| Path                                 | Justification                                                                      |
|--------------------------------------|------------------------------------------------------------------------------------|
| `/tmp/*`                             | Ephemeral scratch; per-invocation lifetime.                                        |
| `node_modules/`, `vendor/`           | Build artefacts, not state; reproduced from lockfiles.                             |
| `.env` files on disk                 | Secrets belong in the sealed envelope (`SC-G`), never as plaintext files.          |

---

## `EX-H` · In-memory artifacts

Runtime state that never touches durable storage.

| Artifact                             | Justification                                                                      |
|--------------------------------------|------------------------------------------------------------------------------------|
| Casbin enforcer instance             | Rebuilt from `casbin_rules` (in `SC-E`) on boot.                                   |
| HKDF sub-keys                        | Derived on demand from the current epoch key; never persisted.                     |
| Request-scoped `RequestId` context   | Per-request only; audit rows preserve the ID that mattered.                        |

---

## Closure Rule (`INV-BR-EX-1..3`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next
edit of that file.

| ID              | Statement                                                                                                              |
|-----------------|------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-EX-1`   | `pg_tables` union filtered to `public.*` equals the set covered by `SC-A..F` union the tables listed in `EX-A..E`.     |
| `INV-BR-EX-2`   | Adding a table via migration requires updating either `05-scope-catalog.md` (in `SC-*`) or this file in the same PR.   |
| `INV-BR-EX-3`   | Every exclusion row carries a one-sentence justification; empty or placeholder justifications fail the step 30 report. |

---

## Cross-References

- Parent: [`05-scope-catalog.md`](./05-scope-catalog.md) `INV-BR-SC-2` (closure).
- Consumed by: `<spec-placeholder file="07-manifest-schema.md" />`
  (`manifest.excluded[]` slot binds to this list),
  `<spec-placeholder file="24-testing-matrix.md" />`
  (closure test), `99-consistency-report.md` (step 30 diff).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit
  promotes `INV-BR-EX-1..3`.
