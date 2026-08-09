# Open Questions and Module Sign-Off

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Medium (open questions by definition).

---

## Keywords

`open-questions` · `sign-off` · `deferred` · `assumptions` · `risks` · `plan-14-preconditions`

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

Close Plan 13 (BR spec authoring) with an explicit ledger of
open questions, deferred decisions, and residual risks that
Plan 14 (BR implementation) must resolve at or before the S1
Shadow gate. Without this file, Plan 14 begins without a
disagreement surface and the module's spec drifts silently on
first contact with code.

---

## Ledger Format

Each entry has a stable id, an owning file (for context), the
question stated as a decision to make, current assumption made
in the spec, the risk if the assumption is wrong, and the
deadline (stage) by which Plan 14 must resolve it.

Stages reference `25-migration-and-rollout.md` (S0..S4).

---

## Open Questions

### OQ-01: Manifest signature algorithm agility

- **Owning file:** `07-manifest-schema.md`
- **Question:** Do we support algorithm agility (Ed25519 today, allow future algs) in the manifest signature envelope, or freeze on Ed25519 for v1?
- **Current assumption:** Ed25519 only, `alg` field required but validated as literal `"ed25519"`.
- **Risk if wrong:** Post-quantum migration in 2028+ requires archive format change; INV-BR-MF-* becomes non-forward-compatible.
- **Deadline:** S0.

### OQ-02: Snapshot dedupe strategy

- **Owning file:** `13-endpoint-snapshot.md`
- **Question:** Do Snapshots share content-addressed chunks across snapshots (chunk-store dedupe) or is each Snapshot a full archive?
- **Current assumption:** Each Snapshot is a full archive; no chunk dedupe.
- **Risk if wrong:** Storage cost balloons on tenants with high snapshot cadence; may force retention shortening.
- **Deadline:** S2.

### OQ-03: Cross-region archive residency

- **Owning file:** `11-endpoint-export.md`
- **Question:** Where does the archive land when the tenant's data residency region differs from the operator's region?
- **Current assumption:** Archive lands in tenant's residency region; operator downloads via signed URL.
- **Risk if wrong:** Sovereignty violation on Restore from a mis-regioned URL.
- **Deadline:** S2.

### OQ-04: Restore into non-empty target

- **Owning file:** `14-endpoint-restore.md`
- **Question:** When the target scope is non-empty, does `conflictPolicy=abortOnAny` compare by primary key only or by row content hash?
- **Current assumption:** Primary key only; content differences are surfaced by Preflight but do not block `abortOnAny` unless PK collides.
- **Risk if wrong:** Silent data drift on Restore when PKs match but content differs.
- **Deadline:** S1.

### OQ-05: Preflight cache TTL

- **Owning file:** `12-endpoint-import.md`
- **Question:** How long is a Preflight result valid before it must be recomputed?
- **Current assumption:** 15 minutes; after that Apply must re-run Preflight.
- **Risk if wrong:** Stale preflight lets a client Apply against a target that changed since the report; violates `INV-BR-MS-1` in spirit.
- **Deadline:** S1.

### OQ-06: SSE fallback for HTTP/1.1 proxies

- **Owning file:** `15-jobs-and-progress.md`, `19-fe-import-flow.md`
- **Question:** Is 5s polling a permanent fallback or a temporary bridge to WebSockets?
- **Current assumption:** Permanent fallback; polling is the interoperable floor.
- **Risk if wrong:** Long-tail HTTP/1.1 proxies get 5s progress latency forever; UX suffers on very long Restores.
- **Deadline:** S3.

### OQ-07: RBAC deputy semantics under emergency

- **Owning file:** `02-casbin-integration.md`, `03-permission-matrix.md`
- **Question:** Can `Role.OnCall` bypass a `deputy` deny-override during a P1 incident?
- **Current assumption:** No; deputy deny is absolute. Emergency-override requires a separate `System.Configure` grant flow.
- **Risk if wrong:** RB-03 chain-repair operator is blocked in a real incident.
- **Deadline:** S0.

### OQ-08: Audit slice size cap

- **Owning file:** `23-audit-and-compliance.md`
- **Question:** Is there a hard cap on `scope/audit/*.jsonl.zst` size in a single Export?
- **Current assumption:** No hard cap; audit slice grows unbounded with tenant history.
- **Risk if wrong:** A 10-year tenant's Export becomes multi-GiB audit-only; Preflight window on Import exceeds SLA.
- **Deadline:** S2.

### OQ-09: Idempotency-Key retention window

- **Owning file:** `16-idempotency-and-locks.md`
- **Question:** How long are `backup_idempotency_keys` retained after last use?
- **Current assumption:** 24 hours after last hit.
- **Risk if wrong:** A retry after 25 hours succeeds twice; violates INV-BR-LK-*.
- **Deadline:** S1.

### OQ-10: Kill-switch scope granularity

- **Owning file:** `25-migration-and-rollout.md`, `26-runbooks.md`
- **Question:** Is `br.kill-switch` global only, or per-tenant?
- **Current assumption:** Global only in v1.
- **Risk if wrong:** A single tenant incident forces a module-wide halt.
- **Deadline:** S3.

### OQ-11: FE offline / degraded-mode UX

- **Owning file:** `17-fe-routes.md`, `18-fe-export-flow.md`, `19-fe-import-flow.md`
- **Question:** When the module is disabled (`503 ModuleDisabled`), do we hide `RA-BR-2..5` from the nav or leave them visible with a disabled state?
- **Current assumption:** Leave visible with `StateOffline` per `18-fe-routes.md`'s disabled-not-hidden rule.
- **Risk if wrong:** Users refresh into a broken page rather than a clear disabled state.
- **Deadline:** S1.

### OQ-12: Pseudonymisation reversal

