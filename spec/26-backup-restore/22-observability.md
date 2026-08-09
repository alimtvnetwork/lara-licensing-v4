# Observability

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`observability` · `request-id` · `error-id` · `lara-diag` · `audit-row` · `metrics` · `sse` · `propagation` · `redactor`

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

Pin the observability contract for the entire Backup / Restore /
Snapshot module: a single canonical event catalogue, a
`RequestId` propagation chain from FE submit through worker job,
progress-event, and audit row, and a closed-set metrics registry.
Every endpoint (11..14), the job model (15), the lock registry
(16), and every FE flow (18..21) already emit `lara-diag`
entries and audit rows with ad-hoc fields; without this file the
field set drifts, `RequestId` is not enforced across the
`request -> job -> SSE -> audit` boundary, and step 24
(audit/compliance) plus step 25 (testing matrix) have no
canonical target to conform against. This file consumes the base
correlation shape from
[`spec/03-error-manage/`](../03-error-manage/00-overview.md) and
adds only the BR-specific catalogue, metric names, and
propagation invariants.

---

## Correlation Chain

Every user-initiated BR action MUST produce a chain with three
identifiers:

| Identifier   | Source                                       | Scope                                                                 |
|--------------|----------------------------------------------|-----------------------------------------------------------------------|
| `RequestId`  | Middleware from `03-error-manage` (UUIDv7)   | One HTTP request. FE stamps `X-Request-Id`; server echoes it back.    |
| `JobId`      | Job row created inside the request tx        | One long-running job (Export/Import/Restore/Snapshot/RetentionSweep). |
| `ErrorId`    | Minted at the throw site of `LaraException` | One error occurrence.                                                 |

Propagation invariants:

1. Every job row MUST persist the initiating `RequestId` in
   column `initiatingRequestId`.
