# Endpoint: Export

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`endpoint` · `export` · `backup` · `async-job` · `idempotency` · `capability` · `envelope` · `location` · `casbin`

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

Pin the HTTP contract for the Export entry point. This is the first
client-callable surface of the pipeline: it accepts a scope
selection, mints an archive job, and hands back a 202 with a
`Location` header pointing at the job resource. All heavy lifting
(streaming write per `08-archive-format.md`, GCM encryption per
`09-encryption-and-keys.md`, seal under Active KEK per
`10-secrets-forward-secrecy.md`) runs asynchronously.

---

## Route

```
POST /api/admin/backup/exports
```

- Middleware stack (in order, from `02-casbin-integration.md`): `RequestId`, `Sanctum`, `CasbinPepMiddleware`, route.
- Capability required: `Backup.Export` (`03-permission-matrix.md`); denial emits `Rbac.Denied` envelope.
- Content type: `application/json; charset=utf-8`.
- Idempotency: `Idempotency-Key` header REQUIRED, opaque string 16..128 chars, matching `^[A-Za-z0-9._-]+$`. Contract pinned by `<spec-placeholder file="16-idempotency-and-locks.md" />`.

---

## Request Body

```json
{
  "scope": {
    "schema":          true,
    "closedSets":      true,
    "features":        true,
    "licenses":        true,
    "rbac":            true,
    "domain":          ["all"],
    "secretsEnvelope": true,
    "files":           true
  },
  "encryption": {
    "epoch": null
  },
  "note": "monthly-2026-07"
}
```

| Field                    | Type              | Required | Rules                                                                                                        |
|--------------------------|-------------------|----------|--------------------------------------------------------------------------------------------------------------|
| `scope.schema`           | boolean           | yes      | `true` selects `SC-A`; must be `true` (schema is mandatory per `INV-BR-SC-1`).                                |
| `scope.closedSets`       | boolean           | yes      | Selects `SC-B`; must be `true` if `licenses=true` (FK dependency).                                            |
| `scope.features`         | boolean           | yes      | Selects `SC-C`; must be `true` if `licenses=true` (license feature-catalog FK).                               |
| `scope.licenses`         | boolean           | yes      | Selects `SC-D`.                                                                                              |
| `scope.rbac`             | boolean           | yes      | Selects `SC-E`; must be `true` if `domain != []` (domain RLS uses `has_role()`).                              |
| `scope.domain`           | array or `["all"]`| yes      | Table names from `SC-F` allowlist or the literal `["all"]`; unknown names fail 422 `Validation.Failed`.       |
| `scope.secretsEnvelope`  | boolean           | yes      | Selects `SC-G`; sealed under Active epoch KEK.                                                               |
| `scope.files`            | boolean           | yes      | Selects `SC-H`; requires `domain != []` because file rows reference domain FKs.                              |
| `encryption.epoch`       | integer or null   | no       | `null` means seal under Active epoch (default and only allowed value per `INV-BR-FS-1`); a numeric value fails 422 `Validation.Failed` with `reason=non_active_epoch_forbidden`. |
| `note`                   | string            | no       | 1..280 chars, stored on the archive record; audited but never logged in cleartext.                            |

Any FK-dependency violation (e.g. `licenses=true` with `features=false`) fails 422 `Validation.Failed` with a JSON pointer to the offending field. Validation runs before any job enqueue.

---

## Response: 202 Accepted

```
HTTP/1.1 202 Accepted
Location: /api/admin/backup/jobs/01J8Z9K2XR7C8V3M4N5P6Q7R8S
Content-Type: application/json; charset=utf-8
X-Request-Id: 01J8Z9K2XR7C8V3M4N5P6Q7R8S
```

```json
{
  "Result": "Accepted",
  "Data": {
    "JobId":      "01J8Z9K2XR7C8V3M4N5P6Q7R8S",
    "ArchiveId": "01J8Z9K2XR7C8V3M4N5P6Q7R8T",
    "State":     "Queued",
    "CreatedAt": "2026-07-20T14:22:31.842Z"
  },
  "Attributes": {
    "RequestId":       "01J8Z9K2XR7C8V3M4N5P6Q7R8S",
    "IdempotencyKey":  "monthly-2026-07",
    "Capability":      "Backup.Export"
  }
}
```

- `JobId` is UUIDv7 and matches the `Location` header suffix.
- `ArchiveId` is minted synchronously so a client can reference it before completion; the archive row starts in state `Draft` per `<spec-placeholder file="19-state-machines.md" />`.
- Envelope shape is the canonical response envelope from `spec/03-error-manage/` (Result, Data, Attributes).

---

## Idempotency Replay

Re-POST with the same `Idempotency-Key` and same request body returns 200 with the original 202 payload plus:

