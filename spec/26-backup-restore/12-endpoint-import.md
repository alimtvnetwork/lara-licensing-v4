# Endpoint: Import

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`endpoint` · `import` · `verify` · `apply` · `presigned-upload` · `manifest-preflight` · `epoch-purged` · `dry-run` · `idempotency`

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

Pin the HTTP contract for the Import entry point: the symmetric
twin of [`11-endpoint-export.md`](./11-endpoint-export.md).
Import takes a previously-produced archive, validates its manifest
before any DB tx opens (`INV-BR-MS-1`), unseals its DEK against a
resolvable epoch KEK (`INV-BR-EK-2`, `INV-BR-FS-4`), and either
verifies-only (dry-run) or verifies-and-applies. The apply phase
delegates to `<spec-placeholder file="14-endpoint-restore.md" />`
so this file governs only the ingestion + pre-flight surface.

---

## Route

```
POST /api/admin/backup/imports
```

- Middleware order (from `02-casbin-integration.md`): `RequestId`, `Sanctum`, `CasbinPepMiddleware`, route.
- Capability required: `Backup.Import` (`03-permission-matrix.md`); deputy role is explicitly denied per the permission matrix.
- Idempotency: `Idempotency-Key` REQUIRED, same rules as Export (16..128 chars, `^[A-Za-z0-9._-]+$`).

---

## Upload Modes

Two accepted transports, negotiated by `Content-Type`:

| Mode                | Content-Type                       | When to use                                              |
|---------------------|------------------------------------|----------------------------------------------------------|
| Presigned reference | `application/json; charset=utf-8`  | Archive already at a storage backend from `<spec-placeholder file="22-storage-backends.md" />`. Default for cPanel and S3-compatible. |
| Streaming multipart | `multipart/form-data`              | One-shot upload from CLI or FE wizard for small archives (<= 100 MiB). Larger uploads MUST use presigned. |

Streaming multipart requests larger than the per-actor cap from
`27-performance-budget.md` fail 413 `Payload.TooLarge` before any
byte hits temporary storage.

---

## Request Body: Presigned Reference

```json
{
  "source": {
    "kind":    "storage",
    "backend": "s3",
    "path":    "backups/exports/01J8Z9K2XR7C8V3M4N5P6Q7R8T.tar",
    "sha256":  "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
  },
  "mode": "verifyAndApply",
  "note": "restore-drill-2026-07"
}
```

| Field           | Type    | Required | Rules                                                                                                    |
|-----------------|---------|----------|----------------------------------------------------------------------------------------------------------|
| `source.kind`   | enum    | yes      | Closed set `{"storage","multipart"}`; must match `Content-Type`.                                          |
| `source.backend`| enum    | yes(*)   | Closed set from `22-storage-backends.md` (`local`, `s3`, `cpanel`). Required when `kind=storage`.        |
| `source.path`   | string  | yes(*)   | Backend-relative path; MUST NOT contain `..` segments. Required when `kind=storage`.                     |
| `source.sha256` | string  | yes      | Lowercase hex, 64 chars. Compared byte-for-byte against a fresh streaming hash before manifest parse.    |
| `mode`          | enum    | yes      | Closed set `{"verifyOnly","verifyAndApply"}`; controls whether apply phase enqueues.                     |
| `note`          | string  | no       | 1..280 chars.                                                                                            |

Streaming multipart mode replaces `source` with a form field `archive` (the tar stream) and a text field `sha256` (same 64-char hex).

---

## Verify Phase (synchronous, always runs)

Runs before any job is enqueued. Every failure aborts before touching the queue:

1. Idempotency lookup (return 200 replay or 409 body mismatch).
2. Casbin PEP check (`Backup.Import`).
3. Fetch or accept the archive stream; hash compressed bytes in one pass; compare to `source.sha256`. Mismatch: 422 `BackupCorrupt`, `reason=sha256_mismatch`.
4. Parse manifest (first tar entry, uncompressed per `INV-BR-AF-1`); validate against `07-manifest-schema.md` JSON Schema; failure: 422 `BackupCorrupt` with JSON pointer.
5. Verify Merkle root matches `manifest.contentHash` by streaming remaining chunks (`INV-BR-AF-4`); mismatch: 422 `BackupCorrupt`, `reason=merkle_mismatch`, `chunkPath` details.
6. Resolve KEK for `manifest.encryption.epoch` + `kid`. If Purged: 422 `BackupCorrupt`, `reason=epoch_purged`, `epoch`. If Retired or Active: continue.
7. Unseal `envelope.sealedDek` under the resolved KEK. GCM failure: 422 `BackupCorrupt`, `reason=dek_unseal_failed`. No key bytes logged.
8. Cross-check `scope` vs `excluded` disjointness (`INV-BR-MS-4`); failure: 422 `BackupCorrupt`, `reason=scope_excluded_overlap`.
9. Cross-check `producedBy.role == super_admin` (`INV-BR-MS-1`); failure: 422 `BackupCorrupt`, `reason=untrusted_producer`.

