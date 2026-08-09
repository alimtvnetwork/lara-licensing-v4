# Scope Catalog

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`scope` · `export` · `snapshot` · `catalogue` · `tables` · `closed-sets` · `feature-catalog` · `license` · `secrets-envelope` · `files` · `migration-state`

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

Authoritative, closed enumeration of every artifact class that an
Export archive and a Snapshot archive **MUST** contain. Downstream
files bind to this catalogue by class ID (`SC-*`); if an artifact is
not listed here, it is out of scope by construction and MUST be
enumerated in [`06-scope-exclusions.md`](./06-scope-exclusions.md)
with a justification.

Every class listed below is a normative unit of atomicity per
[`04-invariants.md`](./04-invariants.md) §`INV-BR-A`: an archive is
valid only if every class it declares in the manifest is present,
checksum-verified, and restorable in one transaction (or one
transaction per class when the class is explicitly declared
independently restorable).

---

## Scope Classes (`SC-A..H`)

| ID     | Class                | Persisted In                                                                | Restorable Unit          | Ordering (restore) |
|--------|----------------------|-----------------------------------------------------------------------------|--------------------------|--------------------|
| `SC-A` | Schema state         | `public.migrations` rows + declared `schema_hash`                           | Whole (single tx)        | 1                  |
| `SC-B` | Closed-set tables    | `public.closed_sets`, `public.closed_set_values` (see [closed-sets](../../src/lib/closed-sets.ts)) | Whole (single tx) | 2 |
| `SC-C` | Feature catalog      | `public.features`, `public.feature_defaults`                                | Whole (single tx)        | 3                  |
| `SC-D` | License artifacts    | `public.licenses`, `public.license_features`, `public.license_epochs`       | Whole (single tx)        | 4                  |
| `SC-E` | RBAC state           | `public.user_roles`, `public.casbin_rules`, `public.system_bootstrap`       | Whole (single tx)        | 5                  |
| `SC-F` | Domain tables        | Every remaining `public.*` table not listed above and not in `06-scope-exclusions.md` | Whole (single tx) | 6 |
| `SC-G` | Secrets envelope     | HKDF-sealed blob per [`09-encryption-and-keys.md`](<spec-placeholder file="09-encryption-and-keys.md" />) | Whole (re-seal on restore) | 7 |
| `SC-H` | File objects         | Object-storage entries referenced by any table in `SC-F` via `file_id`      | Per-object (streamed)    | 8                  |

Restore order is normative: schema before data (`SC-A` first), closed
sets and feature catalog before license (license rows FK into
feature IDs), RBAC before domain tables (domain-table RLS uses
`has_role()`), secrets envelope resealed before file objects are
served (encrypted paths depend on the current epoch key), file
objects last so a mid-stream failure cannot leave the row present
without its blob.

---

## Class Contracts

Each class binds three normative facts:

1. **Selector**: the exact SQL predicate or storage pattern used to
   enumerate members at Export time.
2. **Manifest slot**: the JSON key under `manifest.scope.*` where
   the class's contentHash lands (schema pinned by
   [`07-manifest-schema.md`](<spec-placeholder file="07-manifest-schema.md" />)).
3. **Restore boundary**: whether the class restores as one DB
   transaction (`whole`) or streams objects one at a time
   (`per-object`).

### SC-A · Schema state

- Selector: `SELECT migration FROM public.migrations ORDER BY id ASC` plus the SHA-256 over the concatenated migration file bodies at Export time (`schema_hash`).
- Manifest slot: `manifest.scope.schema` (`{ migrations: string[], schemaHash: string }`).
- Restore boundary: `whole`. On mismatch, restore aborts with `BackupVersionMismatch` (see [`16-error-taxonomy.md`](<spec-placeholder file="16-error-taxonomy.md" />)).

### SC-B · Closed-set tables

- Selector: `SELECT * FROM public.closed_sets` and `SELECT * FROM public.closed_set_values`.
- Manifest slot: `manifest.scope.closedSets`.
- Restore boundary: `whole`. Values are keyed by `(set_id, value_key)`; conflicts resolve per the strategy pinned in [`12-endpoint-import.md`](<spec-placeholder file="12-endpoint-import.md" />).

### SC-C · Feature catalog

- Selector: `SELECT * FROM public.features` and `SELECT * FROM public.feature_defaults`.
- Manifest slot: `manifest.scope.features`.
- Restore boundary: `whole`. Restoring `SC-D` before `SC-C` is a manifest error.

### SC-D · License artifacts

- Selector: `SELECT * FROM public.licenses`, `SELECT * FROM public.license_features`, `SELECT * FROM public.license_epochs` (all epochs, not just current).
- Manifest slot: `manifest.scope.licenses`.
- Restore boundary: `whole`. Re-seal is applied to any per-license secret payload as part of `SC-G` restore.

