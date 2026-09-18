# Verify Key

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

The verify key is issued by the server after a successful hash-key check. The client presents (serial + hash + verify) together in the final call. This binds the verification to a single server-issued nonce and limits replay windows.

## Generation

- Random 32 hex chars, cryptographic RNG.
- Stored server-side as `VerifyKeys(VerifyKeyId, LicenseId, SerialId, HashKeyDigest, VerifyKeyValue, IssuedAt, ExpiresAt, IsConsumed)`.
- `ExpiresAt = IssuedAt + 5 minutes`.
- Single-use: `IsConsumed` flips on first successful final verification.

## Flow

1. Client calls `POST /Verify/Serial` with `SerialValue` → server checks existence, revocation, expiry.
2. Client calls `POST /Verify/Hash` with `SerialValue + HashKey + MachineFingerprint + UserIdentifier` → server validates hash and returns `VerifyKey`.
3. Client calls `POST /Verify/Final` with `SerialValue + HashKey + VerifyKey` → server consumes the key and returns authorization decision plus binding info.

Diagram: [`../23-app-db/09-verify-sequence.mmd`](../23-app-db/09-verify-sequence.mmd).

## Errors

| Code | Meaning |
|------|---------|
| `VerifyKeyExpired` | Past `ExpiresAt`. |
| `VerifyKeyConsumed` | Already used. |
| `VerifyKeyMismatch` | Serial or hash does not match issuance. |

## Acceptance

- AC-VK-001: Consumed verify keys cannot be reused, even within the 5-minute window.
- AC-VK-002: Verify keys expire exactly at `ExpiresAt`; a 5-minute-and-1-second call returns 401.
- AC-VK-003: All verify-key events (`Issued`, `Consumed`, `Expired`) appear in the audit log.
