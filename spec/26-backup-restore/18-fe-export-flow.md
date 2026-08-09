# FE Export Flow

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`fe-export` · `state-chart` · `sse` · `progress-event` · `download-ready` · `retry-after` · `state-forbidden` · `state-offline` · `state-pending`

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

Route anchor `RA-BR-2` (`/admin/backup/export`, file
`src/routes/_authenticated/admin.backup.export.tsx`) hosts the export
state chart. This file pins the FE state machine, the copy dictionary,
the empty/loading/error surfaces, the SSE reconnect discipline, and the
download-ready contract. Steps 20..22 mirror this shape for import,
snapshots, and roles.

## Non-goals

- Server contract (owned by `11-endpoint-export.md` and `15-jobs-and-progress.md`).
- Capability guard (owned by `17-fe-routes.md`, invariant `INV-BR-FE-1`).
- Global error envelope (owned by `spec/03-error-manage/`).

---

## FE State Chart

Five FE-visible states plus one transient submission state. States are
derived from the server job state (`Queued`, `Running`, `Succeeded`,
`Failed`, `Cancelled` per `15-jobs-and-progress.md`) and never from
local optimistic guesses.

```text
             +-------------+
             |   Idle      |  no active job, form editable
             +------+------+
                    | Submit()
                    v
             +-------------+
             | Submitting  |  POST /api/admin/backup/exports pending
             +------+------+
              202   |         4xx/5xx -> Failed(local)
                    v
             +-------------+
             |  Queued     |  server state=Queued, sequence=0
             +------+------+
                    | first progressEvent
                    v
             +-------------+
             |  Running    |  server state=Running, sequence>0
             +------+------+
        state=Succeeded | state=Failed | state=Cancelled
                    v                v                 v
        +--------------------+  +-----------+   +-------------+
        |  DownloadReady     |  |  Failed   |   |  Cancelled  |
        +---------+----------+  +-----------+   +-------------+
                  | user clicks Download or Reset -> Idle
                  v
             +-------------+
             |   Idle      |
             +-------------+
```

Transitions are ONLY driven by:

1. The 202 response to the POST (Idle -> Queued).
2. Progress events consumed via SSE (Queued -> Running -> terminal).
3. User action from a terminal state (Reset).
4. Capability revocation (any -> Forbidden; see `INV-BR-FE-1`).

No timer-based transition. No `setState(Running)` before the first
progress event arrives.

## Endpoint Wiring

- **Submit:** `POST /api/admin/backup/exports` with `Idempotency-Key`
  minted per submission via `crypto.randomUUID()` (charset conforms to
  `16-idempotency-and-locks.md`). On 409 `IdempotencyBodyMismatch`, FE
  MUST surface the envelope message verbatim and MUST NOT auto-retry.
- **Poll fallback:** `GET /api/admin/backup/jobs/{jobId}` at 5 s cadence
  when SSE is unavailable or `EventSource.readyState === CLOSED` after
  three reconnect attempts.
- **SSE:** `EventSource('/api/admin/backup/jobs/{jobId}/events')` with
  `Last-Event-ID` header set from the last observed `sequence`. FE MUST
  drop any event whose `sequence <= lastSequence` and MUST close and
  reconnect on any `sequence` gap.
- **Download:** `Succeeded` result payload MUST contain
  `downloadUrl`, `expiresAt`, `sizeBytes`, `sha256`. The download button
  MUST call `window.open(downloadUrl, '_self')` and MUST NOT proxy the
  bytes through client-side fetch (memory blow-up risk).

## Retry-After Discipline

- On 429 or 503 during submit, FE MUST honour `Retry-After` via
  `useSubmitLock` (`src/hooks/use-submit-lock.ts` per Plan 11) and
  MUST disable the submit button for exactly that many seconds. Zero
  or negative values fall back to 30 s.
- Auto-retry is disabled by default. The Submit button re-enables
  after the lock expires; the user re-clicks.

