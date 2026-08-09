# Testing Matrix

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`testing` · `unit` · `property` · `integration` · `e2e` · `chaos` · `contract` · `coverage-matrix` · `fixtures` · `invariants`

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

Pin the closed-set test taxonomy, per-invariant coverage matrix,
fixture catalogue, and CI gates for the Backup / Restore /
Snapshot module. Files 04..23 declared ~100 invariants
(`INV-BR-*`) but no file has enumerated which test tier proves
each one, what fixtures are shared, or which gates block merge.
Without this, the implementation plan (Plan 14) has no
acceptance oracle and CI cannot fail on missing coverage.

---

## Test Tiers (closed set)

| Tier              | Purpose                                                             | Runner                                     | Runs in CI      |
|-------------------|---------------------------------------------------------------------|--------------------------------------------|-----------------|
| `unit`            | One class or pure function, no I/O.                                 | PHPUnit (BE), Vitest (FE).                 | Every PR.       |
| `contract`        | Request/response shape vs `spec/03-error-manage` envelope + BR wire.| Pact-lite JSON schema harness.             | Every PR.       |
| `property`        | Randomised invariants: manifest hashing, chain re-hash, closed sets.| PHPUnit + `eris`; Vitest + `fast-check`.   | Every PR.       |
| `integration`     | Real Postgres + real MinIO; single module boundary.                 | PHPUnit `@group integration` on Postgres 16.| Every PR.       |
| `e2e`             | Full HTTP + worker + FE, real archives on disk.                     | Playwright + backend worker.               | Every PR (short) + nightly (full). |
| `chaos`           | Failure injection: kill worker mid-apply, corrupt bytes, clock skew.| Custom harness `br:chaos`.                 | Nightly + release. |
| `perf-smoke`      | Fixed archive size, timing budget guardrails.                       | k6 against staging.                        | Nightly + release. |

No other tier is permitted. Tests MUST declare their tier via
group/tag and MUST NOT cross tiers (an `integration` test cannot
be tagged `unit`).

---

## Fixture Catalogue

Stored under `backend/tests/Fixtures/backup/` and mirrored to
`tests/e2e/fixtures/backup/` for Playwright.

| Fixture ID              | Shape                                                                 | Consumers                                  |
|-------------------------|-----------------------------------------------------------------------|--------------------------------------------|
| `FX-A-happy`            | Well-formed archive, 3 shards, 10 audit rows, no snapshots.           | contract, integration, e2e.                |
| `FX-A-happy-signed`     | `FX-A-happy` re-signed with the previous KEK epoch.                   | integration (Restore across epochs).       |
| `FX-A-audit-only`       | Manifest with only `scope/audit/*`.                                   | contract, integration (audit-slice tests). |
| `FX-A-large`            | 5 GiB archive, 1M rows, 20 snapshots.                                 | perf-smoke, chaos.                         |
| `FX-C-bad-magic`        | First 6 bytes flipped.                                                | contract (BackupCorrupt.bad_magic).        |
| `FX-C-bad-manifest`     | `manifest.json.zst` re-signed but mutated payload.                    | contract (BackupCorrupt.manifest_signature).|
| `FX-C-chain-break`      | One audit row's `rowHash` mutated.                                    | integration (audit_chain_break_on_import). |
| `FX-C-truncated`        | Last 4 KiB removed.                                                   | contract (BackupCorrupt.truncated).        |
| `FX-C-purged-epoch`     | References a purged KEK epoch.                                        | integration (Purged-epoch Preflight).      |
| `FX-P-policy-lockout`   | Casbin policy CSV that would remove all `Role.Manage` holders.        | integration (lockout guard).               |
| `FX-P-deputy-deny`      | Policy with deputy deny-override.                                     | integration + FE unit.                     |
| `FX-J-stuck`            | Job stuck in `Running` past heartbeat window.                         | chaos.                                     |
| `FX-K-idempotency-hit`  | Two requests with the same `Idempotency-Key`, different bodies.       | contract (Idempotency.Conflict).           |

Adding a fixture requires a version bump of this file and an
entry in the coverage matrix.

---

## Coverage Matrix

Each `INV-BR-*` invariant MUST have at least one test in the
listed tier(s). CI gate `br:coverage-matrix` parses this table
and fails on missing rows.

| Invariant                 | Owning file                          | Tiers                                          |
|---------------------------|--------------------------------------|------------------------------------------------|
| `INV-BR-1..8`             | `04-invariants.md`                   | property, integration.                         |
| `INV-BR-SC-*`             | `05-scope-catalog.md`                | unit, contract.                                |
| `INV-BR-SX-*`             | `06-scope-exclusions.md`             | unit, contract.                                |
| `INV-BR-MF-*`             | `07-manifest-schema.md`              | property (hash), contract (schema).            |
| `INV-BR-AF-*`             | `08-archive-format.md`               | property (round-trip), integration.            |
| `INV-BR-CR-*`             | `09-encryption-and-keys.md`          | property (AES-GCM), integration (KEK rotate).  |
| `INV-BR-SF-*`             | `10-secrets-forward-secrecy.md`      | integration (purge), chaos (mid-rotate).       |
| `INV-BR-EP-EX-*`          | `11-endpoint-export.md`              | contract, integration, e2e.                    |
| `INV-BR-EP-IM-*`          | `12-endpoint-import.md`              | contract, integration, e2e.                    |
| `INV-BR-EP-SN-*`          | `13-endpoint-snapshot.md`            | contract, integration, e2e.                    |
| `INV-BR-EP-RS-*`          | `14-endpoint-restore.md`             | contract, integration, e2e, chaos.             |
| `INV-BR-JB-*`             | `15-jobs-and-progress.md`            | integration (SSE), chaos (worker kill).        |
| `INV-BR-LK-*`             | `16-idempotency-and-locks.md`        | property (lock order), integration.            |
| `INV-BR-FE-RT-*`          | `17-fe-routes.md`                    | FE unit, e2e.                                  |
| `INV-BR-FE-EX-*`          | `18-fe-export-flow.md`               | FE unit, e2e.                                  |
| `INV-BR-FE-IM-*`          | `19-fe-import-flow.md`               | FE unit, e2e.                                  |
| `INV-BR-FE-SN-*`          | `20-fe-snapshots-flow.md`            | FE unit, e2e.                                  |
| `INV-BR-FE-RB-*`          | `21-fe-roles-and-casbin-ui.md`       | FE unit, integration (dry-run), e2e.           |
| `INV-BR-OB-*`             | `22-observability.md`                | contract (log shape), integration.             |
| `INV-BR-AU-1..10`         | `23-audit-and-compliance.md`         | integration (trigger), property (chain), chaos (recompute). |

