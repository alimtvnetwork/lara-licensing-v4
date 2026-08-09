# Machine Bindings

**Version:** 1.0.0
**Updated:** 2026-07-16
**Status:** Normative for LaraLicensingV1.

---

## Purpose

Fix the machine binding contract: how a client fingerprint becomes a stable identifier, what the server stores, how many concurrent bindings a license may have, and how a machine is released and rebound. Prior to this file the schema (`MachineBindings` in [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) lines 136-149) stored raw `MacAddress` and `MotherboardSerial`, directly contradicting [`13-audit-logging.md`](./13-audit-logging.md) §"PayloadJson rules" which forbids raw MAC and motherboard serials in any persisted payload. This file resolves that contradiction.

## Normative sources

- [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) `MachineBindings` (updated to store hashes only per §Storage below).
- [`13-audit-logging.md`](./13-audit-logging.md): `MachineBound` (audit id 35), `MachineUnbound` (audit id 36), `PayloadJson.FingerprintHash` rule.
- [`09-verify-key.md`](./09-verify-key.md): verify flow that produces the binding.
- [`12-error-taxonomy.md`](./12-error-taxonomy.md): `MachineQuotaExceeded`, `MachineNotBound`, `MachineRebindCooldownActive`.
- [`14-rate-limiting.md`](./14-rate-limiting.md): `verify:fingerprint` bucket family.
- [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md).

## Canonical fingerprint form

A client fingerprint is derived from hardware identifiers assembled by the caller. The server never sees the raw values; only the hash. The hashing algorithm is fixed here so two implementations produce identical `FingerprintHash` for the same physical machine.

1. Collect four inputs, each trimmed and lowercased ASCII:
   - `MacAddressPrimary`: first non-loopback interface MAC, format `aa:bb:cc:dd:ee:ff`.
   - `MotherboardSerial`: BIOS-reported serial, alphanumeric.
   - `CpuIdHex`: CPUID leaves 0..3 concatenated as lowercase hex.
   - `MachineGuid`: OS-assigned machine GUID (Windows registry `MachineGuid`, `/etc/machine-id` on Linux, hardware UUID on macOS), lowercase hex.
2. Assemble the canonical string: `MacAddressPrimary + "|" + MotherboardSerial + "|" + CpuIdHex + "|" + MachineGuid`. Missing components are replaced with the literal token `NONE`.
3. `FingerprintHash = lowercase(hex(SHA-256(UTF-8-encoded canonical string)))`. Length is exactly 64 hex chars.

The client SHOULD send only `FingerprintHash` in the request body under key `FingerprintHash`. Sending raw components is DEPRECATED and MUST be rejected with `ValidationFailed` starting v1.1; v1.0 accepts them for backward compatibility BUT the server hashes and discards them before writing any row.

## Storage

`MachineBindings` columns (superseding the v0.1.0 schema for this table):

| Column | Type | Notes |
|--------|------|-------|
| `MachineBindingId` | BIGINT UNSIGNED PK | |
| `LicenseId` | BIGINT UNSIGNED FK `Licenses` | |
| `FingerprintHash` | CHAR(64) | Canonical form above; lowercase hex. |
| `FirstSeenAt` | DATETIME | Wall clock at first successful bind. |
| `LastSeenAt` | DATETIME | Wall clock at most recent `POST /Verify/Final` success. |
| `ReleasedAt` | DATETIME NULL | NULL while active; set on unbind, never cleared. |
| `RebindCooldownUntil` | DATETIME NULL | Wall clock; NULL when not in cooldown. |
| `CreatedAt`, `UpdatedAt` | DATETIME | |

Unique index `(LicenseId, FingerprintHash)`. Raw `MacAddress`, `MotherboardSerial`, and `MachineKey` columns are dropped in the same migration that adds `FingerprintHash`; historical rows are backfilled by hashing existing `MachineKey` values with the canonical algorithm above (the `MachineKey` legacy column was already the client-side fingerprint, so its SHA-256 is a valid `FingerprintHash`).

## Quota

- Every license has `MaxConcurrentBindings` (default 1, override per `LicenseCategories` variation).
- "Concurrent" means `ReleasedAt IS NULL`. Released rows do not count.
- Attempting to bind a new fingerprint when the quota is full returns `409 MachineQuotaExceeded` with `Attributes.Error.Details.CurrentCount` and `MaxCount`.
- Rebinding an already-bound `FingerprintHash` (same license, same hash, `ReleasedAt IS NULL`) is a no-op success: update `LastSeenAt`, return the existing binding. This makes verify flows on the same machine cheap and replay-safe.

## Unbind