Verify phase never opens a DB write transaction. Every step logs `lara.exception` on failure with `ErrorId`, `RequestId`, JSON pointer, no key bytes, no secret payload.

---

## Response: `verifyOnly`

```
HTTP/1.1 200 OK
X-Request-Id: 01J8Z9K2XR7C8V3M4N5P6Q7R8S
```

```json
{
  "Result": "Ok",
  "Data": {
    "ArchiveId":        "01J8Z9K2XR7C8V3M4N5P6Q7R8T",
    "ManifestVersion":  "1.0.0",
    "ArchiveKind":      "export",
    "AppVersion":       "0.462.0",
    "Epoch":            7,
    "EpochState":       "Retired",
    "Scope": {
      "schema": true, "closedSets": true, "features": true, "licenses": true,
      "rbac": true, "domain": ["users","tenants"], "secretsEnvelope": true, "files": true
    },
    "ChunkCount": 42,
    "BytesTotal": 268435456,
    "ReSealPlanned": true
  },
  "Attributes": {
    "RequestId":      "01J8Z9K2XR7C8V3M4N5P6Q7R8S",
    "IdempotencyKey": "restore-drill-2026-07",
    "Capability":     "Backup.Import",
    "Mode":           "verifyOnly"
  }
}
```

- `ReSealPlanned: true` when `Epoch < activeEpoch`, matching the flag from `INV-BR-FS-5`.
- The verify response is a read-only preview; no archive row is inserted.

---

## Response: `verifyAndApply`

```
HTTP/1.1 202 Accepted
Location: /api/admin/backup/jobs/01J8Z9K3ABDE7F8G9H0J1K2L3M
X-Request-Id: 01J8Z9K3ABDE7F8G9H0J1K2L3M
```

```json
{
  "Result": "Accepted",
  "Data": {
    "JobId":     "01J8Z9K3ABDE7F8G9H0J1K2L3M",
    "ArchiveId":"01J8Z9K2XR7C8V3M4N5P6Q7R8T",
    "State":    "Queued",
    "CreatedAt":"2026-07-20T14:41:02.117Z"
  },
  "Attributes": {
    "RequestId":      "01J8Z9K3ABDE7F8G9H0J1K2L3M",
    "IdempotencyKey": "restore-drill-2026-07",
    "Capability":     "Backup.Import",
    "Mode":           "verifyAndApply"
  }
}
```

Apply phase runs the `backup.import` job kind from `<spec-placeholder file="15-jobs-and-progress.md" />`, which delegates SC-A..H writes to the Restore orchestrator.

---

## Idempotency Replay

Identical to Export: same key + same body returns 200 (verifyOnly) or 202 (verifyAndApply) with `X-Lara-Idempotency-Replay: hit`; different body under the same key fails 409 `Idempotency.KeyReused` with `Attributes.Error.Details.reason = "body_mismatch"`.

---

## Error Envelopes

| HTTP | Error code                | Trigger                                                                          |
|------|---------------------------|-----------------------------------------------------------------------------------|
| 400  | `Validation.Failed`       | Missing field, mode/kind mismatch, `Idempotency-Key` malformed, invalid path.     |
| 401  | `Auth.Unauthenticated`    | No Sanctum session.                                                               |
| 403  | `Rbac.Denied`             | Caller lacks `Backup.Import` (includes deputy denial per permission matrix).      |
| 409  | `Idempotency.KeyReused`   | Same key, different body.                                                         |
| 413  | `Payload.TooLarge`        | Multipart body > per-actor cap from `27-performance-budget.md`.                   |
| 422  | `BackupCorrupt`           | Any verify-phase failure (sha256, merkle, schema, dek, scope, epoch, producedBy). |
| 429  | `RateLimit.Exceeded`      | Per-actor Import budget.                                                          |
| 500  | `BackupCorrupt`           | SecretsProvider unreachable or KEK material inaccessible.                         |

