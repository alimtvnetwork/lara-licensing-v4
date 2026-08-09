# Plan 14: Backup / Restore / Snapshot Implementation

**Version:** 1.0.0
**Status:** Pending
**Precondition:** Plan 13 complete (spec files 00..27 under `spec/26-backup-restore/`).
**Handover contract:** `spec/26-backup-restore/27-open-questions.md` sign-off criteria.

---

## Purpose

Ship the BR module in the strict order pinned by
`spec/26-backup-restore/25-migration-and-rollout.md` (S0
Migrations, S1 Shadow, S2 Internal, S3 Canary 5%, S4 Ramp
25/50/100). Each step lands one narrow slice with tests
matching the tier(s) declared in
`spec/26-backup-restore/24-testing-matrix.md` and never opens
a code path whose owning spec still has AI Confidence `Draft`
(`INV-BR-OQ-6`).

---

## Non-Negotiable Rules

1. No spec drift. If code disagrees with a spec file, the spec
   is amended in the same PR with a version bump, not
   retroactively.
2. Every step MUST close its coverage-matrix row in
   `24-testing-matrix.md` before the next step begins; the
   `br:coverage-matrix` gate enforces this.
3. Every migration MUST be idempotent and pass
   `lara:ci:migration-idempotency` (wired at v0.402.0).
   Migrations 5 and 7 are forward-only per `INV-BR-MG-2`.
4. Feature flags default `off` per `25-migration-and-rollout.md`;
   flipping any flag requires a release PR with the readiness
   checklist ticked and emits `backup.rollout.flag_changed`.
5. No file exceeds 15-line function bodies. No magic literals
   in domain code. PascalCase for DB columns and JSON keys.
6. No em/en dashes in code, comments, commits, or docs (project
   memory rule).

---

## Step Ladder (30 steps, one per release)

Each step is one release (`v0.x+1.0`). CI must be green before
the next step begins.

### S0 Migrations (Stage entry gate: 9 migrations green + `br:coverage-matrix` green)

1. **Migrations 1-3.** `br_enums`, `backup_jobs`, `backup_idempotency_keys`. Grants + RLS per specs 15 and 16. Contract + integration tests for job-row shape and idempotency-key lookup. Unblocks: 2.
2. **Migrations 4-6.** `backup_snapshots`, `backup_audit_events` (with `BEFORE INSERT` trigger), `audit_pseudonymiser` role. Property test for hash chain re-hash on advisory-locked concurrent inserts. Unblocks: 3.
3. **Migrations 7-9.** `br_kek_epochs`, seed Casbin BR policies, advisory-lock key registry. Integration test for Casbin PDP < 5 ms on 14-capability matrix. Unblocks: 4.
4. **Feature-flag surface.** Wire the 7 flags in `feature_flags` with `off|shadow|on` values and controller-layer check hook per `INV-BR-MG-3`. Contract test asserts a disabled flag returns before any lock acquisition. Unblocks: 5.
5. **Backfill jobs.** `br.backfill.audit_genesis`, `br.backfill.kek_epoch_zero`, `br.backfill.snapshot_pin_counts`. Run under `br.global` rank-1 lock. Emit `backup.audit.chain_genesis`. Integration + chaos (`CH-LOCK-STARVE`). Unblocks: 6.
6. **`br-ops` command surface (skeleton).** Minimal commands: `jobs:show`, `jobs:list`, `locks:holders`, `audit:verify`, `kek:epochs`. No mutating commands yet. Unit + integration. Unblocks: 7.

### S1 Shadow (Stage entry gate: S0 + `br:e2e-full` nightly green 3 nights + chain verifier zero-break)

