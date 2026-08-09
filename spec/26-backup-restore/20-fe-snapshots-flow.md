# FE Snapshots Flow

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`fe` · `snapshots` · `list` · `create` · `pin` · `restore` · `delete` · `retention-badge` · `pin-count` · `sc-h-pointer` · `state-chart`

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

Pin the FE state charts and endpoint wiring for the Snapshots pages
hosted at route anchors `RA-BR-4` (`admin.snapshots.tsx`, list) and
`RA-BR-5` (`admin.snapshots.$snapshotId.tsx`, detail) from
[`17-fe-routes.md`](./17-fe-routes.md). The pages consume producer
contracts from [`13-endpoint-snapshot.md`](./13-endpoint-snapshot.md)
and delegate the restore-from-snapshot sub-flow to
[`14-endpoint-restore.md`](./14-endpoint-restore.md), re-using the
preflight-confirmation gate defined in
[`19-fe-import-flow.md`](./19-fe-import-flow.md). Without this file,
the pin-count contract (`INV-BR-EP-SN-6` dangling SC-H pointer) has
no FE mitigation, the delete-while-pinned refusal has no client UX,
and retention badges have no derivation rule.

---

## Routes

- List anchor `RA-BR-4`, file `src/routes/_authenticated/admin.snapshots.tsx`, capability `Snapshot.View`.
- Detail anchor `RA-BR-5`, file `src/routes/_authenticated/admin.snapshots.$snapshotId.tsx`, capability `Snapshot.View`.
- Create control gated additionally by `Snapshot.Create`.
- Delete control gated additionally by `Snapshot.Delete`.
- Restore control gated additionally by `Backup.Restore`.
- Pin/Unpin controls gated additionally by `Snapshot.Pin`.

Guard order: `useCapability(cap)` MUST resolve `true` before any BR
query mounts; on `false` render `<StateForbidden>` and log one
`lara-diag` warn entry with `RequestId`, `capability`, `UserId`.

---

## List Page State Chart (`RA-BR-4`)

Five states driven by `GET /api/admin/snapshots` (paged) and the
optional create-drawer sub-flow.

```
[Loading] --2xx--> [Ready] --openCreate--> [Creating] --202--> [Ready+PendingRow]
    │                 │                        │
    │                 │                        └--4xx/5xx--> [Ready+CreateError]
    │                 └--empty result--> [Empty]
    └--5xx / offline--> [Failed]
```

Legal states: `Loading`, `Ready`, `Empty`, `Creating`,
`Ready+PendingRow`, `Ready+CreateError`, `Failed`.

Rules:

1. Paging MUST be server-driven; the FE never accumulates the full
   set in memory.
2. `Ready+PendingRow` renders the newly created row with a
   `<StatePending>` marker until the SSE `snapshot.completed` event
   flips it to `Sealed`; no optimistic Sealed state.
3. The create drawer submits to `POST /api/admin/snapshots` with a
   per-submission UUIDv7 `Idempotency-Key`; label collision (409
   `Validation.Failed`) surfaces inline on the label field without
   closing the drawer.

---

## Detail Page State Chart (`RA-BR-5`)

Six states driven by `GET /api/admin/snapshots/{id}` and the
pin/delete/restore sub-flows.

```
[Loading] --2xx--> [Ready] ----restore---> [RestoreWizard] (delegates to 19-fe-import-flow.md)
    │                 │
    │                 ├--pinToggle-->  [PinPending]  --2xx--> [Ready]
    │                 ├--delete-----> [DeleteConfirm] --confirm--> [Deleting] --2xx--> [Gone]
    │                 │                                                       --409 pinned--> [Ready+DeleteBlocked]
    │                 └--yank------->  [YankConfirm]  --confirm--> [Yanking]  --2xx--> [Ready(Yanked)]
    └--404 / 5xx--> [Failed]
```

Legal states: `Loading`, `Ready`, `PinPending`, `DeleteConfirm`,
`Deleting`, `Ready+DeleteBlocked`, `YankConfirm`, `Yanking`,
`Ready(Yanked)`, `RestoreWizard`, `Gone`, `Failed`.

---

## Endpoint Wiring

| Action              | Endpoint / channel                                                        |
|---------------------|---------------------------------------------------------------------------|
| List                | `GET /api/admin/snapshots?cursor=...&limit=...`                            |
| Detail              | `GET /api/admin/snapshots/{id}`                                            |
| Create              | `POST /api/admin/snapshots` (`Idempotency-Key` REQUIRED)                   |
| Create progress     | SSE `/api/admin/backup/jobs/{id}/events` with `Last-Event-ID`              |
| Pin / Unpin         | `POST /api/admin/snapshots/{id}/pin` and `.../unpin` (`Snapshot.Pin`)      |
| Yank                | `POST /api/admin/snapshots/{id}/yank` (`Snapshot.Yank`)                    |
| Delete              | `DELETE /api/admin/snapshots/{id}` (`Snapshot.Delete`)                     |
| Restore             | `POST /api/admin/backup/restores` with `source={kind:'snapshot',snapshotId}` |

Rules:

1. Every mutating action mints a per-submission UUIDv7
   `Idempotency-Key`; the same key is re-used on retry of the same
   attempt (per `16-idempotency-and-locks.md`).
2. `Retry-After` on 429/503 is honored via `useSubmitLock`; no
   auto-retry.
3. The delete request MUST NOT be issued until the user confirms
   in the `DeleteConfirm` state; a 409 `Snapshot.Pinned` response
   transitions to `Ready+DeleteBlocked` with copy explaining the
   pin holder.

---

## Retention Badges

Each row in the list AND the detail header renders exactly one
retention badge derived from the snapshot row's `retention.policy`
and `sealedAt`:

| Policy                     | Badge shape                            | Derivation |
|----------------------------|----------------------------------------|------------|
| `keepDays`                 | `Expires in Nd` (green) / `Ndh` (amber < 48h) | `sealedAt + keepDays - now()` |
| `keepCount`                | `Rank K/N` (neutral)                   | server-provided rank field    |
| `keepUntilExplicitDelete`  | `Manual` (neutral, dashed border)      | policy string                 |
| Pinned (any policy)        | Overlay `Pinned` chip (destructive-outline) | `pinCount > 0` per operator pin, distinct from SC-H `pinCount` |
| Yanked                     | Overlay `Yanked` chip (destructive)    | `yankedAt IS NOT NULL`        |

Badges MUST NOT be computed from client clock alone; server
provides `expiresAt` explicitly and the FE only formats it. Clock
skew MUST NOT cross the amber boundary silently.

---

## Restore Sub-Flow

Clicking `Restore` on the detail page opens the same wizard shape
as [`19-fe-import-flow.md`](./19-fe-import-flow.md) starting at
state `PreflightReady` (no upload phase): the wizard submits
`POST /api/admin/backup/imports` with `source={kind:'snapshot',snapshotId}`
in `verifyOnly` mode, renders the preflight report, requires the
confirmation checkbox, and then submits Restore. All invariants
from `19-fe-import-flow.md` (`INV-BR-FE-IM-1..8`) apply verbatim.

Rationale: Restore is a Restore, whether source is archive or
snapshot; the preflight-confirmation gate MUST NOT be bypassed on
the snapshot path.

---

## Copy Dictionary

All strings sourced from `src/i18n/backup-snapshots.json`. Minimum
20 keys:

`snapshots.title`, `snapshots.empty`, `snapshots.column.label`,
`snapshots.column.sealedAt`, `snapshots.column.expiresAt`,
`snapshots.column.retention`, `snapshots.badge.pinned`,
`snapshots.badge.yanked`, `snapshots.badge.manual`,
`snapshots.create.cta`, `snapshots.create.labelCollision`,
`snapshots.pin.cta`, `snapshots.unpin.cta`,
`snapshots.pin.pending`, `snapshots.yank.cta`,
`snapshots.yank.confirmTitle`, `snapshots.delete.cta`,
`snapshots.delete.confirmTitle`, `snapshots.delete.blockedByPin`,
`snapshots.restore.cta`.

Inline literals in TSX are a lint failure.

---

## Surface State Delegation

- `<StateForbidden>` on capability denial for the whole page or
  per-action for finer-grained caps.
- `<StateOffline>` when list poll fails three times in a row AND
  SSE is closed.
- `<StatePending>` for `Creating`, `PinPending`, `Deleting`,
  `Yanking`.
- `<StateCard variant="destructive">` for `Failed` and for
  `Ready+DeleteBlocked` (with copy `snapshots.delete.blockedByPin`).
- `<StateCard variant="empty">` for `Empty`.

---

## Observability

Every transition MUST emit exactly one `lara-diag` entry with:

- `RequestId` from the triggering response.
- `sequence` on SSE-driven create transitions.
- `snapshotId` on all mutating actions.
- `action` field one of `create|pin|unpin|yank|delete|restore`.
- `ErrorId` + `ErrorCode` on failures.

Do NOT log the `note` field or any SC-H pointer contents. Retain
`redactor.crypto` and `redactor.pii` behavior from
[`spec/03-error-manage/`](../03-error-manage/00-overview.md).

---

## Invariants

| ID              | Rule |
|-----------------|------|
| INV-BR-FE-SN-1  | The list page never renders an optimistic `Sealed` row; a newly created row stays in `<StatePending>` until SSE `snapshot.completed` arrives. |
| INV-BR-FE-SN-2  | `expiresAt` MUST be sourced from the server response; the FE only formats it. Client clock is NEVER used to compute retention. |
| INV-BR-FE-SN-3  | 409 `Snapshot.Pinned` on delete MUST transition to `Ready+DeleteBlocked` and render `snapshots.delete.blockedByPin`; the FE MUST NOT auto-unpin. |
| INV-BR-FE-SN-4  | The Restore sub-flow re-uses `19-fe-import-flow.md`'s preflight-confirmation gate; direct submission of Restore without a `PreflightReady` state is a lint/type failure. |
| INV-BR-FE-SN-5  | Every mutating action mints a per-submission UUIDv7 `Idempotency-Key`, re-used verbatim on retries of the same attempt. |
| INV-BR-FE-SN-6  | Capability guards are evaluated per action; a user with `Snapshot.View` but without `Snapshot.Delete` sees the delete control rendered disabled with `<StateForbidden>` tooltip, never hidden silently. |
| INV-BR-FE-SN-7  | All strings sourced from `src/i18n/backup-snapshots.json`; inline literals in TSX are a lint failure. |
| INV-BR-FE-SN-8  | Pagination is server-driven; the FE never accumulates full result sets in memory across pages. |

---

## Cross-References

- [`13-endpoint-snapshot.md`](./13-endpoint-snapshot.md): producer contract, SC-H pin semantics, retention policies.
- [`14-endpoint-restore.md`](./14-endpoint-restore.md): source discriminator for `{kind:'snapshot'}`.
- [`15-jobs-and-progress.md`](./15-jobs-and-progress.md): SSE `snapshot.completed` event.
- [`16-idempotency-and-locks.md`](./16-idempotency-and-locks.md): key format and lock registry (`snapshots.label:<shardId>:<label>`).
- [`17-fe-routes.md`](./17-fe-routes.md): route anchors, capability guards.
- [`19-fe-import-flow.md`](./19-fe-import-flow.md): preflight-confirmation gate re-used by the Restore sub-flow.