Every 4xx/5xx logs `lara.exception` with `ErrorId`, `RequestId`, JSON pointer or `chunkPath`. `redactor.crypto` from `INV-BR-EK-6` strips base64/hex fields.

---

## Purged-Epoch Failure Path (`INV-BR-FS-4`)

When step 6 resolves `manifest.encryption.epoch` to a Purged KEK:

- Response: 422 `BackupCorrupt`, `Attributes.Error.Details = { reason: "epoch_purged", epoch, activeEpoch }`.
- No archive row inserted. No storage touched. No key bytes logged.
- Audit row `backup.import_rejected_purged_epoch` emitted with `actor`, `sourcePath`, `epoch`.
- Client remediation is out-of-band: restore from a newer archive, or (if within the retention window) recover the archive under a Retired epoch.

---

## Async Job Handoff (`verifyAndApply` only)

After verify phase succeeds, inside one DB transaction:

1. Insert `archive` row in state `Verified` (unlike Export which starts `Draft`).
2. Insert `job` row of kind `backup.import` referencing `ArchiveId` and carrying `mode=verifyAndApply`, `reSealPlanned`.
3. Emit audit row `backup.import_enqueued` with `actor`, `archiveId`, `jobId`, `epoch`, `mode`.
4. Commit tx; dispatch job.

If any step 1..3 fails, the tx rolls back and no job is dispatched.

---

## Invariants (`INV-BR-EP-IM-1..7`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next edit of that file.

| ID                | Statement                                                                                                                          |
|-------------------|------------------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-EP-IM-1`  | Verify phase runs to completion before any DB write transaction opens; a verify failure never mutates the database.                |
| `INV-BR-EP-IM-2`  | Compressed archive bytes are hashed in one streaming pass and compared to `source.sha256` before manifest parse; mismatch is fatal.|
| `INV-BR-EP-IM-3`  | `verifyOnly` responses never insert an archive row; `verifyAndApply` responses insert exactly one archive row inside one tx.       |
| `INV-BR-EP-IM-4`  | Purged-epoch archives fail 422 `BackupCorrupt` with `reason=epoch_purged`; the failure never touches storage or the DB.            |
| `INV-BR-EP-IM-5`  | Every response body conforms to the canonical envelope from `spec/03-error-manage/`; `Attributes.Capability` is always `Backup.Import`. |
| `INV-BR-EP-IM-6`  | Multipart uploads over the per-actor cap fail 413 before any byte hits temporary storage.                                          |
| `INV-BR-EP-IM-7`  | The verify phase's 9-step order is normative; step reordering is a spec violation because later steps depend on earlier state.     |

---

## Cross-References

- Parent: [`11-endpoint-export.md`](./11-endpoint-export.md) (symmetric shape), [`07-manifest-schema.md`](./07-manifest-schema.md), [`08-archive-format.md`](./08-archive-format.md), [`09-encryption-and-keys.md`](./09-encryption-and-keys.md), [`10-secrets-forward-secrecy.md`](./10-secrets-forward-secrecy.md), [`03-permission-matrix.md`](./03-permission-matrix.md).
- Consumed by: `<spec-placeholder file="13-endpoint-snapshot.md" />` (reuses verify preflight for snapshot-source archives), `<spec-placeholder file="14-endpoint-restore.md" />` (apply orchestrator), `<spec-placeholder file="15-jobs-and-progress.md" />` (`backup.import` job kind), `<spec-placeholder file="16-idempotency-and-locks.md" />`, `<spec-placeholder file="17-audit-and-observability.md" />` (`backup.import_enqueued`, `backup.import_rejected_purged_epoch`), `<spec-placeholder file="18-error-codes.md" />` (`Payload.TooLarge`, verify-phase reason codes), `<spec-placeholder file="19-state-machines.md" />` (`Verified` state), `<spec-placeholder file="20-frontend-flows.md" />` (wizard verify-then-apply UX), `<spec-placeholder file="21-cli-parity.md" />` (`lara:backup:import`), `<spec-placeholder file="22-storage-backends.md" />` (`source.backend` closed set), `<spec-placeholder file="24-testing-matrix.md" />` (verify-phase fixture suite).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-EP-IM-1..7`.