2. Every SSE progress event MUST include `requestId` (the
   initiator's) AND `jobId`; the FE MUST log both on transition.
3. Every audit row on a BR action MUST include `RequestId` AND,
   when applicable, `JobId` and `ErrorId`.
4. Retry attempts of the same job MUST carry the initiator's
   `RequestId`, not a fresh one; workers MUST NOT rewrite this
   field.
5. `ErrorId` is minted exactly once per throw and copied to
   response `Attributes.ErrorId`, log context, and (when the
   error terminates a job) the `backup_jobs.errorId` column.

---

## `lara-diag` Entry Shape (BR-Specific)

All BR entries extend the base shape from
`spec/03-error-manage/` and add the following typed fields.
Level is one of `debug|info|warn|error`. Message text is a
closed-set enum from the copy dictionary; free-form strings are
forbidden.

Base fields (from error-manage): `RequestId`, `ErrorId?`,
`ErrorCode?`, `userId?`, `sessionId?`, `route`, `httpStatus?`.

BR extensions:

| Field                | Type       | When required                                        |
|----------------------|------------|------------------------------------------------------|
| `module`             | literal    | Always `"backup-restore"`.                           |
| `jobId`              | uuid v7    | Any log tied to a job (create/progress/complete).    |
| `jobKind`            | closed set | Any log with `jobId`. See `15-jobs-and-progress.md`.  |
| `sequence`           | integer    | SSE-driven FE transitions and worker progress writes.|
| `action`             | closed set | Mutating FE actions (see BR action enum below).      |
| `capability`         | closed set | RBAC decisions (allow/deny logs).                    |
| `snapshotId`         | uuid v7    | Snapshot list/detail mutations.                      |
| `policyVersion`      | integer    | Roles/policy actions.                                |
| `findingCounts`      | object     | Roles dry-run responses.                             |
| `retryAfterSeconds`  | integer    | 429/503 responses to submitting client.              |

Closed-set BR action enum (FE): `submitExport`, `submitImport`,
`submitRestore`, `submitSnapshot`, `pin`, `unpin`, `yank`,
`deleteSnapshot`, `dryRunPolicy`, `savePolicy`, `previewUser`,
`conflictReload`, `discard`, `cancelJob`, `reconnectSse`.

---

## Audit Event Catalogue

Closed set. Adding a code requires a version bump of this file
AND the audit row schema in `23-audit-and-compliance.md`. Every
row cites at least `RequestId`; jobful codes cite `JobId`; error
codes cite `ErrorId`.

### Export / Import / Restore

| Code                              | Actor kind        | Trigger                                                                 |
|-----------------------------------|-------------------|-------------------------------------------------------------------------|
| `backup.export.enqueued`          | user              | `POST /api/admin/backup/exports` accepted (202).                        |
| `backup.export.completed`         | worker            | Export job -> `Succeeded`.                                              |
| `backup.export.failed`            | worker            | Export job -> `Failed`; `ErrorId` cited.                                |
| `backup.import.enqueued`          | user              | `POST /api/admin/backup/imports` accepted (202).                        |
| `backup.import.preflight_ready`   | worker            | Import preflight -> `PreflightReady`.                                   |
| `backup.import.preflight_failed`  | worker            | Preflight rejected; `ErrorCode` cited.                                  |
| `backup.restore.enqueued`         | user              | `POST /api/admin/backup/restores` accepted (202).                       |
| `backup.restore.applied`          | worker            | Restore apply tx committed.                                             |
| `backup.restore.rolled_back`      | worker            | Restore outer tx rolled back; `ErrorId` cited.                          |
| `backup.restore.no_return_point`  | worker            | Cancel refused past step 3 (`INV-BR-JP-6`).                             |

### Snapshot / Retention

| Code                                | Actor  | Trigger                                              |
|-------------------------------------|--------|------------------------------------------------------|
| `snapshot.create.enqueued`          | user   | Snapshot creation accepted.                          |
| `snapshot.create.completed`         | worker | Snapshot sealed.                                     |
| `snapshot.pin.set`                  | user   | Operator pin applied.                                |
| `snapshot.pin.cleared`              | user   | Operator pin removed.                                |
| `snapshot.delete.blocked_by_pin`    | user   | Delete refused with 409 `Snapshot.Pinned`.           |
| `snapshot.deleted`                  | user   | Snapshot deleted; SC-H `pinCount` decremented.       |
| `snapshot.yanked`                   | user   | Snapshot yanked.                                     |
| `snapshot.retention_swept`          | worker | Retention sweeper reclaimed rows/bytes.              |
| `snapshot.pointer_dangling`         | worker | Restore detected a dangling SC-H pointer.            |

### Jobs / Locks / Idempotency

| Code                                | Actor  | Trigger                                              |
|-------------------------------------|--------|------------------------------------------------------|
| `job.retry_scheduled`               | worker | Worker will retry after `retryAfterSeconds`.         |
| `job.failed_exhausted`              | worker | `attemptCount >= maxAttempts`; `ErrorId` cited.      |
| `job.cancelled`                     | worker | Cooperative cancel accepted.                         |
| `job.lease_expired`                 | worker | 30 s reaper flipped stale `Running -> Queued`.       |
| `backup.idempotency_hit`            | server | Replay-hit; cached response served.                  |
| `backup.idempotency_body_mismatch`  | server | Same key, different JCS `bodyHash`; 409 returned.    |
| `backup.idempotency_sweep_failed`   | worker | Sweeper failed; request path unaffected.             |
| `backup.lock_timeout`               | server | Advisory-lock acquisition timed out; 503 returned.   |

### RBAC / Roles

| Code                                | Actor | Trigger                                              |
|-------------------------------------|-------|------------------------------------------------------|
| `rbac.decision.allow`               | pep   | Capability granted (sampled per rate limit).         |
| `rbac.decision.deny`                | pep   | Capability denied (always emitted, never sampled).   |
| `rbac.policy.previewed`             | user  | Dry-run diff evaluated.                              |
| `rbac.policy.saved`                 | user  | Save committed; `policyVersion` before/after cited.  |
| `rbac.policy.version_mismatch`      | server| 409 on `If-Match`; forces `ConflictReload`.          |

Total: 29 codes. This is the closed-set audit-event catalogue for
the BR module.

---

## Metrics Registry (Prometheus)

Closed set. Every metric is exposed under prefix `lara_backup_`.
Adding a metric requires a version bump of this file. Labels
listed with each metric are the ONLY allowed labels (bounded
cardinality).

### Counters

| Name                                     | Labels                                    | Increment on                                              |
|------------------------------------------|-------------------------------------------|-----------------------------------------------------------|
| `lara_backup_jobs_enqueued_total`        | `kind`                                    | Job row insert.                                           |
| `lara_backup_jobs_succeeded_total`       | `kind`                                    | `Running -> Succeeded`.                                    |
| `lara_backup_jobs_failed_total`          | `kind`, `errorCode`                       | `Running -> Failed`.                                       |
| `lara_backup_jobs_cancelled_total`       | `kind`                                    | `Running -> Cancelled`.                                    |
| `lara_backup_jobs_retried_total`         | `kind`                                    | `retry_scheduled` audit event.                            |
| `lara_backup_idempotency_hits_total`     | `endpoint`                                | Replay-hit.                                               |
| `lara_backup_idempotency_mismatch_total` | `endpoint`                                | Body-hash mismatch.                                       |
| `lara_backup_lock_timeouts_total`        | `lockName`                                | Advisory lock acquire timeout.                            |
| `lara_backup_rbac_denies_total`          | `capability`, `role`                      | PEP deny decision.                                        |
| `lara_backup_snapshot_pin_events_total`  | `op` (`set`/`clear`)                      | Operator pin toggle.                                      |
| `lara_backup_pointer_dangling_total`     | (none)                                    | SC-H dangling pointer detected during Restore.            |

### Gauges

| Name                                        | Labels     | Meaning                                              |
|---------------------------------------------|------------|------------------------------------------------------|
| `lara_backup_jobs_queued`                   | `kind`     | Current `Queued` count.                              |
| `lara_backup_jobs_running`                  | `kind`     | Current `Running` count.                             |
| `lara_backup_active_snapshots`              | (none)     | Snapshots not in `Yanked`/`Deleted`.                 |
| `lara_backup_sc_h_pinned_bytes`             | (none)     | SC-H bytes retained solely due to snapshot pins.     |

### Histograms

Buckets are the fixed set `{0.1s, 0.5s, 1s, 5s, 30s, 60s, 300s,
900s, 1800s, 3600s}` unless noted.

| Name                                      | Labels     | Observation                                                     |
|-------------------------------------------|------------|-----------------------------------------------------------------|
| `lara_backup_job_duration_seconds`        | `kind`     | Elapsed `Queued -> terminal`.                                    |
| `lara_backup_job_apply_tx_seconds`        | `kind`     | Elapsed inside the outer apply tx (Restore).                    |
| `lara_backup_export_bytes`                | (none)     | Archive size in bytes; buckets `{1MiB..64GiB}` powers of 2.     |
| `lara_backup_sse_event_lag_seconds`       | `kind`     | Time from worker progress write to FE SSE receipt.              |

---

## Log Level and Redaction Policy

| Situation                                    | Level  | Notes                                                   |
|----------------------------------------------|--------|---------------------------------------------------------|
| RBAC deny                                    | warn   | Always emitted; never sampled.                          |
| RBAC allow                                   | debug  | Sampled at 1/100.                                       |
| Job enqueue / progress / complete            | info   | Never sampled.                                          |
| Job retry / lease expired                    | warn   | Never sampled.                                          |
| Job failed (terminal)                        | error  | `ErrorId` MUST be present.                              |
| Idempotency hit                              | info   | Never sampled.                                          |
| Idempotency body mismatch                    | warn   | Includes both hashes' short prefix (8 chars) only.      |
| Lock timeout                                 | warn   | Includes `lockName`, holder if known.                   |
| Deputy deny row change                       | warn   | Includes `policyVersion` before/after.                  |

Redactor rules (delegating to `redactor.crypto` and
`redactor.pii` from `spec/03-error-manage/`):

1. Never log manifest inner fields beyond row/byte counts.
2. Never log DEK/KEK bytes; log only `epochId` and `kekVersion`.
3. Never log SC-H bytes or storage credentials; log only
   pointer `{bucket, path, sha256Prefix8}`.
4. Never log the `note` field of a snapshot; it is user text.
5. Never log user email/phone; use `userId`.

---

## SSE Contract Alignment

Every SSE progress event MUST include, in addition to fields
defined in `15-jobs-and-progress.md`:

- `requestId` (initiator's),
- `sequence` (monotonic, per job),
- `at` (RFC 3339 timestamp, worker clock).

The FE MUST log one `lara-diag` info entry per state transition
citing `sequence`. Missing `sequence` on any transition is a bug;
the FE MUST log an error entry with `reason=sequence_missing`
and stall the transition.

---

## Invariants

| ID              | Rule |
|-----------------|------|
| INV-BR-OB-1     | Every BR log entry MUST carry `module="backup-restore"` and a `RequestId`; entries without a `RequestId` are dropped at the sink and counted under `lara_backup_log_dropped_total{reason="missing_request_id"}`. |
| INV-BR-OB-2     | `initiatingRequestId` on `backup_jobs` MUST equal the `RequestId` of the accepting request; workers MUST NOT rewrite it on retry. |
| INV-BR-OB-3     | `ErrorId` MUST be present on every terminal `failed` log AND on `backup_jobs.errorId`; a job in `Failed` with a null `errorId` is a data-integrity bug. |
| INV-BR-OB-4     | Audit event codes are the closed set defined in this file; adding a code requires a version bump here. |
| INV-BR-OB-5     | Metric names and label sets are the closed set defined in this file; new labels require a version bump here. |
| INV-BR-OB-6     | RBAC deny logs are never sampled; RBAC allow logs are sampled at 1/100 to bound volume. |
| INV-BR-OB-7     | Redactors MUST run before log emission at both the server sink and the FE `lara-diag` pipe; a log line containing `redactor.violation=true` is a test failure. |
| INV-BR-OB-8     | SSE events MUST carry `requestId` AND `sequence`; the FE MUST NOT transition without both. |
| INV-BR-OB-9     | Log level policy MUST match the table in this file; a `warn` emitted at `info` level is a lint failure enforced by the log-level linter cited in `spec/coding-guidelines/`. |
| INV-BR-OB-10    | `RequestId` on retries of the same job is preserved verbatim; a mismatch between `initiatingRequestId` and the current retry's log context is a test failure. |

---

## Cross-References

- [`spec/03-error-manage/`](../03-error-manage/00-overview.md): base `RequestId`/`ErrorId`/`ErrorCode` chain, redactor implementations.
- [`15-jobs-and-progress.md`](./15-jobs-and-progress.md): job schema, SSE event shape.
- [`16-idempotency-and-locks.md`](./16-idempotency-and-locks.md): sources of `idempotency_hit`/`body_mismatch`/`lock_timeout`.
- [`02-casbin-integration.md`](./02-casbin-integration.md): source of `rbac.decision.allow`/`deny`.
- [`18-fe-export-flow.md`](./18-fe-export-flow.md), [`19-fe-import-flow.md`](./19-fe-import-flow.md), [`20-fe-snapshots-flow.md`](./20-fe-snapshots-flow.md), [`21-fe-roles-and-casbin-ui.md`](./21-fe-roles-and-casbin-ui.md): FE emitters of the `lara-diag` shape defined here.