```
X-Lara-Idempotency-Replay: hit
```

Re-POST with the same key but a different body fails 409 with error code `Idempotency.KeyReused` (closed-set entry defined in `18-error-codes.md`) and `Attributes.Error.Details.reason = "body_mismatch"`; the original job is unaffected.

---

## Error Envelopes

Every failure uses the canonical envelope from `spec/03-error-manage/`:

| HTTP | Error code                | Trigger                                                            |
|------|---------------------------|---------------------------------------------------------------------|
| 400  | `Validation.Failed`       | Missing required field, unknown domain table, `Idempotency-Key` malformed. |
| 401  | `Auth.Unauthenticated`    | No Sanctum session.                                                 |
| 403  | `Rbac.Denied`             | Caller lacks `Backup.Export`.                                       |
| 409  | `Idempotency.KeyReused`   | Same key, different body.                                           |
| 422  | `Validation.Failed`       | FK-dependency violation between scope flags.                        |
| 422  | `BackupCorrupt`           | `encryption.epoch` set to a non-null non-Active value.              |
| 429  | `RateLimit.Exceeded`      | Per-actor Export budget from `27-performance-budget.md`.            |
| 500  | `BackupCorrupt`           | KEK unresolvable at enqueue time.                                   |

Every 5xx logs `lara.exception` with `ErrorId`, `RequestId`, JSON pointer, and NO scope values.

---

## Async Job Handoff

The synchronous handler performs, in order:

1. Validate schema + FK-dependencies (fail fast, no writes).
2. Casbin PEP check (`Backup.Export`).
3. Idempotency lookup (return 200 replay or 409 body mismatch).
4. Resolve Active epoch KEK id via SecretsProvider (fail 500 if unresolvable).
5. Insert `archive` row in state `Draft` inside a DB tx.
6. Insert `job` row of kind `backup.export` referencing `ArchiveId`, bound in `<spec-placeholder file="15-jobs-and-progress.md" />`.
7. Emit audit row `backup.export_enqueued` with `actor`, `archiveId`, `jobId`, `scope`, `note`.
8. Commit tx, dispatch job to queue, return 202.

If any step 1..5 fails, the tx rolls back and no job is dispatched; step 6..8 are atomic under the outer tx so a partial "job without archive" is impossible.

---

## Invariants (`INV-BR-EP-EX-1..5`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next edit of that file.

| ID                | Statement                                                                                                                          |
|-------------------|------------------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-EP-EX-1`  | Every 2xx response includes `Location`, `X-Request-Id`, and a `JobId` equal to the `Location` suffix.                              |
| `INV-BR-EP-EX-2`  | `Idempotency-Key` is required; missing header fails 400 `Validation.Failed` before any DB write.                                   |
| `INV-BR-EP-EX-3`  | The archive row and its export job are inserted inside one DB transaction; a job without a matching archive row cannot exist.      |
| `INV-BR-EP-EX-4`  | `encryption.epoch` accepts only `null`; explicit non-Active values fail 422 `BackupCorrupt` (`INV-BR-FS-1`).                        |
| `INV-BR-EP-EX-5`  | Every response body conforms to the canonical envelope from `spec/03-error-manage/`; `Attributes.Capability` is always `Backup.Export`. |

---

## Cross-References

- Parent: [`02-casbin-integration.md`](./02-casbin-integration.md), [`03-permission-matrix.md`](./03-permission-matrix.md), [`05-scope-catalog.md`](./05-scope-catalog.md), [`07-manifest-schema.md`](./07-manifest-schema.md), [`08-archive-format.md`](./08-archive-format.md), [`09-encryption-and-keys.md`](./09-encryption-and-keys.md), [`10-secrets-forward-secrecy.md`](./10-secrets-forward-secrecy.md).
- Consumed by: `<spec-placeholder file="12-endpoint-import.md" />` (mirror shape), `<spec-placeholder file="13-endpoint-snapshot.md" />` (subset), `<spec-placeholder file="15-jobs-and-progress.md" />` (`backup.export` job kind), `<spec-placeholder file="16-idempotency-and-locks.md" />` (key contract), `<spec-placeholder file="17-audit-and-observability.md" />` (`backup.export_enqueued` row), `<spec-placeholder file="18-error-codes.md" />` (`Idempotency.KeyReused`, `Validation.Failed`), `<spec-placeholder file="19-state-machines.md" />` (`Draft` starting state), `<spec-placeholder file="20-frontend-flows.md" />` (wizard POST), `<spec-placeholder file="21-cli-parity.md" />` (`lara:backup:export`), `<spec-placeholder file="24-testing-matrix.md" />` (endpoint conformance suite).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-EP-EX-1..5`.
