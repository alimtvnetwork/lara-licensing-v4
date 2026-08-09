# Migration and Rollout

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`migration` · `rollout` · `feature-flag` · `staged-enablement` · `backfill` · `rollback` · `kill-switch` · `readiness-gate`

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

Pin the migration order, feature-flag surface, staged rollout
gates, backfill discipline, and rollback rules for the Backup /
Restore / Snapshot module. Files 04..24 declared schema, jobs,
endpoints, FE flows, observability, audit, and the testing
matrix, but no file has governed HOW the module ships into a
live environment without disturbing existing tenants, HOW audit
hash chains are seeded on day one, or HOW to yank the feature
mid-rollout without corrupting archives already produced.
Without this, Plan 14 (implementation) has no shipping order and
the first Export against production risks orphan `backup_jobs`
rows.

---

## Migration Order (strict)

Migrations MUST be applied in this order in a single deploy.
Each migration is idempotent and reversible except where noted.

| # | Migration ID                                     | Effect                                                                             | Reversible |
|---|--------------------------------------------------|------------------------------------------------------------------------------------|------------|
| 1 | `2026_07_21_000001_create_br_enums`              | Postgres enums for `job_kind`, `job_state`, `conflict_policy`, `actor_kind`.       | Yes.       |
| 2 | `2026_07_21_000002_create_backup_jobs`           | `backup_jobs` table + grants + RLS per `15-jobs-and-progress.md`.                  | Yes.       |
| 3 | `2026_07_21_000003_create_backup_idempotency`    | `backup_idempotency_keys` table per `16-idempotency-and-locks.md`.                 | Yes.       |
| 4 | `2026_07_21_000004_create_backup_snapshots`      | `backup_snapshots` table + pin_count column per `13-endpoint-snapshot.md`.         | Yes.       |
| 5 | `2026_07_21_000005_create_backup_audit_events`   | Append-only table + `BEFORE INSERT` hash trigger per `23-audit-and-compliance.md`. | No (audit).|
| 6 | `2026_07_21_000006_create_audit_pseudonymiser`   | Role + `fn_pseudonymise_actor` SECURITY DEFINER procedure.                         | Yes.       |
| 7 | `2026_07_21_000007_create_br_kek_epochs`         | KEK epoch registry per `09-encryption-and-keys.md`.                                | No (crypto).|
| 8 | `2026_07_21_000008_seed_br_casbin_policies`      | Seed 14-capability matrix per `03-permission-matrix.md`.                           | Yes.       |
| 9 | `2026_07_21_000009_create_br_advisory_lock_keys` | Numeric key registry for the six-rank global order per `16-idempotency-and-locks.md`. | Yes.    |

Rules:

1. Migrations 5 and 7 are marked non-reversible because rolling
   them back would delete an immutable audit chain or a live
   KEK epoch; use a forward-only compensating migration instead.
2. Migration 8 is idempotent via `ON CONFLICT DO NOTHING` on
   `(role, capability)`.
3. The CI gate `lara:ci:migration-idempotency` (already wired at
   v0.402.0) MUST pass for all nine migrations.

---

## Feature Flags (closed set)

Owned by `feature_flags` table (existing platform primitive).
Values are `off | shadow | on`. Default `off` in every env.

| Flag ID                       | Guards                                                            | Notes                                                     |
|-------------------------------|-------------------------------------------------------------------|-----------------------------------------------------------|
| `br.export.enabled`           | `POST /api/backup/exports`, FE `RA-BR-2`.                          | Independent of Import so Export can ship first.           |
| `br.import.enabled`           | `POST /api/backup/imports`, `POST /api/backup/restores`, `RA-BR-3`.| Requires `br.export.enabled=on` in the source tenant.     |
| `br.snapshots.enabled`        | All `/api/backup/snapshots/*`, `RA-BR-4`/`RA-BR-5`.                | Depends on `br.export.enabled=on`.                        |
| `br.roles-ui.enabled`         | FE `RA-BR-6`.                                                      | BE Casbin policy is always on once seeded.                |
| `br.audit-chain-verify.enabled` | Daily chain verifier job.                                        | Should ship `on` from day one; `off` is a red flag.       |
| `br.audit-pseudonymise.enabled` | `fn_pseudonymise_actor` invocation gate.                         | Gated by legal signoff; default `off`.                    |
| `br.kill-switch`              | Global BR module halt.                                             | When `on`, all BR endpoints return `503 ModuleDisabled` with `Retry-After`. |

