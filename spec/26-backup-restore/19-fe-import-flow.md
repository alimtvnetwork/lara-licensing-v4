# FE Import Flow

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`fe` · `import` · `state-chart` · `preflight` · `confirm-diff` · `apply` · `conflict-policy` · `retry-after` · `sse` · `copy-dictionary`

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

Pin the FE state chart and endpoint wiring for the Import wizard
hosted at route anchor `RA-BR-3` (`admin.backup.import.tsx`) from
[`17-fe-routes.md`](./17-fe-routes.md). The wizard consumes the
producer contracts pinned in
[`12-endpoint-import.md`](./12-endpoint-import.md) (upload +
preflight) and delegates the apply phase to
[`14-endpoint-restore.md`](./14-endpoint-restore.md). Import differs
from Export in three shape-specific ways this file MUST govern: (a)
an ingest step that either PUTs bytes to a presigned URL or streams
multipart, (b) a synchronous preflight report the user MUST confirm
before any DB tx opens (`INV-BR-MS-1`), and (c) a conflict-policy
selector that binds the Restore body (`abortOnAny`, `preserveLive`,
`overwriteFromSource`). This file mirrors the shape of
[`18-fe-export-flow.md`](./18-fe-export-flow.md) so the two wizards
share observability, `Retry-After`, and surface-state discipline.

---

## Route

- Anchor: `RA-BR-3` from `17-fe-routes.md`.
- File: `src/routes/_authenticated/admin.backup.import.tsx`.
- Guard: `useCapability('Backup.Import')` MUST resolve `true`
  before any BR query mounts; on `false` render `<StateForbidden>`
  and log one `lara-diag` warn entry with `RequestId`, `capability`,
  `UserId`.

---

## State Chart

Eight states. Transitions are driven only by (a) 202 responses from
Import / Restore, (b) SSE progress events with monotonic
`sequence`, or (c) explicit user actions on the wizard. No
optimistic transitions.

```
                        ┌──────────────────────────────────────────┐
                        v                                          │
[Idle] --pickSource--> [Uploading] --202 preflight job--> [Preflighting]
                            │                                      │
                            │                                      v
                            │                              [PreflightReady]
                            │                                      │
                            │                     confirmApply     │
                            │                            ┌─────────┤
                            v                            v         │
                        [Failed] <----error----- [Submitting]      │
                            ^                            │         │
                            │                            v         │
                            └──────- error ------- [Applying] -----┘
                            ^                            │
                            │                            v
                            └------ error ------- [Succeeded]
                                                        │
                                                        v
                                                  [Cancelled] (Applying only, pre step 3)
```

Legal states: `Idle`, `Uploading`, `Preflighting`, `PreflightReady`,
`Submitting`, `Applying`, `Succeeded`, `Failed`, `Cancelled`.

Legal transitions:

| From             | Event                                | To                |
|------------------|--------------------------------------|-------------------|
| Idle             | user picks archive source            | Uploading         |
| Uploading        | ingest 2xx + 202 preflight enqueued  | Preflighting      |
| Uploading        | 4xx/5xx / abort                      | Failed            |
| Preflighting     | SSE `preflight.completed`            | PreflightReady    |
| Preflighting     | SSE `error` OR terminal poll failure | Failed            |
| PreflightReady   | user clicks `Apply`                  | Submitting        |
| PreflightReady   | user clicks `Discard`                | Idle              |
| Submitting       | Restore 202                          | Applying          |
| Submitting       | 409 conflict / 4xx                   | PreflightReady    |
| Submitting       | 5xx after `Retry-After` window       | Failed            |
| Applying         | SSE `apply.completed`                | Succeeded         |
| Applying         | SSE `error`                          | Failed            |
| Applying         | user Cancel BEFORE Restore step 3    | Cancelled         |

Any other transition is a bug; the FE MUST log a `lara-diag` error
entry with `RequestId` and current `sequence`, then remain in the
current state.

---

## Endpoint Wiring

| Wizard step         | Endpoint / channel                                              |
|---------------------|-----------------------------------------------------------------|
| Presign request     | `POST /api/admin/backup/imports/presign` (mode selection)       |
| Ingest bytes        | Presigned PUT (S3-compatible) OR `multipart/form-data` to import|
| Preflight submit    | `POST /api/admin/backup/imports` (mode `verifyOnly`)            |
| Preflight progress  | SSE `/api/admin/backup/jobs/{id}/events` with `Last-Event-ID`   |
| Apply submit        | `POST /api/admin/backup/restores` (source `{kind:'archive'}`)   |
| Apply progress      | SSE `/api/admin/backup/jobs/{id}/events`                        |
| Cancel              | `POST /api/admin/backup/jobs/{id}/cancel` (Applying only)       |

Rules:

1. `Idempotency-Key` MUST be minted per submission (UUIDv7) for
   both the Import preflight POST and the Restore apply POST.
   Re-submission of the same wizard step re-uses the same key so
   accidental double-click hits the replay path from
   [`16-idempotency-and-locks.md`](./16-idempotency-and-locks.md).