---

## Chaos Scenarios (closed set)

| Scenario ID       | Trigger                                                       | Expected outcome                                                            |
|-------------------|---------------------------------------------------------------|------------------------------------------------------------------------------|
| `CH-KILL-EXPORT`  | SIGKILL worker mid-Export.                                    | Job resumes on next poll, no partial archive published.                     |
| `CH-KILL-RESTORE` | SIGKILL worker after preflight, before apply commit.          | Outer tx rolls back, no scope mutation, one `restore.aborted` audit row.    |
| `CH-CLOCK-SKEW`   | Advance worker clock by 24 h during Snapshot retention sweep. | Retention derived from server `expiresAt`, no premature yank.               |
| `CH-CORRUPT-ARCHIVE` | Flip 1 bit in archive body during ingest.                  | Preflight fails with `BackupCorrupt.body_hash`.                             |
| `CH-CHAIN-BREAK`  | Mutate one `rowHash` in the audit slice.                      | Restore aborts with `BackupCorrupt.audit_chain_break_on_import`.            |
| `CH-KEK-ROTATE`   | Rotate KEK during in-flight Export.                           | Export completes with the old epoch id recorded in manifest.                |
| `CH-LOCK-STARVE`  | Hold `br.global` lock 60 s.                                   | New Export queues, no deadlock, `retryAfterSeconds` propagated to FE.       |

Adding a scenario requires a version bump.

---

## CI Gates

Gates run in `.github/workflows/br-tests.yml` (created in Plan
14). Merge blocked on failure.

| Gate                       | Job                                          | Blocks merge |
|----------------------------|----------------------------------------------|--------------|
| `br:unit`                  | PHPUnit `--group=br,unit` + Vitest BR only.  | Yes.         |
| `br:contract`              | Envelope + wire schemas.                     | Yes.         |
| `br:property`              | `eris` + `fast-check` seeded runs.           | Yes.         |
| `br:integration`           | Disposable Postgres 16 + MinIO.              | Yes.         |
| `br:coverage-matrix`       | Parses this file, fails on missing rows.     | Yes.         |
| `br:e2e-short`             | Happy path only.                             | Yes.         |
| `br:e2e-full`              | All scenarios.                               | Nightly only.|
| `br:chaos`                 | 7 chaos scenarios.                           | Nightly + release. |
| `br:perf-smoke`            | k6 budgets vs `FX-A-large`.                  | Nightly + release. |

---

## Coverage Thresholds

| Layer                     | Line % | Branch % | Notes                                    |
|---------------------------|--------|----------|-------------------------------------------|
| BE services (BR module)   | 90     | 85       | Enforced by phpunit + `--coverage-clover`.|
| BE controllers (BR)       | 85     | 75       | Includes contract tests.                  |
| FE hooks/lib (BR)         | 90     | 85       | Vitest.                                   |
| FE components (BR routes) | 80     | 70       | Vitest + Testing Library.                 |

Do not chase 100%; the closed-set invariant matrix above is the
real oracle.

---

## Invariants (of this file)

| ID              | Rule |
|-----------------|------|
| INV-BR-TS-1     | Every `INV-BR-*` in files 04..23 MUST appear in the Coverage Matrix; the `br:coverage-matrix` gate fails otherwise. |
| INV-BR-TS-2     | Test tiers are the closed set above; no ad-hoc tiers permitted. |
| INV-BR-TS-3     | Fixtures live in the catalogue table; adding one requires a version bump of this file. |
| INV-BR-TS-4     | Chaos scenarios are the closed set above; adding one requires a version bump. |
| INV-BR-TS-5     | Contract tests MUST use the canonical envelope schema from `spec/03-error-manage/`. |
| INV-BR-TS-6     | Integration tests MUST run against Postgres 16 (backend uses PG-only types); SQLite is forbidden. |
| INV-BR-TS-7     | E2E tests MUST reconstruct the Supabase session via the browser-use pattern; no direct DB seeding of `auth.users`. |
| INV-BR-TS-8     | Coverage thresholds are the closed set above; lowering any threshold requires a version bump and a documented rationale. |
| INV-BR-TS-9     | A test tagged `unit` MUST NOT open a DB or network connection; violation fails the `br:unit` gate. |
| INV-BR-TS-10    | Every chaos scenario MUST assert both the negative outcome (no partial state) AND the audit row emitted. |

---

## Cross-References

- [`04-invariants.md`](./04-invariants.md) through [`23-audit-and-compliance.md`](./23-audit-and-compliance.md): source of the `INV-BR-*` catalogue.
- [`spec/03-error-manage/`](../03-error-manage/00-overview.md): envelope schema used by contract tests.
- [`spec/25-app-audit/03-plan-300-steps.md`](../25-app-audit/03-plan-300-steps.md): where BR CI gates plug into the 300-step Band-A+ track.