## Copy Dictionary

Copy strings live in `src/i18n/backup-export.json` and are referenced
by key only. This file pins the keys and the default English strings.

| Key | English | Where |
|-----|---------|-------|
| `export.title` | `Export backup` | Page H1 |
| `export.form.scope.legend` | `Scope` | Fieldset legend |
| `export.form.note.label` | `Note (optional)` | Input label |
| `export.form.submit` | `Start export` | Submit button (Idle) |
| `export.form.submitting` | `Starting...` | Submit button (Submitting) |
| `export.state.queued.title` | `Queued` | Status card |
| `export.state.queued.body` | `Waiting for a worker.` | Status card body |
| `export.state.running.title` | `Running` | Status card |
| `export.state.running.body` | `Phase: {phase}. {percent}% complete.` | Status card body |
| `export.state.downloadReady.title` | `Ready to download` | Status card |
| `export.state.downloadReady.button` | `Download archive` | CTA |
| `export.state.failed.title` | `Export failed` | Status card |
| `export.state.failed.body` | `{errorCode}: {message}` | Uses envelope `Attributes.Message` |
| `export.state.cancelled.title` | `Cancelled` | Status card |
| `export.action.reset` | `Start new export` | Reset button on terminal states |

No hard-coded strings in components. `check-magic-literals.py` treats
this file as the sole allow-list for export FE strings.

## Surface States

Route shell delegates non-happy surfaces to shared components from
`spec/24-*`:

- **Forbidden:** `<StateForbidden capability="Backup.Export" />` when
  `useCapability('Backup.Export')` returns `false`. Never mount the
  submit form; never issue `GET /api/admin/backup/jobs`.
- **Offline:** `<StateOffline />` when `navigator.onLine === false`
  OR the last three SSE reconnects failed with network errors. Submit
  button disabled; poll paused.
- **Pending:** `<StatePending />` while the initial
  `useSuspenseQuery(jobsList)` is in flight. Never render the form
  behind a spinner overlay.
- **Empty:** No active job, no history: render the form with a small
  hint referring to `export.title`; do NOT hide the form.

## Observability

Every state transition emits one `lara-diag` entry at level `info`
with `RequestId`, `jobId` (when known), `fromState`, `toState`, and
`sequence`. Terminal `Failed` transitions log at `warn` and include
`ErrorId` from the envelope (`spec/03-error-manage/`).

FE MUST NOT swallow SSE parse errors: a malformed event closes the
`EventSource` and logs one `warn` entry, then the reconnect timer
runs; three consecutive parse failures escalate to `Offline` surface.

## Invariants

- **INV-BR-FE-EX-1:** FE transitions to `Running` only after the first
  progress event with `sequence >= 1` is received; the 202 alone is
  insufficient.
- **INV-BR-FE-EX-2:** Progress events with non-monotonic `sequence`
  are dropped; a gap forces an SSE close and reconnect with
  `Last-Event-ID` set to the last observed `sequence`.
- **INV-BR-FE-EX-3:** `Retry-After` on 429/503 disables submit for the
  header value in seconds via `useSubmitLock`; no auto-retry.
- **INV-BR-FE-EX-4:** Download uses `downloadUrl` from the terminal
  event; bytes never flow through client JS memory.
- **INV-BR-FE-EX-5:** Every state transition logs one `lara-diag`
  entry with `RequestId` and `sequence`; `Failed` includes `ErrorId`.
- **INV-BR-FE-EX-6:** All user-visible strings resolve through
  `src/i18n/backup-export.json`; no inline literals.

---

## References

- `spec/26-backup-restore/11-endpoint-export.md`
- `spec/26-backup-restore/15-jobs-and-progress.md`
- `spec/26-backup-restore/16-idempotency-and-locks.md`
- `spec/26-backup-restore/17-fe-routes.md` (RA-BR-2)
- `spec/03-error-manage/`
- `spec/24-*` (StateForbidden, StateOffline, StatePending)