- **Owning file:** `23-audit-and-compliance.md`
- **Question:** Is pseudonymisation ever reversible (e.g. subject withdraws erasure request)?
- **Current assumption:** No; per-epoch HMAC + rotation makes reversal infeasible by design.
- **Risk if wrong:** Legal reversal request cannot be served; may require a separate un-erasure workflow.
- **Deadline:** S2.

### OQ-13: Multi-shard atomicity

- **Owning file:** `14-endpoint-restore.md`, `16-idempotency-and-locks.md`
- **Question:** When a Restore spans multiple shards, is the outer transaction atomic across all shards or per-shard?
- **Current assumption:** Per-shard atomicity with a compensating rollback on any per-shard failure.
- **Risk if wrong:** Partial Restore across shards on failure; requires explicit user-facing state.
- **Deadline:** S1.

### OQ-14: Snapshot pin quota

- **Owning file:** `13-endpoint-snapshot.md`
- **Question:** Is there a per-tenant cap on pinned snapshots?
- **Current assumption:** Soft cap 100; hard cap 1000; both configurable per tier.
- **Risk if wrong:** Storage cost from indefinite pinning; retention sweeper starves.
- **Deadline:** S2.

### OQ-15: Chaos scenario expansion post-launch

- **Owning file:** `24-testing-matrix.md`
- **Question:** Do we require a chaos-scenario version bump for every new failure mode found in production?
- **Current assumption:** Yes; INV-BR-TS-4 already enforces closed-set + version bump.
- **Risk if wrong:** Chaos catalogue drifts; regression tests fail to cover real incidents.
- **Deadline:** Ongoing.

---

## Deferred To Plan 15+

These are explicitly out of scope for Plan 14 and MUST NOT
block S4 (100% ramp):

| Deferred item                              | Rationale                                                            |
|--------------------------------------------|----------------------------------------------------------------------|
| Post-quantum signature migration           | Depends on OQ-01 resolution and library maturity.                    |
| Chunk-store dedupe for Snapshots           | Depends on OQ-02; storage optimisation, not correctness.             |
| Per-tenant kill-switch                     | Depends on OQ-10; global switch is sufficient for v1.                |
| WebSocket progress transport               | Depends on OQ-06; SSE + polling is sufficient for v1.                |
| Un-erasure / pseudonymisation reversal     | Depends on OQ-12; not required by GDPR.                              |

---

## Assumptions Recap (spec-wide)

Assumptions the whole module leans on. If any is wrong the
spec needs a version bump on the owning file.

1. Postgres 16 is the only supported DB (already asserted by `INV-BR-TS-6`).
2. Object storage supports signed URLs with sub-minute TTLs and `Content-MD5` enforcement.
3. Workers are horizontally scalable and can hold `br.global` advisory locks for the duration of an outer Restore tx.
4. Casbin PDP evaluates in under 5 ms for a 14-capability matrix.
5. Client SDKs implement UUIDv7 `Idempotency-Key` generation correctly.
6. Legal signoff for pseudonymisation is a synchronous prerequisite, not a workflow this module owns.

---

## Sign-Off Criteria (Plan 13 -> Plan 14 handover)

Plan 14 may begin when ALL of these are true. This is the
handover contract and MUST be verified by the release PR
labeler:

1. All spec files 00..27 exist at declared paths with version, AI confidence, ambiguity, keywords, scoring, and invariants sections.
2. Every `INV-BR-*` invariant declared in files 04..26 appears in `24-testing-matrix.md`'s coverage matrix.
3. The BR audit event catalogue totals 37 codes across `22-observability.md` (29), `23-audit-and-compliance.md` (3), `25-migration-and-rollout.md` (2), and `26-runbooks.md` (3).
4. This file's OQ-01, OQ-04, OQ-05, OQ-07, OQ-09, OQ-11, OQ-13 have Plan-14 deadlines set no later than S1.
5. `.lovable/plans/pending/13-*.md` is moved to `.lovable/plans/done/` (Plan 14 handles this at commit time).
6. No spec file has AI Confidence `Draft` at merge time for Plan 14; Draft was acceptable during Plan 13 authoring but Plan 14 requires each file to be promoted to `Reviewed` when its owning code lands.

---

## Invariants

| ID              | Rule |
|-----------------|------|
| INV-BR-OQ-1     | Every open question MUST have a stage-based deadline; a deadline in the past blocks the release PR labeler. |
| INV-BR-OQ-2     | Deferred items MUST NOT block S4; if S4 discovers a blocker among them, escalate to a new plan. |
| INV-BR-OQ-3     | Assumptions recap MUST match the invariants declared elsewhere in the module; contradictions block sign-off. |
| INV-BR-OQ-4     | Sign-off criteria are the closed set above; adding a criterion requires a version bump of this file. |
| INV-BR-OQ-5     | Resolving an open question MUST version-bump the owning spec file AND update this ledger's status. |
| INV-BR-OQ-6     | Plan 14 MUST NOT begin implementation of an endpoint whose owning spec file still has AI Confidence `Draft`. |
| INV-BR-OQ-7     | Deadlines are stages, not calendar dates, so rollout slippage does not silently invalidate them. |
| INV-BR-OQ-8     | The BR audit catalogue total (37) is asserted here; any new code MUST version-bump both this file and the owning spec file. |

---

## Cross-References

- Every file in `spec/26-backup-restore/` from `00-overview.md` through `26-runbooks.md`.
- [`spec/25-app-audit/03-plan-300-steps.md`](../25-app-audit/03-plan-300-steps.md): Band A+ track that consumes BR module invariants.
- [`.lovable/plans/pending/13-*`](../../.lovable/plans/pending/): Plan 13 tracking; moved to `done/` at Plan 14 kickoff.
