# License Variations

**Version:** 1.1.0
**Updated:** 2026-07-16

---

## Canonical set (single source of truth)

This file is the sole normative source for the `LicenseVariation` shape. Any other spec file (including [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md)) MUST point here rather than restate parameters. Parameter names are PascalCase and stable; renaming is a breaking change.

Collection name is `LicenseVariations`; forbidden collection synonyms are `Variations`, `license_variations`, `LicenseVariants`. Parameter forbidden synonyms: `UserCount` MUST NOT be spelled `user_count`, `Users`, or `Seats`; `MachineCount` MUST NOT be spelled `machine_count`, `Machines`, or `Devices`.

## Cross references

- Error taxonomy: [`12-error-taxonomy.md`](./12-error-taxonomy.md) `LicenseUserLimit` and `LicenseMachineLimit` fire when a bind exceeds the corresponding parameter.
- Endpoint map: [`10-endpoints.md`](./10-endpoints.md) `POST /Licenses/{LicenseId}/Bind*` reads these limits.
- API contracts: [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md) uses these parameter names verbatim.

## Parameters

Every license carries two independent limits:

| Parameter | Meaning | Identifiers |
|-----------|---------|-------------|
| `UserCount` | Max distinct users under one license. | Email or hashed IP fallback. |
| `MachineCount` | Max distinct machines under one license. | MAC, motherboard serial, machine-generated key. |

Either limit can be `null` meaning unlimited.

## Identification Rules

- Desktop clients: MAC + motherboard serial + generated `MachineKey`. IP is recorded but not authoritative.
- Web clients: hashed IP + browser fingerprint. `MachineCount` is meaningless for web-only licenses; set to `null`.
- User identity: authenticated email preferred; when absent, hashed IP is used as a coarse fallback.

## Storage

- `LicenseVariations(LicenseVariationId, LicenseId, UserCount, MachineCount)` one-to-one with `Licenses`.
- `MachineBindings(MachineBindingId, LicenseId, MacAddress, MotherboardSerial, MachineKey, FirstSeenAt)` tracks each bound machine.
- `UserBindings(UserBindingId, LicenseId, UserIdentifier, FirstSeenAt)` tracks each bound user.

## Acceptance

- AC-VAR-001: Binding a new machine beyond `MachineCount` returns `LicenseMachineLimit`.
- AC-VAR-002: Web licenses may set `MachineCount = null` without validation error.
- AC-VAR-003: Rebinding a released slot is allowed and logged.
