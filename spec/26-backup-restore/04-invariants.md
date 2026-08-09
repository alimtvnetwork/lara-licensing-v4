# Invariants

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`invariants` · `normative` · `atomicity` · `idempotency` · `forward-secrecy` · `rbac` · `audit` · `quota`

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

Flat, addressable list of every normative invariant the
Backup/Restore/Snapshot module upholds. Downstream endpoint specs
(`11..15`), the testing matrix (`24-testing-matrix.md`), and the
acceptance criteria (`97-acceptance-criteria.md`) cite invariant IDs
from this file. This is the single addressable set; if an invariant
is not listed here, no test or endpoint may cite it.

---

## Module Invariants (`INV-BR-A..F`)

Promoted from [`00-overview.md`](./00-overview.md) §Invariants.

| ID          | Name              | Statement                                                                                              |
|-------------|-------------------|--------------------------------------------------------------------------------------------------------|
| `INV-BR-A`  | Atomicity         | Every Export, Import, Snapshot, and Restore either succeeds in whole or leaves system state unchanged. |
| `INV-BR-B`  | Idempotency       | Retrying a job with the same idempotency key returns the original result; no duplicate side effects.   |
| `INV-BR-C`  | Forward secrecy   | Restoring an archive re-seals secrets under the current key epoch; prior epochs are never re-derivable.|
| `INV-BR-D`  | RBAC              | Every mutation passes the Casbin PEP; no controller trait or route attribute bypasses the enforcer.    |
| `INV-BR-E`  | Audit             | Every mutation writes an immutable audit row keyed by `RequestId` before the response envelope emits.  |
| `INV-BR-F`  | Quota             | Snapshot count and archive size never exceed configured quotas; excess is rejected pre-commit.         |

---

## Actor and Role Invariants (`INV-BR-ACT-1..5`)

Promoted from [`01-actors-and-roles.md`](./01-actors-and-roles.md).

| ID              | Statement                                                                                                              |
|-----------------|------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-ACT-1`  | After bootstrap, exactly one `super_admin` exists at all times.                                                        |
| `INV-BR-ACT-2`  | `system_bootstrap.first_user_id` is never NULLed; the FK is `ON DELETE RESTRICT`.                                      |
| `INV-BR-ACT-3`  | Every role mutation (grant, revoke, promote, demote, deputy grant, deputy expire) writes exactly one audit row.        |
| `INV-BR-ACT-4`  | Delegated (`deputy`) principals never hold `Backup.Import` or `Snapshot.Restore`, regardless of parent role.           |
| `INV-BR-ACT-5`  | Super Admin transfer is atomic: promotion of the new principal and demotion of the old happen in one DB transaction.   |

---

## Casbin Model Invariants (`INV-BR-CAS-1..6`)

Promoted from [`02-casbin-integration.md`](./02-casbin-integration.md).

| ID              | Statement                                                                                                              |
|-----------------|------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-CAS-1`  | Exactly one enforcer instance per request; no per-controller re-instantiation.                                         |
| `INV-BR-CAS-2`  | Model is loaded once at boot and cached; policy rows are reloaded on write via the adapter watcher.                    |
| `INV-BR-CAS-3`  | No policy row references a raw `user_id` as `sub`; user bindings live only in `g` rows.                                |
| `INV-BR-CAS-4`  | Deny-overrides effect is preserved by `policy_effect`; no route may override it.                                       |
| `INV-BR-CAS-5`  | `casbin_rules` and `user_roles` stay in sync per `MIG-CAS-1..3`; drift is a P1 incident.                               |
| `INV-BR-CAS-6`  | FE enforcer output is advisory; every mutation endpoint re-checks server-side.                                         |

---

## Migration Invariants (`MIG-CAS-1..5`)

Promoted from [`02-casbin-integration.md`](./02-casbin-integration.md) §Migration.

| ID             | Statement                                                                                                               |
|----------------|-------------------------------------------------------------------------------------------------------------------------|
| `MIG-CAS-1`    | Every `INSERT INTO user_roles(user_id, role)` emits a matching `g, <user_id>, <role>` row in the same transaction.      |
| `MIG-CAS-2`    | Every `DELETE FROM user_roles` deletes the matching `g` row in the same transaction.                                    |
| `MIG-CAS-3`    | `99-consistency-report.md` diff between `user_roles` and `casbin_rules` grouping rows is empty.                         |
| `MIG-CAS-4`    | `has_role()` remains sole source of truth for RLS `USING` clauses; Casbin is sole source of truth for HTTP.             |
| `MIG-CAS-5`    | On drift detection, the enforcer emits `RoleSyncPending` and the Global Error Modal surfaces a red banner.              |

---

## Permission Matrix Invariants (`INV-BR-PM-1..5`)

Promoted from [`03-permission-matrix.md`](./03-permission-matrix.md).

| ID              | Statement                                                                                                              |
|-----------------|------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-PM-1`   | Every allow/deny cell in the matrix corresponds to exactly one seed row in `casbin_rules`; step 30 diff is empty.      |
| `INV-BR-PM-2`   | `Role.Manage` and `System.Configure` are held only by `super_admin`.                                                   |
| `INV-BR-PM-3`   | `deputy` deny rows for `Backup.Import` and `Snapshot.Restore` are always present in `casbin_rules`.                    |
| `INV-BR-PM-4`   | `user` role holds zero rows in this module.                                                                            |
| `INV-BR-PM-5`   | Adding a capability requires updating the catalogue, matrix, seed CSV, endpoint spec, and migration in one changeset.  |

---

## Cross-Cutting Rules

- Every invariant listed above is normative and testable. Non-testable
  "principles" do not belong here; they belong in `00-overview.md`.
- Each invariant `MUST` be cited by at least one test row in
  `<spec-placeholder file="24-testing-matrix.md" />` and at least one
  acceptance criterion in `<spec-placeholder file="97-acceptance-criteria.md" />`.
- Adding a new invariant requires (a) an entry in this file, (b) a
  citation site in the originating sibling, (c) a test row, and (d) an
  acceptance criterion. The step 30 consistency report enforces all four.

---

## Cross-References

- Parent: [`00-overview.md`](./00-overview.md) (`INV-BR-A..F` origin).
- Sibling: [`01-actors-and-roles.md`](./01-actors-and-roles.md) (`INV-BR-ACT-1..5`).
- Sibling: [`02-casbin-integration.md`](./02-casbin-integration.md) (`INV-BR-CAS-1..6`, `MIG-CAS-1..5`).
- Sibling: [`03-permission-matrix.md`](./03-permission-matrix.md) (`INV-BR-PM-1..5`).
- Downstream: `<spec-placeholder file="05-scope-catalog.md" />` (binds `INV-BR-A` to specific tables).
- Downstream: `<spec-placeholder file="11-endpoint-export.md" />` .. `<spec-placeholder file="14-endpoint-restore.md" />` (cite invariant IDs in their §Guarantees section).
- Downstream: `<spec-placeholder file="24-testing-matrix.md" />` (test rows cite invariant IDs).
- Downstream: `<spec-placeholder file="97-acceptance-criteria.md" />` (AC-BR-* rows cite invariant IDs).
