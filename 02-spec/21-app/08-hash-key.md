# Hash Key

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

The hash key is produced client-side and sent to the server as the second verification factor after the serial number.

## Inputs

Ordered list, concatenated with `|` before hashing:

1. `SerialValue`
2. `MotherboardSerial` (desktop) or `BrowserFingerprint` (web)
3. `MacAddress` (desktop) or empty string (web)
4. `IpAddress`
5. `UserIdentifier` (email or hashed IP)
6. `AppBuilderSalt` (per-integration secret, provided at client registration)

## Algorithm

- Default: `HMAC-SHA256` with `AppBuilderSalt` as the key.
- Output truncation configurable to 4, 8, 12, 32, 64, or 128 hex chars. Default 32.
- Configuration lives in `AppBuilders(AppBuilderId, HashLength, HashAlgorithm)`.

## Transport

```json
{
  "SerialValue": "Alim-M-V1-9F3K-2XQ8-77TA",
  "HashKey": "9f3ka72b...",
  "MachineFingerprint": {
    "MotherboardSerial": "MB123",
    "MacAddress": "AA:BB:CC:DD:EE:FF",
    "IpAddress": "203.0.113.5"
  },
  "UserIdentifier": "user@example.com"
}
```

## Acceptance

- AC-HASH-001: Server recomputes the hash with stored `AppBuilderSalt` and compares in constant time.
- AC-HASH-002: Mismatch returns `VerifyHashInvalid` and does not disclose which input failed.
- AC-HASH-003: Hash length shorter than 4 chars is rejected at config time.