2. SSE reconnect MUST send `Last-Event-ID` equal to the last
   observed `sequence`. Three failed reconnects switch to 5 s poll
   on `GET /api/admin/backup/jobs/{id}` per
   [`15-jobs-and-progress.md`](./15-jobs-and-progress.md).
3. `Retry-After` on any 429/503 MUST be honored by
   `useSubmitLock`; no auto-retry.
4. Multipart ingest is only offered when the picked file is
   `<= 100 MiB`; larger files force the presigned path.

---

## Preflight Report

`PreflightReady` renders a read-only report bound to the preflight
job result payload from `12-endpoint-import.md`. Required fields:

- Manifest version, produced-at, source shard summary.
- Scope classes present (SC-A..H) with row / byte counts.
- Epoch resolvability status for `INV-BR-EK-2` and
  `INV-BR-FS-4`; if any KEK epoch is Purged the report renders in a
  destructive style and the `Apply` action is disabled.
- Conflict-policy selector, default `abortOnAny`. The three
  options bind the Restore body one-to-one.
- Optional per-scope opt-out toggles for subset apply.

The user MUST tick a confirmation checkbox `"I have reviewed the
preflight report."` before `Apply` becomes enabled. No exceptions.

---

## Copy Dictionary

The Import wizard MUST source every user-visible string from
`src/i18n/backup-import.json`. Keys are the sole allow-list; no
inline literals in TSX. Minimum 18 keys:

`import.title`, `import.source.file`, `import.source.presigned`,
`import.upload.progress`, `import.upload.failed`,
`import.preflight.pending`, `import.preflight.ready`,
`import.preflight.epochPurged`, `import.conflict.abortOnAny`,
`import.conflict.preserveLive`, `import.conflict.overwriteFromSource`,
`import.apply.cta`, `import.apply.confirmCheckbox`,
`import.applying.status`, `import.succeeded.banner`,
`import.failed.banner`, `import.cancel.cta`,
`import.cancel.past_no_return_point`.

---

## Surface State Delegation

- `<StateForbidden>` on capability denial (see Route section).
- `<StateOffline>` when the SSE channel is closed AND three polls
  in a row return network errors.
- `<StatePending>` during `Uploading`, `Preflighting`, `Submitting`,
  and `Applying`. Component identity MUST change per state so
  screen readers announce the new phase.
- `<StateCard variant="destructive">` for `Failed`, showing
  `ErrorCode`, `ErrorId`, `RequestId`, and the copy-dictionary
  message. Never render the raw `Attributes.debug` field even if
  present.

---

## Observability

Every transition MUST emit exactly one `lara-diag` entry with
level `info` (or `warn` for retry-eligible 4xx, `error` for
`Failed`) containing:

- `RequestId` from the triggering response.
- `sequence` (last observed) for SSE-driven transitions.
- `ErrorId` and `ErrorCode` on `Failed`.
- `policy` field on Apply submissions
  (`abortOnAny|preserveLive|overwriteFromSource`).

No PII, no archive bytes, no manifest inner fields beyond counts
per `redactor.crypto` and `redactor.pii` from
[`spec/03-error-manage/`](../03-error-manage/00-overview.md).

---

## Invariants

| ID              | Rule |
|-----------------|------|
| INV-BR-FE-IM-1  | The `Apply` control is disabled until the confirmation checkbox is ticked AND preflight state is `PreflightReady` AND no epoch is Purged. |
| INV-BR-FE-IM-2  | `Idempotency-Key` is minted once per submission attempt (UUIDv7) and re-used verbatim on retries of that same attempt. |
| INV-BR-FE-IM-3  | The FE MUST NOT transition `Applying -> Succeeded` on a Restore 202 alone; only an SSE `apply.completed` event with matching `jobId` and monotonic `sequence` triggers the transition. |
| INV-BR-FE-IM-4  | On SSE `sequence` gap the FE MUST reconnect with `Last-Event-ID` = last observed sequence before resuming state updates. |
| INV-BR-FE-IM-5  | 409 `restore_past_no_return_point` from Cancel MUST leave the wizard in `Applying` and render `import.cancel.past_no_return_point`. |
| INV-BR-FE-IM-6  | All strings are sourced from `src/i18n/backup-import.json`; inline literals in TSX are a lint failure. |
| INV-BR-FE-IM-7  | `Retry-After` is honored via `useSubmitLock`; the FE MUST NOT auto-retry preflight or apply submissions. |
| INV-BR-FE-IM-8  | Ingest byte streams are never held in JS memory; the presigned PUT reads from the picked `File` via streaming and multipart mode is bounded to <= 100 MiB. |

---

## Cross-References

- [`12-endpoint-import.md`](./12-endpoint-import.md): producer contract for preflight + upload modes.
- [`14-endpoint-restore.md`](./14-endpoint-restore.md): apply-phase source discriminator and conflict policy.
- [`15-jobs-and-progress.md`](./15-jobs-and-progress.md): SSE + poll surface, cancel semantics.
- [`16-idempotency-and-locks.md`](./16-idempotency-and-locks.md): key format, replay matrix.
- [`17-fe-routes.md`](./17-fe-routes.md): route anchor + capability guard contract.
- [`18-fe-export-flow.md`](./18-fe-export-flow.md): sibling wizard shape.