7. **Manifest schema + archive format.** Implement `ManifestBuilder`, `ArchiveWriter`, `ArchiveReader`. Ed25519 signature per OQ-01 assumption. Property tests: round-trip, hash stability, `INV-BR-MF-*`, `INV-BR-AF-*`. Unblocks: 8.
8. **Encryption + KEK epochs.** AES-256-GCM DEKs wrapped by epoch KEK. Chaos `CH-KEK-ROTATE`. Property tests for AEAD nonces. Unblocks: 9.
9. **Export endpoint (shadow-only).** `POST /api/backup/exports` behind `br.export.enabled=shadow`. Emits `mode=shadow` observability rows; no archive persisted. Integration + contract. Unblocks: 10.
10. **Export worker.** Job kind `br.export`. Produces archive to quarantine bucket during shadow. Chaos `CH-KILL-EXPORT`. Integration + chaos. Unblocks: 11.
11. **Import ingest + preflight.** `POST /api/backup/imports` returns preflight report; TTL per OQ-05 (15 min). Contract tests for all `BackupCorrupt.*` sub-codes with fixtures FX-C-*. Unblocks: 12.
12. **Restore endpoint (shadow-only).** Preflight-gated; conflict-policy binding; audit-chain verify per `INV-BR-AU-7`. Chaos `CH-CORRUPT-ARCHIVE`, `CH-CHAIN-BREAK`. Unblocks: 13.
13. **Restore worker + outer tx.** Multi-shard atomicity per OQ-13 (per-shard atomicity + compensating rollback). Chaos `CH-KILL-RESTORE`. Unblocks: 14.
14. **SSE progress + polling fallback.** `INV-BR-JB-*`. Integration for `Last-Event-ID` reconnect. Unblocks: 15.
15. **Snapshot endpoints.** List, create, pin, unpin, yank, delete, restore. Refuses delete when `pin_count > 0`. Integration + contract. Unblocks: 16.
16. **Snapshot worker + retention sweeper.** Chaos `CH-CLOCK-SKEW`. Unblocks: 17.
17. **Observability wiring.** `RequestId`/`JobId`/`ErrorId` propagation across HTTP -> job -> SSE -> audit. All 37 audit codes emitted. Contract tests for log-shape and Prometheus registry (11 counters, 4 gauges, 4 histograms). Unblocks: 18.
18. **Audit chain verifier (daily job).** Emits `backup.audit.chain_break_detected` on mismatch. Integration + property (rehash under load). Unblocks: 19.
19. **FE routes RA-BR-1..6 + capability guards.** State components (`StateForbidden`, `StateOffline`, `StatePending`) already exist. FE unit tests. Unblocks: 20.
20. **FE Export flow.** 6-state chart per `18-fe-export-flow.md`. Vitest + Playwright short e2e. Unblocks: 21.
21. **FE Import wizard.** 8-state chart per `19-fe-import-flow.md`; preflight-confirmation gate. Purged-epoch disable per FX-C-purged-epoch. Unblocks: 22.
22. **FE Snapshots list + detail.** Per `20-fe-snapshots-flow.md`; retention badges from server `expiresAt`. Unblocks: 23.
23. **FE Roles / Casbin UI (RA-BR-6).** Three-panel per `21-fe-roles-and-casbin-ui.md`. Lockout-guard integration test (FX-P-policy-lockout). Unblocks: 24.

### S2 Internal (Stage entry gate: S1 + 7 days corruption-free + chaos green + runbooks published)

24. **Runbooks in-app.** Publish `26-runbooks.md` into ops surface; `br-ops` mutating commands (`jobs:fence`, `archive:quarantine`, `locks:release --reason zombie`, `audit:pseudonymise`). Emits `backup.job.fenced`, `backup.lock.zombie_released`, `backup.audit.pseudonymised`. Unblocks: 25.
25. **GDPR pseudonymisation flow.** `fn_pseudonymise_actor` invocation gate `br.audit-pseudonymise.enabled` default `off` + legal-hold shard flag. Refusal codes `Audit.PseudonymiseBlockedByExport`, `Audit.PseudonymiseBlockedByLegalHold`. Integration. Unblocks: 26.
26. **Kill-switch drill machinery.** Quarterly scheduler; drill artifact writer; asserts audit trigger fires during drill per `INV-BR-RB-6`. Unblocks: 27.

### S3 Canary 5% (Stage entry gate: S2 + no P1 for 14 days + perf budgets met)

27. **Perf-smoke + budgets.** k6 workflow against `FX-A-large`. Budgets pinned. Unblocks: 28.

### S4 Ramp 25 -> 50 -> 100 (Stage entry gate: S3 + 7 days per step + ratio guardrail)

28. **Traffic ramp 25%.** Feature-flag targeting by tenant-id hash. Ratio guardrail alert wired.
29. **Traffic ramp 50%.** Same as above with widened targeting.
30. **Traffic ramp 100% + Plan 14 sign-off.** Move `13-*` and `14-*` plans to `.lovable/plans/done/`. Retro. Post-launch chaos schedule confirmed.

---

## Coverage Discipline

Each step's PR MUST:

1. List the invariants it satisfies (`INV-BR-*`).
2. Reference the fixtures it consumes (`FX-*`).
3. Reference the chaos scenarios it runs (`CH-*` where applicable).
4. Update `spec/26-backup-restore/24-testing-matrix.md`'s
   coverage-matrix row status if it flipped from spec-only to
   tested.
5. Bump the owning spec file's AI Confidence to `Reviewed` when
   its code lands per `INV-BR-OQ-6`.

---

## Open Question Resolutions (deadlines from `27-open-questions.md`)

| Open question | Deadline (stage) | Plan-14 step |
|---------------|------------------|--------------|
| OQ-01 signature agility | S0 | Step 3 (pin Ed25519). |
| OQ-04 restore PK vs content | S1 | Step 12 (choose PK). |
| OQ-05 preflight TTL | S1 | Step 11 (15 min). |
| OQ-07 deputy emergency override | S0 | Step 3 (no override). |
| OQ-09 idempotency retention | S1 | Step 1 (24 h). |
| OQ-11 offline / degraded UX | S1 | Step 19 (disabled-not-hidden). |
| OQ-13 multi-shard atomicity | S1 | Step 13 (per-shard + compensating). |
| OQ-02, OQ-03, OQ-06, OQ-08, OQ-10, OQ-12, OQ-14, OQ-15 | S2/S3 | Later steps as noted. |

---

## Kickoff Deliverables (Step 0 of this plan)

This file itself IS the kickoff. Nothing runs until Step 1's
migrations are applied. The `.lovable/plans/pending/13-*.md`
move to `done/` happens at Step 1 commit time.