Rules:

1. Flags are checked in the controller layer BEFORE any lock
   acquisition, so a disabled feature never mutates state.
2. `shadow` mode routes writes to a dry-run branch that emits
   observability rows tagged `mode=shadow` and returns
   `202 ShadowAccepted` without persisting; used to warm caches
   and validate schemas in production without user impact.
3. Flipping any flag emits `backup.rollout.flag_changed` (a new
   observability code owned by this file; the BR audit catalogue
   grows to 33 codes with this addition).

Extension to `22-observability.md`:

| Code                              | Actor  | Trigger                                              |
|-----------------------------------|--------|------------------------------------------------------|
| `backup.rollout.flag_changed`     | user   | Operator flipped a `br.*` feature flag.              |

---

## Staged Enablement Gates

Rollout proceeds through five stages. Each stage has an entry
gate that MUST be green before advancing. Regression at any
stage triggers `br.kill-switch=on` and a rollback plan below.

| Stage | Name                     | Entry gate                                                                                                     | Traffic scope           |
|-------|--------------------------|----------------------------------------------------------------------------------------------------------------|-------------------------|
| S0    | Migrations applied       | All 9 migrations green in prod; `br:coverage-matrix` gate green in CI.                                          | None (schema only).     |
| S1    | Shadow                   | S0 + `br:e2e-full` nightly green for 3 consecutive nights.                                                      | 100% of tenants; writes dropped. |
| S2    | Internal tenants         | S1 + zero `backup.*.corrupt` audit rows for 7 days + chaos suite green.                                         | Employee-owned tenants only. |
| S3    | Canary (5%)              | S2 + no P1 incidents for 14 days + perf-smoke within budget.                                                    | 5% of external tenants, sticky by tenant id hash. |
| S4    | Ramp (25% -> 50% -> 100%) | S3 + 7 days per step with `backup.export.completed:backup.export.failed` ratio > 100:1 and no new `chain_break_detected`. | 25 -> 50 -> 100.        |

The gate values are the closed set; adjusting them requires a
version bump of this file.

---

## Backfill Discipline

BR has three backfill needs on day one; each is a separate
worker job kind (added to `15-jobs-and-progress.md`'s closed
enum in this file):

| Job kind                     | Effect                                                                                        | Idempotent | Order |
|------------------------------|-----------------------------------------------------------------------------------------------|------------|-------|
| `br.backfill.audit_genesis`  | For each existing shard with no `backup_audit_events` row, insert a genesis row `prevHash=x'00'*32`, `code='backup.audit.chain_genesis'`. | Yes. | 1. |
| `br.backfill.kek_epoch_zero` | Register the current KEK as epoch 0 in `br_kek_epochs`.                                       | Yes. | 2. |
| `br.backfill.snapshot_pin_counts` | Reconcile `pin_count` column from any pre-existing pin references (should be zero at S0). | Yes. | 3. |

Rules:

1. Backfill jobs run under `br.global` rank-1 lock, serialised
   with any concurrent Export/Restore (there should be none at
   S0 by definition).
2. Each backfill job MUST emit start + end audit rows so the
   chain begins with verifiable provenance.
3. Adding a `backup.audit.chain_genesis` code extends the BR
   audit catalogue to 34 codes; that code is owned by this file.

Extension to `22-observability.md` and `23-audit-and-compliance.md`:

| Code                              | Actor  | Trigger                                              |
|-----------------------------------|--------|------------------------------------------------------|
| `backup.audit.chain_genesis`      | worker | Backfill inserted the first row for a shard.         |

---

## Rollback Plan

Regression scenarios and their responses. Never delete audit
rows; never drop KEK epochs.

