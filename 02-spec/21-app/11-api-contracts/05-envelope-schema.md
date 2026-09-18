# Universal Envelope Schema

**Version:** 1.1.0
**Updated:** 2026-07-16

## JSON casing (Plan 03 step 11)

Every JSON key in every request and response body across LaraLicensingV1 is PascalCase. This includes envelope keys (`Status`, `Attributes`, `Results`), attribute-block keys (`RequestId`, `Error`, `Pagination`, `RateLimit`, `Idempotency`), payload keys inside `Results[]`, `Details[]` item keys (`Field`, `Rule`, `Expected`, `Actual`), and request-body keys (for example `SerialValue`, `MachineFingerprint`, `LicenseCategoryId`). `snake_case`, `camelCase`, and `kebab-case` JSON keys are FORBIDDEN and MUST be rejected with `400 ValidationFailed` `Details[]` `{ Field: "<offender>", Rule: "PascalCaseKey" }`. HTTP headers stay in canonical HTTP casing (for example `X-Request-Id`, `Idempotency-Key`, `Retry-After`); they are not JSON keys and are out of scope for this rule. This clause supersedes any older example that used lowercase JSON keys.

## Request-Id propagation (Plan 03 step 12)

Client MAY mint `X-Request-Id` as a ULID or UUIDv4. If present and well-formed, the server MUST reuse the value verbatim in the response `X-Request-Id` header and in `Attributes.RequestId`. If absent or malformed, the server MUST mint a fresh ULID and use it for both surfaces. Endpoints on the strict list in [`../20-observability.md`](../20-observability.md) §Request-Id (`/Admin/*`, `/Verify/*`, `/App/UpdateAsset/*`) MUST reject a missing header with `400 RequestIdMissing` rather than mint one silently. Every log line emitted while handling the request MUST carry the same `RequestId` per [`../22-log-line-contract.md`](../22-log-line-contract.md), and every UI error surface MUST render it per [`../12-error-taxonomy.md`](../12-error-taxonomy.md) §Log level and `RequestId` surface.

---

## Purpose

Lock the exact shape of the universal response envelope for every
LaraLicensingV1 JSON response. Resolves the inconsistency between
[`00-overview.md`](./00-overview.md) (which placed `ErrorCode` under
`Attributes.Error`) and [`../20-observability.md`](../20-observability.md)
(which placed `ErrorCode` under `Status`), and fixes the field-order,
type, and nullability rules referenced by
[`../21-error-management-binding.md`](../21-error-management-binding.md).

## Canonical shape

```json
{
  "Status": {
    "IsSuccess": true,
    "Code": 200,
    "Message": "OK"
  },
  "Attributes": {
    "RequestId": "01HXYZ...",
    "RequestedAt": "2026-07-16T10:00:00Z"
  },
  "Results": []
}
```

Field order MUST be `Status`, `Attributes`, `Results` in every response.
The three keys are always present. `Results` is always an array, never
`null` and never an object.

## `Status` block

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `IsSuccess` | bool | yes | Mirrors `HTTP 2xx`. |
| `Code` | int | yes | Equals the HTTP status. |
| `Message` | string | yes | Short reason phrase. Never contains PII or stack. |

`Status` does NOT carry `ErrorCode`. `ErrorCode` lives in
`Attributes.Error.ErrorCode` (see [`06-envelope-attributes.md`](./06-envelope-attributes.md)).
The v1.0.0 example in [`../20-observability.md`](../20-observability.md)
§`RequestIdMissing` that placed `ErrorCode` under `Status` is superseded
by this file and MUST be corrected at the same edit cadence.

## `Attributes` block

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `RequestId` | ULID or UUIDv4 | yes | Echoes `X-Request-Id`. |
| `RequestedAt` | ISO 8601 UTC | yes | Server clock at ingress. |
| `Error` | object | on failure | Schema in [`06-envelope-attributes.md`](./06-envelope-attributes.md). |
| `Pagination` | object | list responses | Schema in [`06-envelope-attributes.md`](./06-envelope-attributes.md). |
| `RateLimit` | object | 429 only | Schema in [`06-envelope-attributes.md`](./06-envelope-attributes.md). |

`Attributes` MUST NOT contain any key not listed above. Contract-specific
keys go inside `Results`, never as sibling attributes.

## `Results` block

- Always an array.
- Success: zero or more items of the contract's declared type.
- Failure: always empty `[]`. Never `null`, never populated.
- Single-object endpoints (for example `GET /Licenses/{Id}`) return an
  array with exactly one element. Clients index `Results[0]`.

## Failure example

```json
{
  "Status": {
    "IsSuccess": false,
    "Code": 404,
    "Message": "Not Found"
  },
  "Attributes": {
    "RequestId": "01HXYZ...",
    "RequestedAt": "2026-07-16T10:00:00Z",
    "Error": {
      "ErrorCode": "LicenseNotFound",
      "ErrorMessage": "License with the given serial does not exist."
    }
  },
  "Results": []
}
```

## Content negotiation

- `Content-Type: application/json; charset=utf-8` on every response.
- Empty `204` responses are FORBIDDEN. Successful deletes return the
  envelope with `Status.Code=200` and `Results=[]`.
- `Content-Length` MUST match the body byte count. Chunked responses
  are permitted only for `/App/UpdateAsset/*`.

## Acceptance

- AC-ENV-001: Every JSON response validates against this schema, including 4xx and 5xx.
- AC-ENV-002: No response places `ErrorCode` under `Status`.
- AC-ENV-003: `Results` is an array in every response, empty on failure.
- AC-ENV-004: `Attributes.RequestId` equals the `X-Request-Id` response header.
- AC-ENV-005: Every JSON key in every request and response body is PascalCase; a `snake_case`, `camelCase`, or `kebab-case` request key returns `400 ValidationFailed` with `Rule: "PascalCaseKey"`.
- AC-ENV-006: A well-formed client-minted `X-Request-Id` is reused verbatim in the response; a missing or malformed value causes the server to mint a fresh ULID, except on the strict list where it returns `400 RequestIdMissing`.