`POST /Licenses/{LicenseId}/Bindings/{MachineBindingId}/Release` is the only path to unbind. Idempotency-key optional (uniqueness constraint on `ReleasedAt` state already prevents doubles). On success:

1. Set `ReleasedAt = NOW()`, set `RebindCooldownUntil = NOW() + 15 minutes`.
2. Emit one `MachineUnbound` audit row (action id 36) with `PayloadJson.FingerprintHash` (the hash, never the raw components) and `PayloadJson.ReleaseReason ∈ {"UserInitiated","AdminInitiated","QuotaSweep"}`.
3. The freed slot becomes usable for a NEW fingerprint immediately. The just-released fingerprint is under `RebindCooldownUntil` and cannot re-bind to the SAME license until cooldown expires; rebinding the same fingerprint before cooldown returns `409 MachineRebindCooldownActive` with `Attributes.Error.Details.RetryAfterSeconds`.

Rebind cooldown exists to prevent a "unbind + re-verify" loop from evading quota; without it, a caller could effectively pool N+1 machines against an N-slot license.

## Rebind

- Different `FingerprintHash` on the same license (below quota): normal bind, no cooldown check.
- Same `FingerprintHash` on the same license, `ReleasedAt` set, `RebindCooldownUntil > NOW()`: reject `MachineRebindCooldownActive` (retry class `RetryAfter`, `Retry-After` header set to seconds remaining).
- Same `FingerprintHash` on the same license, `ReleasedAt` set, cooldown elapsed: create a NEW `MachineBindings` row (do NOT mutate the released row). This preserves audit history.

## Admin operations

Admins may:

- List bindings for any license: `GET /Admin/Licenses/{LicenseId}/Bindings`. Read-only.
- Force-release a binding: `POST /Admin/Licenses/{LicenseId}/Bindings/{MachineBindingId}/Release` with `PayloadJson.ReleaseReason = "AdminInitiated"`. Cooldown STILL applies to prevent admin churn masking abuse patterns.
- Set `RebindCooldownUntil = NULL` explicitly: `POST /Admin/Licenses/{LicenseId}/Bindings/{MachineBindingId}/ClearCooldown`. Emits `AdminBreakGlassUsed` (existing audit) with `PayloadJson.Reason` required.

## Retention

- Rows with `ReleasedAt IS NULL` are retained indefinitely (active bindings).
- Rows with `ReleasedAt IS NOT NULL` are retained 24 months, then archived to cold storage with the `AuditLogs` archive job (see [`13-audit-logging.md`](./13-audit-logging.md) §"Retention and access"). The archived row keeps `FingerprintHash` for correlation.

## Observability

- `MachineBound` and `MachineUnbound` audit rows correlate to the verify request via `RequestId`.
- Metrics: counter `laralicensing_machine_bindings_total{Outcome ∈ {"Bound","NoOpRefresh","QuotaExceeded","CooldownActive"}}`; histogram `laralicensing_machine_binding_slots_used{LicenseId}`.
- Log line on every bind decision at level `INFO` (Bound, NoOpRefresh) or `WARN` (QuotaExceeded, CooldownActive) with `RequestId`, `LicenseId`, `FingerprintHash` (first 8 chars only in logs), `Outcome`.

## Acceptance

- AC-MB-001: `MachineBindings` stores `FingerprintHash` only. Raw MAC, motherboard, CPUID, or GUID values MUST NOT be persisted anywhere except transient request memory.
- AC-MB-002: `FingerprintHash` for the same physical machine is byte-identical across implementations that follow §"Canonical fingerprint form". Reference vectors ship with the test suite.
- AC-MB-003: Quota is counted as `COUNT(*) WHERE LicenseId = ? AND ReleasedAt IS NULL`. Released rows never count.
- AC-MB-004: Same-license, same-`FingerprintHash`, active binding on second verify is a no-op refresh that updates `LastSeenAt` only.
- AC-MB-005: Unbind sets both `ReleasedAt` and `RebindCooldownUntil` in one UPDATE; setting only one is a bug.
- AC-MB-006: Rebinding the same `FingerprintHash` to the same license before cooldown expiry returns `409 MachineRebindCooldownActive` with `Retry-After` header set from `RebindCooldownUntil - NOW()`.
- AC-MB-007: Post-cooldown rebind INSERTs a new row; the released row is never mutated back to active.
- AC-MB-008: `MachineBound`/`MachineUnbound` audit `PayloadJson` contains `FingerprintHash` only, never raw components (enforced by the serialization allowlist in [`13-audit-logging.md`](./13-audit-logging.md)).
- AC-MB-009: `MachineQuotaExceeded` and `MachineNotBound` map to retry class `NoRetry`; `MachineRebindCooldownActive` maps to `RetryAfter` per [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md).