### SC-E · RBAC state

- Selector: `SELECT * FROM public.user_roles`, `SELECT * FROM public.casbin_rules`, `SELECT * FROM public.system_bootstrap`.
- Manifest slot: `manifest.scope.rbac`.
- Restore boundary: `whole`. `MIG-CAS-1..3` parity is verified in the same transaction; a drift is a restore failure, not a warning.

### SC-F · Domain tables

- Selector: every `public.*` table in `pg_tables` minus the tables listed in `SC-A..E` above and minus every table enumerated in `06-scope-exclusions.md` §Excluded Tables. The selector is computed at Export time, not hard-coded.
- Manifest slot: `manifest.scope.domain` (`{ tables: { name: string, rowCount: number, contentHash: string }[] }`).
- Restore boundary: `whole` per-table, in dependency order derived from FK graph.

### SC-G · Secrets envelope

- Selector: the HKDF-sealed blob of every row column marked `sensitive: true` in the schema registry, re-sealed under the archive's ephemeral key (see [`09-encryption-and-keys.md`](<spec-placeholder file="09-encryption-and-keys.md" />)).
- Manifest slot: `manifest.scope.secretsEnvelope`.
- Restore boundary: `whole` and re-sealed under the **current** epoch key at Restore time, satisfying `INV-BR-C` (forward secrecy).

### SC-H · File objects

- Selector: every object-storage key referenced by any row in `SC-F` via a `file_id` column, resolved to `{ bucket, path, sha256, size }`.
- Manifest slot: `manifest.scope.files` (index only; bodies live in the archive body per [`08-archive-format.md`](<spec-placeholder file="08-archive-format.md" />)).
- Restore boundary: `per-object` with resumable streaming; a failed object emits `RestoreFileFailed` and the outer job continues per [`15-jobs-and-progress.md`](<spec-placeholder file="15-jobs-and-progress.md" />) cancel semantics.

---

## Snapshot vs Export

Snapshots and Exports share the same class catalogue with two
differences pinned here:

| Aspect              | Snapshot                                            | Export                                                    |
|---------------------|-----------------------------------------------------|-----------------------------------------------------------|
| `SC-H` file bodies  | Referenced by pointer (COW-style, same storage)     | Copied into archive body                                  |
| `SC-G` re-seal      | On Restore only                                     | On Export **and** on Restore                              |
| Portability         | Same host only                                      | Portable across hosts of the same `appVersion` major      |
| Retention           | Bound by [`13-endpoint-snapshot.md`](<spec-placeholder file="13-endpoint-snapshot.md" />) quota | Retained until the Super Admin deletes the archive |

A Snapshot missing any class listed here is not a valid snapshot; a
partial snapshot violates `INV-BR-A` and MUST be rejected at
`13-endpoint-snapshot.md` §Create.

---

## Invariants (`INV-BR-SC-1..6`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next
edit of that file (tracked by the step 30 consistency report).

| ID              | Statement                                                                                                              |
|-----------------|------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-SC-1`   | Every archive manifest declares exactly one entry per class `SC-A..H`; a missing slot is an invalid archive.           |
| `INV-BR-SC-2`   | Every table in `public.*` is either in one of `SC-A..F` or is enumerated in `06-scope-exclusions.md`; no third state.  |
| `INV-BR-SC-3`   | Restore ordering is `SC-A -> SC-B -> SC-C -> SC-D -> SC-E -> SC-F -> SC-G -> SC-H`; deviating is an archive error.     |
| `INV-BR-SC-4`   | `SC-G` re-seals under the current epoch key at Restore; the source epoch key is never re-derivable (`INV-BR-C`).       |
| `INV-BR-SC-5`   | `SC-H` object bodies are content-addressed by SHA-256; a mismatch fails the individual object, not the whole archive.  |
| `INV-BR-SC-6`   | Adding a new table to `public.*` requires updating either this catalogue or `06-scope-exclusions.md` in the same PR.   |

---

## Cross-References

- Consumed by: `<spec-placeholder file="07-manifest-schema.md" />`,
  `<spec-placeholder file="08-archive-format.md" />`,
  `<spec-placeholder file="09-encryption-and-keys.md" />`,
  `<spec-placeholder file="11-endpoint-export.md" />`,
  `<spec-placeholder file="12-endpoint-import.md" />`,
  `<spec-placeholder file="13-endpoint-snapshot.md" />`,
  `<spec-placeholder file="14-endpoint-restore.md" />`.
- Companion: [`06-scope-exclusions.md`](<spec-placeholder file="06-scope-exclusions.md" />) enumerates what is deliberately not in scope with per-exclusion justification.
- Normative parent: [`04-invariants.md`](./04-invariants.md) §`INV-BR-A` (atomicity).