| Scenario                                     | Response                                                                                                                     |
|----------------------------------------------|------------------------------------------------------------------------------------------------------------------------------|
| S1 shadow mode surfaces schema mismatch      | Flip `br.export.enabled=off`; fix schema; re-run `br:contract` + `br:integration`.                                          |
| S2 chaos suite regression                    | Flip `br.kill-switch=on`; investigate; forward-only fix; retry chaos.                                                        |
| S3 P1 (data corruption suspected)            | Flip `br.kill-switch=on`; freeze `fn_pseudonymise_actor`; run chain verifier over all shards; publish RCA before re-enable.  |
| S4 ratio regression (fail rate rising)       | Roll traffic back one step (100 -> 50 -> 25); do NOT skip a step; require 24 h of green ratio before advancing again.        |
| Kill-switch tripped                          | All BR endpoints return `503 ModuleDisabled` with `Retry-After: 3600`; existing archives remain valid; in-flight jobs finish or cancel per `15-jobs-and-progress.md`. |

Non-reversible migrations (5, 7) MUST NOT be rolled back; use
forward-only compensating migrations that add rather than drop.

---

## Readiness Checklist (per stage)

Advance to the next stage only when all boxes are ticked. The
`br:readiness` CI gate parses this checklist and blocks stage
advance labels on the release PR.

- S1 -> S2: `[ ]` shadow writes match schema, `[ ]` `br:e2e-full` green 3 nights, `[ ]` chain verifier emits no chain-break.
- S2 -> S3: `[ ]` 7 days corruption-free, `[ ]` chaos green, `[ ]` runbooks (`26-runbooks.md`) published.
- S3 -> S4-25: `[ ]` no P1 for 14 days, `[ ]` perf budgets met, `[ ]` on-call rotation trained.
- S4 steps: `[ ]` 7 days per step, `[ ]` ratio guardrail met, `[ ]` no new `chain_break_detected`.

---

## Invariants

| ID              | Rule |
|-----------------|------|
| INV-BR-MG-1     | Migrations MUST be applied in the strict order above in a single deploy; a partial apply MUST abort deploy. |
| INV-BR-MG-2     | Migrations 5 and 7 are forward-only; rollback is via compensating migrations, never `DROP`. |
| INV-BR-MG-3     | Feature flags MUST be checked in the controller BEFORE lock acquisition; a disabled feature MUST NOT mutate state. |
| INV-BR-MG-4     | `shadow` mode MUST emit observability rows tagged `mode=shadow` and MUST NOT persist to canonical tables. |
| INV-BR-MG-5     | Stage advance requires the readiness checklist to be green; the `br:readiness` gate blocks the release PR label otherwise. |
| INV-BR-MG-6     | Regression at any stage MUST trip `br.kill-switch=on` before investigation; no live debugging on hot traffic. |
| INV-BR-MG-7     | Backfill jobs run under `br.global` rank-1 lock and MUST emit start + end audit rows. |
| INV-BR-MG-8     | The BR audit catalogue grows from 32 to 34 codes with `backup.rollout.flag_changed` and `backup.audit.chain_genesis` owned by this file. |
| INV-BR-MG-9     | Kill-switch responses MUST include `Retry-After` and MUST NOT expose module internals in the envelope. |
| INV-BR-MG-10    | Ramp step regressions MUST roll back exactly one step, never skip; and require 24 h green before re-advance. |

---

## Cross-References

- [`15-jobs-and-progress.md`](./15-jobs-and-progress.md): job-kind enum extension for the three backfill jobs.
- [`16-idempotency-and-locks.md`](./16-idempotency-and-locks.md): `br.global` rank-1 lock used by backfills and kill-switch.
- [`22-observability.md`](./22-observability.md): audit catalogue extended by two codes owned here.
- [`23-audit-and-compliance.md`](./23-audit-and-compliance.md): chain genesis row and pseudonymiser role migration.
- [`24-testing-matrix.md`](./24-testing-matrix.md): CI gates (`br:coverage-matrix`, `br:e2e-full`, chaos) referenced by stage entry gates.
- [`spec/25-app-audit/03-plan-300-steps.md`](../25-app-audit/03-plan-300-steps.md): staged enablement plugs into the Band-A+ readiness track.
