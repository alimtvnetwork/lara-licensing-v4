# Secrets Forward Secrecy

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`forward-secrecy` · `epoch` · `kek-rotation` · `re-seal` · `retention` · `secrets-provider` · `sc-g` · `key-lifecycle` · `rbac-keyrotate`

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

Pin the operational policy that makes `INV-BR-C` (forward secrecy)
and `INV-BR-EK-5` (Restore re-seals under the current epoch KEK)
enforceable in practice. Specifically: when a new epoch is minted,
how long historical epochs are retained for Import, what happens
when an Import archive's `epoch` predates the retention window, and
which capability from
[`03-permission-matrix.md`](./03-permission-matrix.md) authorises a
rotation. Without this policy, `INV-BR-C` is aspirational and every
endpoint (11-14) has an undefined failure mode for stale archives.

Scope class binding: this file governs `SC-G` (secrets envelope)
from [`05-scope-catalog.md`](./05-scope-catalog.md) and every KEK
lifecycle event; content encryption primitives are already pinned in
[`09-encryption-and-keys.md`](./09-encryption-and-keys.md).

---

## Epoch Lifecycle

An epoch is a monotonic integer identifying one Root KEK. States:

```
Draft -> Active -> Retired -> Purged
```

| State     | Meaning                                                                                       | Duration              |
|-----------|-----------------------------------------------------------------------------------------------|-----------------------|
| Draft     | KEK minted, not yet activated; SecretsProvider holds it but no seal or unseal uses it.        | Minutes to 1 hour     |
| Active    | Exactly one epoch is Active at any time; every new archive seals under it.                    | 90 days (default)     |
| Retired   | KEK is unseal-only; new archives never seal under it, but old archives can still be Imported. | 365 days (default)    |
| Purged    | KEK material is destroyed; archives referencing this epoch fail Import with `BackupCorrupt`.  | Terminal              |

Invariant: at every wall-clock instant, exactly one epoch is
Active, at most `retentionCount = 4` epochs are Retired, and every
older epoch is Purged. Defaults are tunable via
`config('backup.forward_secrecy')` but the shape is fixed.

---

## Rotation Triggers

Rotation moves the Active KEK to Retired and promotes a Draft KEK
to Active. Triggers, in priority order:

1. **On-compromise** (operator-initiated via `Rbac.KeyRotate` capability): immediate rotation, retention window unchanged, audit row `backup.kek_rotated` with `reason=compromise`.
2. **Scheduled** (default 90 days from `activatedAt`): a cron job under `<spec-placeholder file="15-jobs-and-progress.md" />` mints the next Draft, waits for a smoke Export+Import cycle to pass, then promotes.
3. **On-Restore of an Import produced under a Retired epoch older than `warnAfterEpochs = 2`**: emits `Rbac.KeyRotate` reminder into the audit log; does NOT auto-rotate.

Rotation never happens as a side effect of a normal Export, Import, Snapshot, or Restore; the only mutators are the cron job and an explicit `Rbac.KeyRotate` API call.

---

## Re-Seal Timing (`SC-G` slot)

For every Restore (`14-endpoint-restore.md`), re-seal MUST happen
in this exact order (already promised by `INV-BR-EK-5`; this file
pins the operational rules around it):

1. Unseal archive DEK using the KEK identified by `manifest.encryption.epoch` + `kid`. If that KEK is Purged, fail with `BackupCorrupt` and `Attributes.Error.Details.reason = "epoch_purged"`.
2. If `manifest.encryption.epoch < activeEpoch`, mark the Restore's `reSealPlanned = true` in the job payload.
3. Restore `SC-A..F` under the archive's DEK-content.
4. **Restore `SC-G` with per-row re-seal**: each secret row is decrypted under the archive's DEK-content, re-encrypted under the Active epoch KEK, and only then persisted. No secret row is ever written to storage in cleartext or under a non-Active KEK.
5. Re-seal the archive-level DEK under the Active epoch KEK for the restored `SC-G` slot (in-memory manifest copy only; the on-disk archive is not modified).
6. Restore `SC-H` bodies: decrypt under archive DEK, re-encrypt under Active epoch KEK, persist.
7. Emit audit row `backup.reseal_completed` with `fromEpoch`, `toEpoch`, and count of SC-G rows re-sealed.

Any failure between step 4 and step 7 rolls back the DB transaction and re-queues the Restore job with the same idempotency key; partial re-seals are impossible because SC-G writes and SC-H writes both live inside one outer transaction (`INV-BR-A`).

---

## Retention Window

Defaults, all pinned in `config('backup.forward_secrecy')`:

| Setting              | Default | Meaning                                                                    |
|----------------------|---------|----------------------------------------------------------------------------|
| `activeMaxDays`      | 90      | Wall-clock max time an epoch stays Active before scheduled rotation.       |
| `retiredMaxDays`     | 365     | Wall-clock max time a Retired KEK stays available for Import unseal.       |
| `retentionCount`     | 4       | Max concurrent Retired epochs; oldest is Purged when limit exceeded.       |
| `warnAfterEpochs`    | 2       | Import older than N epochs behind Active emits a Rotate reminder.          |
| `purgeGracePeriod`   | 24h     | Delay between Retired -> Purged transition and material destruction.       |

Import of an archive whose `epoch` is Purged fails with:

- HTTP 422 (`BackupCorrupt`, closed-set error code from `18-error-codes.md`).
- `Attributes.Error.Details.reason = "epoch_purged"`.
- `Attributes.Error.Details.epoch = <archive epoch>`.
- No key material logged; `redactor.crypto` from `INV-BR-EK-6` strips every base64 field.

---

## Failure Modes

| Trigger                                                     | HTTP | Error code       | Details                                          |
|-------------------------------------------------------------|------|------------------|--------------------------------------------------|
| Import archive under a Purged epoch                         | 422  | `BackupCorrupt`  | `reason=epoch_purged`, `epoch`                   |
| Rotation attempted without `Rbac.KeyRotate` capability      | 403  | `Rbac.Denied`    | `capability=Rbac.KeyRotate`                      |
| Rotation attempted while another rotation is in-flight      | 409  | `RestoreConflict`| `reason=rotation_in_progress`                    |
| Draft KEK smoke test fails (Export + Import round-trip)     | 500  | `BackupCorrupt`  | `reason=draft_smoke_failed`; Draft is destroyed  |
| SC-G re-seal fails mid-Restore                              | 500  | `BackupCorrupt`  | rollback outer tx, requeue with same idempotency |

Every failure emits `lara.exception` with `ErrorId`, `RequestId`, `fromEpoch`, `toEpoch`, and audit-log correlation ID; no key bytes ever appear in logs.

---

## Audit Rows

Emitted to the audit trail (in-scope under `SC-F` domain tables via `<spec-placeholder file="17-audit-and-observability.md" />`):

| Event                       | Payload                                              |
|-----------------------------|------------------------------------------------------|
| `backup.kek_minted`         | `epoch`, `kid`, `state=Draft`, actor                 |
| `backup.kek_activated`      | `epoch`, `kid`, `previousEpoch`                      |
| `backup.kek_retired`        | `epoch`, `kid`, `retiredAt`                          |
| `backup.kek_purged`         | `epoch`, `kid`, `purgedAt`                           |
| `backup.reseal_completed`   | `archiveId`, `fromEpoch`, `toEpoch`, `rowsResealed`  |
| `backup.rotate_reminder`    | `archiveEpoch`, `activeEpoch`, `restoreJobId`        |

---

## Invariants (`INV-BR-FS-1..6`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next
edit of that file.

| ID              | Statement                                                                                                                          |
|-----------------|------------------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-FS-1`   | Exactly one epoch is Active at every wall-clock instant; new archives seal only under the Active KEK.                              |
| `INV-BR-FS-2`   | Retired KEKs are unseal-only; any code path attempting to seal under a non-Active KEK fails at the SecretsProvider boundary.       |
| `INV-BR-FS-3`   | Rotation is authorised exclusively by the `Rbac.KeyRotate` capability; scheduled rotation is executed by a service principal with that capability. |
| `INV-BR-FS-4`   | Purged epochs are unrecoverable; Import of an archive under a Purged epoch fails with `BackupCorrupt` and never touches storage.   |
| `INV-BR-FS-5`   | Every Restore that unseals under a non-Active epoch performs SC-G per-row re-seal + archive DEK re-seal before the outer tx commits.|
| `INV-BR-FS-6`   | Draft KEKs are promoted to Active only after a successful smoke Export+Import round-trip; failed smoke destroys the Draft material.|

---

## Cross-References

- Parent: [`09-encryption-and-keys.md`](./09-encryption-and-keys.md) (`INV-BR-EK-5` re-seal invariant), [`05-scope-catalog.md`](./05-scope-catalog.md) (`SC-G` slot), [`03-permission-matrix.md`](./03-permission-matrix.md) (`Rbac.KeyRotate` capability).
- Consumed by: `<spec-placeholder file="11-endpoint-export.md" />` (seal under Active), `<spec-placeholder file="12-endpoint-import.md" />` (Purged-epoch failure), `<spec-placeholder file="13-endpoint-snapshot.md" />` (same seal rules), `<spec-placeholder file="14-endpoint-restore.md" />` (re-seal orchestration), `<spec-placeholder file="15-jobs-and-progress.md" />` (rotation cron, smoke round-trip), `<spec-placeholder file="17-audit-and-observability.md" />` (audit rows), `<spec-placeholder file="18-error-codes.md" />` (`epoch_purged`, `rotation_in_progress` details).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-FS-1..6`.
