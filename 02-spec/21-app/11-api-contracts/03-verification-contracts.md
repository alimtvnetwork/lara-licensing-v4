# Verification API Contracts

**Version:** 1.3.0
**Updated:** 2026-07-21

## Envelope invariants (Plan 03 step 14)

Every verify request and response body uses PascalCase JSON keys per [`05-envelope-schema.md`](./05-envelope-schema.md) §JSON casing. Every verify endpoint is on the `X-Request-Id` strict list per [`../20-observability.md`](../20-observability.md) §Request-Id: a missing or malformed header returns `400 RequestIdMissing` and the server MUST NOT mint a substitute. Verify endpoints do NOT accept `Idempotency-Key`; the header is ignored if supplied and MUST NOT be rejected for its presence (per [`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md) §Scope, verify routes are outside the idempotency scope in v1 because responses contain `VerifyKey` values, see AC-IEH-008).

## Shared input objects

`MachineFingerprint` contains optional `MotherboardSerial`, optional `MacAddress`, optional `BrowserFingerprint`, and `MachineKey`. At least `MachineKey` or `BrowserFingerprint` is required. The server derives the caller IP and does not trust an `IpAddress` request property.

`UserIdentifier` is an optional string up to 255 characters. The server normalizes it before binding.

`EnvironmentId` (integer) is REQUIRED on every verify request body per [`../44-environments.md`](../44-environments.md) §3 gate 2. The value MUST be derived server-side from the AppBuilder OAuth client's configured environment; end-user callers inherit it from their AppBuilder client. Requests missing `EnvironmentId` return `400 ValidationFailed` with `Details = [{ "Field": "EnvironmentId", "Rule": "Required" }]`. A caller `EnvironmentId` that references a value not present in `Environments` returns `400 ValidationFailed` with `Rule: "MembershipRequired"`. A well-formed `EnvironmentId` that does not match the license row's `EnvironmentId` returns `409 EnvironmentMismatch` with the opaque `Details.Value` `"<Requested>/<Licensed>"` per [`../12-error-taxonomy.md`](../12-error-taxonomy.md) row `EnvironmentMismatch` and AC-LENV-004; the mismatch response MUST NOT contain the actual environment names anywhere in the envelope (this includes `Attributes`, `Message`, and log payloads). Successful `POST /Verify/Final` responses MUST echo the license's `EnvironmentId` (safe to disclose once the match gate has passed) so the calling app can gate features by environment.


## `POST /Verify/Serial`

Request: `SerialValue` string and `EnvironmentId` integer (see §Shared input objects).

Result: `IsValid` boolean, `LicenseId` integer, `Category` enum, optional `ExpiresAt`, `IsSingleUse`, optional `UserCount`, and optional `MachineCount`.

Failure envelopes:

| HTTP | ErrorCode | Details[] shape | Retry class |
|------|-----------|-----------------|-------------|
| 400 | `ValidationFailed` | `{ Field: "SerialValue" \| "EnvironmentId", Rule: "Required" \| "MembershipRequired" \| "PascalCaseKey" \| "MaxLength" }` | NoRetry |
| 400 | `RequestIdMissing` | omit | NoRetry |
| 401 | `SerialInvalid` | omit (no fingerprint disclosure) | NoRetry |
| 401 | `SerialRevoked` | omit | NoRetry |
| 401 | `LicenseExpired` | `{ Field: "ExpiresAt", Rule: "InPast", Actual: "<iso>" }` | NoRetry |
| 409 | `EnvironmentMismatch` | `{ Field: "Environment", Rule: "Mismatch", Value: "<Requested>/<Licensed>" }` (opaque markers only, AC-LENV-004) | NoRetry |
| 429 | `RateLimited` | `Attributes.RateLimit` per [`06-envelope-attributes.md`](./06-envelope-attributes.md) | RetryAfter |

## `POST /Verify/Hash`

Request: `SerialValue`, `HashKey`, `EnvironmentId`, `MachineFingerprint`, and optional `UserIdentifier`.

Result: `VerifyKey` string and `ExpiresAt` timestamp. The response is never cached (`Cache-Control: no-store`).

Failure envelopes:

| HTTP | ErrorCode | Details[] shape | Retry class |
|------|-----------|-----------------|-------------|
| 400 | `ValidationFailed` | `{ Field: "MachineFingerprint" \| "SerialValue" \| "HashKey" \| "EnvironmentId", Rule: "Required" \| "MembershipRequired" \| "OneOfRequired" \| "PascalCaseKey" }` | NoRetry |
| 400 | `RequestIdMissing` | omit | NoRetry |
| 401 | `VerifyHashInvalid` | omit (does not disclose which fingerprint component failed, AC-API-VER-002) | NoRetry |
| 401 | `LicenseExpired` | `{ Field: "ExpiresAt", Rule: "InPast", Actual: "<iso>" }` | NoRetry |
| 409 | `EnvironmentMismatch` | `{ Field: "Environment", Rule: "Mismatch", Value: "<Requested>/<Licensed>" }` | NoRetry |
| 409 | `LicenseMachineLimit` | `{ Field: "MachineCount", Rule: "MaxReached", Expected: "<int>", Actual: "<int>" }` | NoRetry |
| 409 | `LicenseUserLimit` | `{ Field: "UserCount", Rule: "MaxReached", Expected: "<int>", Actual: "<int>" }` | NoRetry |
| 429 | `RateLimited` | `Attributes.RateLimit` | RetryAfter |

## `POST /Verify/Final`

Request: `SerialValue`, `HashKey`, `VerifyKey`, and `EnvironmentId`.

Result: `IsAuthorized` boolean, `LicenseId`, `EnvironmentId` integer (echoed from the license row after the match gate has passed; safe to disclose per AC-API-VER-011), `LicenseTierId` integer (echoed from the license row so the client can gate tier-level UI without a second call), `Features` object (PascalCase `FeatureKey` -> typed `Value` map resolved per [`../45-license-features.md`](../45-license-features.md) §4 precedence: `LicenseFeatures` override `TierFeatures`; absent keys mean "not licensed" and clients MUST NOT infer defaults), `AuthorizedAt`, optional `ExpiresAt`, `MachineBindingId` optional integer, and `UserBindingId` optional integer. The `Features` map is resolved inside the same transaction as the binding write, after the `EnvironmentMismatch` gate has passed, so a concurrent feature toggle cannot race the response.

Failure envelopes:

| HTTP | ErrorCode | Details[] shape | Retry class |
|------|-----------|-----------------|-------------|
| 400 | `ValidationFailed` | `{ Field: "VerifyKey" \| "HashKey" \| "SerialValue" \| "EnvironmentId", Rule: "Required" \| "MembershipRequired" \| "PascalCaseKey" }` | NoRetry |
| 400 | `RequestIdMissing` | omit | NoRetry |
| 401 | `VerifyKeyExpired` | `{ Field: "ExpiresAt", Rule: "InPast", Actual: "<iso>" }` | NoRetry |
| 401 | `VerifyKeyConsumed` | omit (concurrent second request per §Transaction boundary) | NoRetry |
| 401 | `VerifyKeyMismatch` | omit (constant-time compare, AC-API-VER-001; does not disclose which pair mismatched) | NoRetry |
| 409 | `EnvironmentMismatch` | `{ Field: "Environment", Rule: "Mismatch", Value: "<Requested>/<Licensed>" }` | NoRetry |
| 409 | `LicenseMachineLimit` | as above | NoRetry |
| 409 | `LicenseUserLimit` | as above | NoRetry |
| 429 | `RateLimited` | `Attributes.RateLimit` | RetryAfter |

## Transaction boundary

Final verification locks the `VerifyKeys` row, validates it, checks the caller `EnvironmentId` against the license row's `EnvironmentId` (mismatch aborts with `EnvironmentMismatch` before any binding write), creates or refreshes bindings, marks the key consumed, and writes the audit event in one transaction. A concurrent second request receives `VerifyKeyConsumed`. The environment gate runs INSIDE the transaction so a concurrent environment change on the license row (should one ever be permitted in a future version) cannot race the binding write.

## Acceptance

- AC-API-VER-001: Hash and verify-key comparisons use constant-time comparison.
- AC-API-VER-002: Verification responses do not disclose which fingerprint input differed.
- AC-API-VER-003: Caller IP is read from trusted server request context, never from JSON.
- AC-API-VER-004: Every response includes `Cache-Control: no-store`.
- AC-API-VER-005: Every verify request and response body uses PascalCase JSON keys; non-PascalCase request keys return `400 ValidationFailed` with `Rule: "PascalCaseKey"`.
- AC-API-VER-006: A missing or malformed `X-Request-Id` on any verify endpoint returns `400 RequestIdMissing`; the server MUST NOT mint a substitute.
- AC-API-VER-007: `Idempotency-Key` is ignored on verify endpoints and MUST NOT cause rejection when supplied (verify responses carry `VerifyKey` values and are excluded from replay per AC-IEH-008).
- AC-API-VER-008: Every failure row in the tables above maps to exactly one `ErrorCode` in [`../12-error-taxonomy.md`](../12-error-taxonomy.md) and one retry class in [`../25-retry-decision-matrix.md`](../25-retry-decision-matrix.md).
- AC-API-VER-009: `EnvironmentId` is derived server-side from the caller's AppBuilder OAuth client (or, for end-user callers, inherited from the AppBuilder client). Any JSON-supplied `EnvironmentId` that disagrees with the server-derived value MUST be rejected as `400 ValidationFailed` with `Details = [{ "Field": "EnvironmentId", "Rule": "ClientDerived" }]`; the server MUST NOT trust a client-supplied override.
- AC-API-VER-010: `EnvironmentMismatch` responses on all three verify endpoints emit exactly `{ "Field": "Environment", "Rule": "Mismatch", "Value": "<Requested>/<Licensed>" }` and MUST NOT contain the actual environment names anywhere in the envelope (Details, Attributes, Message) or in server log payloads for that request (per [`../20-observability.md`](../20-observability.md) redaction rules); verified by a contract test that inspects the response body and the emitted log line for the tokens `Production`, `Staging`, and `Development`.
- AC-API-VER-011: Successful `POST /Verify/Final` result echoes the license's `EnvironmentId`; this disclosure is safe because the match gate has already succeeded and the caller by definition knows their own environment. `POST /Verify/Serial` and `POST /Verify/Hash` MUST NOT echo `EnvironmentId` in success responses (they are pre-authorization endpoints).
- AC-API-VER-012: Successful `POST /Verify/Final` result includes a `Features` object whose keys are canonical `FeatureKey` values per [`../45-license-features.md`](../45-license-features.md) §2 and whose values match the declared `ValueType` per §3. The map is the result of the strict `LicenseFeatures > TierFeatures` precedence resolution against the verified `(LicenseId, LicenseTierId)`; a key absent from both layers MUST be omitted (never emitted as `null` or `false`). `POST /Verify/Serial` and `POST /Verify/Hash` MUST NOT include a `Features` field.
- AC-API-VER-013: If any resolved `FeatureKey` is not present in the `Features` catalog (data drift) the endpoint MUST return `500 UnknownServerError` and log a `Warn` line naming the offending key; the response MUST NOT partially leak known keys. If a stored `Value` fails its declared `ValueType` shape (drift bypassing the write-time trigger) the endpoint MUST also return `500 UnknownServerError`. Admin-side writes that violate either rule are rejected earlier by AC-FEAT-001 (`FeatureUnknown` 400) or AC-FEAT-002 (`FeatureValueInvalid` 400) at the write path in [`02-license-contracts.md`](./02-license-contracts.md); see [`../12-error-taxonomy.md`](../12-error-taxonomy.md).


