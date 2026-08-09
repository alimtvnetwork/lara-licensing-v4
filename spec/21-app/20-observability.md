# Observability

**Version:** 1.0.0
**Updated:** 2026-07-16
**Status:** Current. Consumers: `10-endpoints.md` v0.2.0 (strict-list `X-Request-Id`), `12-error-taxonomy.md` v0.2.0 (`RequestIdMissing`), `13-audit-logging.md` v0.2.0 (`RequestId` persistence). Client implementation: `src/lib/lara-api-client.ts` v0.50.0+.

Request correlation and structured logging contract for LaraLicensingV1. Every request is traceable from browser through the Laravel host to the audit log via a single `X-Request-Id`. Silent failure is banned; every error path must log with correlation and surface a machine-readable code.

## Root cause fixed

`spec/21-app/` prose invoked "RequestId" in the envelope but never defined who mints it, who echoes it, or what happens when it is missing. AF-CG-013 flagged this; AF-CX-103 flagged the missing `RequestIdMissing` error code path. This file locks the contract.

## `X-Request-Id`

### Format

- ULID (Crockford base32, 26 chars) or UUIDv4 (36 chars). Server accepts either.
- Case-insensitive on read; echoed verbatim.
- Regex on the server: `^[A-Za-z0-9-]{16,64}$`.

### Who mints

1. **Browser / CLI client.** MUST mint `X-Request-Id` for every outbound request using `crypto.randomUUID()` (browser) or `New-Guid` (PowerShell). Attach as request header.
2. **Server (fallback).** If the header is absent AND the endpoint is not in the strict list below, the server mints one and processes the request; the response echoes the server-minted value.
3. **Strict endpoints.** For every endpoint under `/Admin/*`, `/Verify/*`, and `/App/UpdateAsset/*`, the header is REQUIRED. Missing header returns `RequestIdMissing` (400).

### Who echoes

- Every response, success or failure, MUST include `X-Request-Id` response header AND `Attributes.RequestId` in the envelope. The two values are identical.
- Rate-limit responses (429) and auth failures (401/403) MUST also echo it. This is the observability lifeline for hostile paths.

### Who logs

- Every request logs one line at ingress: `INFO <METHOD> <PATH> requestId=<X-Request-Id> userId=<...>`.
- Every failure logs `ERROR [<ErrorCode>] <message> requestId=<X-Request-Id> userId=<...>`.
- Every audit row per `13-audit-logging.md` MUST carry `RequestId`. Missing `RequestId` on an audit row is a data-integrity defect.

## Client obligations

### Browser (`src/lib/lara-api-client.ts`)

- Mint `X-Request-Id` per request (`crypto.randomUUID()`) before `fetch`.
- Attach header. Log the id alongside `path` and `method` at ingress AND at every catch site.
- On `LaraApiError`, propagate `error.requestId` to the caller for UI display and support tickets.
- Never swallow errors; re-throw after logging.

### PowerShell (`scripts/*.ps1`)

- Mint `[guid]::NewGuid().ToString()` per HTTP call.
- Attach as `X-Request-Id` header on `Invoke-RestMethod`.
- Log per line: `INFO <METHOD> <URL> requestId=<...> -> <status>`.

## Server obligations

- Reject with `RequestIdMissing` (HTTP 400, error code `RequestIdMissing`) for strict-list endpoints without the header.
- Attach `X-Request-Id` to every log line for the lifetime of the request (structured logger context).
- Persist `RequestId` on every `AuditLog` row.
- On `500` internal errors, still echo the header AND include `Attributes.RequestId`; the operator uses it to correlate stack traces.

## Structured log shape

Server ingress line:

```
level=info ts=2026-07-16T10:00:00Z msg="request received" method=POST path=/Admin/Users/{id}/Roles requestId=01HXY... userId=u-123 role=Admin
```

Server error line:

```
level=error ts=2026-07-16T10:00:00Z msg="role denied" code=AUTHZ_ROLE_DENIED path=/Admin/Users/{id}/Roles requestId=01HXY... userId=u-123 required=Admin actual=Reseller
```

Client error line (browser):

```
console.error("Lara API error", { path, method, requestId, code, httpStatus, message })
```

Fields are keyed PascalCase inside JSON payloads and lowercase in log format because logs are wire-level.

## `RequestIdMissing` (in `12-error-taxonomy.md`)

- HTTP 400.
- Envelope:

```json
{
  "Status": { "IsSuccess": false, "Code": 400, "Message": "Bad Request" },
  "Attributes": {
    "RequestId": "01HXY...",
    "RequestedAt": "2026-07-16T10:00:00Z",
    "Error": { "ErrorCode": "RequestIdMissing", "ErrorMessage": "X-Request-Id header is required for this endpoint." }
  },
  "Results": []
}
```

Envelope shape is normative in [`11-api-contracts/05-envelope-schema.md`](./11-api-contracts/05-envelope-schema.md); attribute blocks in [`11-api-contracts/06-envelope-attributes.md`](./11-api-contracts/06-envelope-attributes.md).

## Rate-limit correlation (interaction with `14-rate-limiting.md`)

Rate-limit buckets are keyed by `(userId | ip, endpoint-family, requestId)` for burst tracing. The 429 response echoes `X-Request-Id`, `Retry-After`, and rate-limit headers per `14-rate-limiting.md` so support can reproduce the exact request.

## Acceptance

Passes `AC-AUD-024`, `AC-AUD-025`; contributes to `AC-AUD-006` (self-update endpoints correlate downloads) and `AC-AUD-018..020` (user-management events).

## Cross-references

- Envelope: `spec/21-app/11-api-contracts/00-overview.md`.
- Error taxonomy: `spec/21-app/12-error-taxonomy.md`.
- Audit log: `spec/21-app/13-audit-logging.md`.
- Rate limiting: `spec/21-app/14-rate-limiting.md`.
- Client fix: `src/lib/lara-api-client.ts` (plan step 27).
- Coding rules: `spec/25-app-audit/16-coding-guideline-alignment.md` AF-CG-013.
