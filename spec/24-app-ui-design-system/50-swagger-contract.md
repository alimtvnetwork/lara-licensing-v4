# Swagger / OpenAPI Contract

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1. Single normative source for the OpenAPI 3.1 document that describes every route in `spec/21-app/26-route-dto-index.md`. Any runtime API surface change MUST update this contract in the same commit.
**Owner:** API documentation and machine-readable contract; consumed by generated clients, integration tests, and third-party integrators.
**Related:** [`../21-app/10-endpoints.md`](../21-app/10-endpoints.md), [`../21-app/11-api-contracts/`](../21-app/11-api-contracts/), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/13-audit-logging.md`](../21-app/13-audit-logging.md), [`../21-app/14-rate-limiting.md`](../21-app/14-rate-limiting.md), [`../21-app/21-error-management-binding.md`](../21-app/21-error-management-binding.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/28-audit-action-enum.md`](../21-app/28-audit-action-enum.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/42-quota-requests.md`](../21-app/42-quota-requests.md), [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md), [`../21-app/44-environments.md`](../21-app/44-environments.md), [`../21-app/45-license-features.md`](../21-app/45-license-features.md), [`42-route-blueprint-auth-and-403-404-500.md`](./42-route-blueprint-auth-and-403-404-500.md).

---

## 1. Purpose and scope

Defines the OpenAPI 3.1 contract file layout, the `/docs` viewer, closed-set enum handling, error envelope schema, authentication schemes, and drift-detection linter. Consumers are (a) generated TypeScript clients under `src/lib/lara-*.ts`, (b) third-party integrators, (c) the `check-openapi-parity.py` linter tying the contract back to `26-route-dto-index.md`, (d) integration tests that replay the spec.

Out of scope: SDK generation strategy (deferred, §14); versioned deprecation policy beyond the `Deprecated` flag on individual operations.

## 2. File layout

The OpenAPI document is authored as a set of YAML fragments under `spec/api/`:

```
spec/api/
  openapi.yaml                 # root document, $ref imports every fragment
  info.yaml                    # info, contact, license, servers
  security.yaml                # securitySchemes definitions
  tags.yaml                    # tag registry (Auth, Licenses, Serials, ...)
  paths/
    auth.yaml                  # /Auth/* operations
    licenses.yaml              # /Licenses/* operations
    serials.yaml               # /Serials/* operations
    quotas.yaml                # /Quotas/* + /QuotaRequests/*
    features.yaml              # /Features/*
    admin.yaml                 # /Admin/* aggregate operations
    reseller.yaml              # /Reseller/* self-service
    builder.yaml               # /Builder/*
    me.yaml                    # /Me/*
  components/
    schemas/
      envelope.yaml            # canonical envelope Response<T>
      error.yaml               # ErrorEnvelope + ErrorCode enum
      pagination.yaml          # PaginatedResponse<T>, PageMeta
      idempotency.yaml         # Idempotency headers, EtagHeaders
      license.yaml             # License DTO family
      serial.yaml              # Serial DTO family
      quota.yaml               # QuotaRequest DTO family
      feature.yaml             # Feature DTO family
      user.yaml                # User + Role DTOs
      environment.yaml         # Environment DTO
      tier.yaml                # Tier DTO
    parameters/
      common.yaml              # PageIndex, PageSize, Sort, q (search)
      idempotency-headers.yaml # Idempotency-Key, If-Match parameter refs
    responses/
      common.yaml              # 400 / 401 / 403 / 404 / 409 / 412 / 429 / 500 canonical
```

- The build MUST bundle these fragments into a single `dist/openapi.json` via a deterministic bundler (`redocly bundle` or `swagger-cli bundle`; the runner is pinned in `linter-scripts/bundle-openapi.sh`) so the wire artifact is a single file (deep imports break most third-party OpenAPI viewers).
- File encoding: UTF-8, LF newlines, no BOM. Trailing newline mandatory.
- Every fragment carries a top-of-file comment citing its normative section in `spec/21-app/`; drift between the fragment and its normative source is a lint failure.

## 3. Root document (`openapi.yaml`)

```yaml
openapi: 3.1.0
info: !include info.yaml
servers:
  - url: https://api.lara.example.com
    description: Production
  - url: https://staging.api.lara.example.com
    description: Staging
security:
  - BearerAuth: []
tags: !include tags.yaml
paths: !include paths/*.yaml
components:
  securitySchemes: !include security.yaml
  schemas: !include components/schemas/*.yaml
  parameters: !include components/parameters/*.yaml
  responses: !include components/responses/*.yaml
```

- The `!include` directive is a bundler-specific convention; the bundled `openapi.json` inlines everything.
- OpenAPI 3.1 is mandatory (not 3.0) because it aligns with JSON Schema 2020-12; every response schema uses JSON Schema keywords (`prefixItems`, `unevaluatedProperties: false`) that 3.0 does not support.
- The root `security` block defaults every operation to `BearerAuth`; operations that are legitimately public (`/api/public/*` webhooks) MUST override with `security: []` AND carry a `x-public-endpoint: true` extension so the drift linter recognises them.

## 4. `/docs` viewer

- Runtime path: `/docs` served by a server route file at `src/routes/api/public/docs.ts` (public because the doc site itself must be reachable without a session) BUT the operations displayed are only those tagged `x-audience: public`.
- Viewer: Scalar API Reference (single embedded HTML with the bundled `openapi.json`). Redoc as a fallback is acceptable; Swagger UI's inline `try-it-out` is BANNED because it ships a JavaScript client that can capture bearer tokens.
- The `/docs` page MUST NOT include an interactive "try it now" console in v1. Interactive playgrounds require session-issuing infrastructure and per-caller rate limits which are out of scope; deferred to `/docs/playground` behind Admin permission (see §14).
- Cache: the bundled `openapi.json` is served with `Cache-Control: public, max-age=300, stale-while-revalidate=3600` and an ETag derived from the file's SHA-256 hash.
- `<meta name="robots" content="noindex">` on the `/docs` HTML per `42-` §2 head-metadata rule.

## 5. Authentication schemes (`security.yaml`)

```yaml
securitySchemes:
  BearerAuth:
    type: http
    scheme: bearer
    bearerFormat: JWT
    description: |
      JWT bearer per spec/21-app/02-authentication-jwt.md.
      Session families and rotation described in spec/21-app/31-auth-session-family.md.
  IdempotencyKey:
    type: apiKey
    in: header
    name: Idempotency-Key
    description: |
      Required on every non-idempotent operation per spec/21-app/29-idempotency-lifecycle.md.
      Not a credential; documented as a header requirement so operations can reference it.
  IfMatchEtag:
    type: apiKey
    in: header
    name: If-Match
    description: |
      Concurrent-editor guard on mutations that carry an entity Etag
      (per 34-, 37-, 38-, 40- blueprint concurrent-editor rules).
```

- `IdempotencyKey` and `IfMatchEtag` are modelled as `apiKey` security schemes purely so that operations can `security: - IdempotencyKey: []` to signal the requirement in generated clients; the server never treats them as credentials.
- OAuth2 provider flows for end-user sign-in are DOCUMENTED but NOT declared as OpenAPI security schemes because the callback (`/auth/callback`) is a browser redirect, not a Bearer-flow (see `42-` §2).

## 6. Tags (`tags.yaml`)

Closed set (order defines viewer group order):

1. `Auth` (login, callback, refresh, sign-out).
2. `Me` (end-user self-service; matches `41-`).
3. `Licenses` (issuance, renewal, revocation).
4. `Serials` (device-bound serial CRUD).
5. `Quotas` (reseller quota snapshot + requests + decisions).
6. `Features` (feature catalog + TierFeatures + LicenseFeatures).
7. `Environments` (environment CRUD).
8. `Tiers` (tier catalog).
9. `Users` (Admin user + role management).
10. `Reseller` (reseller-scope self-service).
11. `Builder` (builder-scope console).
12. `Admin` (admin-only aggregate operations).
13. `Public` (webhooks + health under `/api/public/*`).

- Extra tag `x-internal: true` on any operation NOT intended for third parties; the drift linter enforces that `x-audience: public` tagged operations do NOT carry `x-internal`.
- Every operation MUST carry exactly ONE primary tag from the closed set. Multi-tag operations are BANNED because they break viewer navigation.

## 7. Envelope schema (`components/schemas/envelope.yaml`)

Every 2xx response body is a `Response<T>` envelope per `spec/21-app/11-api-contracts/05-envelope-schema.md`:

```yaml
Response:
  type: object
  required: [Data, Meta]
  additionalProperties: false
  unevaluatedProperties: false
  properties:
    Data:
      # subtype schema is composed per-operation
      description: The primary payload of the response.
    Meta:
      $ref: '#/components/schemas/ResponseMeta'
ResponseMeta:
  type: object
  required: [RequestId, ServerTimeUtc]
  additionalProperties: false
  properties:
    RequestId: { type: string, format: uuid }
    ServerTimeUtc: { type: string, format: date-time }
    Deprecation:
      type: object
      nullable: true
      properties:
        Sunset: { type: string, format: date-time }
        Replacement: { type: string }
```

- `additionalProperties: false` AND `unevaluatedProperties: false` are BOTH set to reject typos in payloads (JSON Schema 2020-12 requires both to fully close the object).
- Property casing: PascalCase, matching the DB and JSON conventions from `.lovable/strictly-avoid.md`.
- Envelope MUST NOT be flattened even for scalar payloads (`{ "Data": 42, "Meta": {...} }` is correct; returning `42` at the root is BANNED because generated clients cannot switch envelope shape per operation).

## 8. Error envelope (`components/schemas/error.yaml`)

```yaml
ErrorEnvelope:
  type: object
  required: [Error, Meta]
  additionalProperties: false
  unevaluatedProperties: false
  properties:
    Error:
      type: object
      required: [Code, Message]
      additionalProperties: false
      properties:
        Code: { $ref: '#/components/schemas/ErrorCode' }
        Message: { type: string, maxLength: 240 }
        Details:
          type: object
          nullable: true
          description: |
            Optional structured detail per ErrorCode. Schema per code is
            documented in spec/21-app/12-error-taxonomy.md.
        RetryAfterSec: { type: integer, minimum: 0, nullable: true }
    Meta: { $ref: '#/components/schemas/ResponseMeta' }
ErrorCode:
  type: string
  enum: # generated from spec/21-app/12-error-taxonomy.md; drift is a lint failure
    - AuthFailed
    - Unauthorized
    - Forbidden
    - LicenseNotFound
    - SerialNotFound
    - ClientNotFound
    - DeviceNotFound
    - QuotaExhausted
    - QuotaAlreadyDecided
    - EnvironmentMismatch
    - PreconditionFailed
    - RateLimited
    - ValidationFailed
    - InternalError
    - OAuthStateInvalid
    - ClientSecretAlreadyRotated
    # ... closed set; see spec/21-app/12-error-taxonomy.md
```

- `ErrorCode` is a closed enum GENERATED at bundle time from `spec/21-app/12-error-taxonomy.md`; a mismatch between the two files is a lint failure per `check-error-code-parity.py` (§13). Hand-maintaining the enum in both files is BANNED.
- Every 4xx / 5xx response references `ErrorEnvelope` at `application/json`. Ad-hoc error shapes BANNED.
- `Details` structure per code is documented in `12-error-taxonomy.md`, NOT in OpenAPI, because the shapes are code-specific and modelling them all as `oneOf` in OpenAPI produces unusable generated clients.

## 9. Closed-set enums

Every enum in `26-route-dto-index.md` (Status, Tier, Channel, Role, AuditAction, LicenseCategory, LicenseVariation, LicenseFeatureState, QuotaRequestStatus, etc.) is a `type: string` + `enum: [...]` closed set in OpenAPI. Rules:

- `enum` order MUST match the normative source ordinal (per `.lovable/strictly-avoid.md` rule 24 pinning ordinal stability); reordering is a lint failure.
- `x-enum-descriptions` extension mandatory on every enum, one description per value. Third-party generated clients read this for typed docstrings.
- Number-typed enums are BANNED; every enum is `type: string` to keep wire format stable across languages.
- Free-form strings for values that could be closed are BANNED (Product tags, Reasons, etc.); when a value is genuinely open, it MUST NOT be typed as an enum.

## 10. Path documentation conventions

Every operation MUST include:

- `operationId` in PascalCase matching `26-route-dto-index.md` (e.g. `AdminLicensesIssue`, `MeProductsList`).
- `summary` (≤ 80 chars), `description` (≤ 480 chars), `tags: [<one primary tag>]`.
- `security` (default `BearerAuth`; add `IdempotencyKey` + `IfMatchEtag` where required per blueprints §8 across `34-`..`42-`).
- `parameters` for path + query with `x-permission` extension citing the exact permission key from `40-permissions.md` (drift linter tie in §13).
- Request body (for POST / PATCH / PUT / DELETE-with-body) referencing a `components/schemas/` DTO.
- Responses: 200 or 201 or 204 for success; ALL applicable 4xx and 5xx per `12-error-taxonomy.md`. Missing 429 on rate-limited paths is a lint failure (all authenticated paths under `14-rate-limiting.md` MUST list 429).
- `x-audit-action` extension on every mutating operation citing an enum value from `28-audit-action-enum.md`; missing extension on a mutation is a lint failure.
- `x-blueprint-ref` extension citing the route blueprint under `spec/24-app-ui-design-system/` that owns the UX for this operation (e.g. `x-blueprint-ref: spec/24-app-ui-design-system/34-route-blueprint-admin-licenses.md`). Operations without a UI (webhooks, cron) omit this extension.
- Deprecation: use OpenAPI's `deprecated: true` PLUS `x-sunset-utc` timestamp; runtime response Meta.Deprecation MUST match.

## 11. Pagination and search parameters

`components/parameters/common.yaml` defines the shared query parameters used by every list operation:

```yaml
PageIndex:
  name: PageIndex
  in: query
  schema: { type: integer, minimum: 0, default: 0 }
PageSize:
  name: PageSize
  in: query
  schema: { type: integer, enum: [25, 50, 100], default: 25 }
Sort:
  name: Sort
  in: query
  # per-operation override of the closed-set enum via allOf; NEVER a free string
  schema: { type: string }
Q:
  name: q
  in: query
  schema: { type: string, maxLength: 128 }
```

- List operations MUST reference these parameter refs verbatim; redefining shape or defaults per operation is BANNED.
- `Sort` enum values are closed sets per operation (each blueprint defines its own closed set); a free-string `Sort` param is BANNED.
- Cursor pagination is NOT used in v1; page-index is the single normative form. Adding cursor pagination later is a v2 concern per `41-` §14 pattern.

## 12. Rate limiting and Retry-After

Per `14-rate-limiting.md`, every authenticated path MUST document a `429 RateLimited` response referencing `ErrorEnvelope` AND include the `Retry-After` header:

```yaml
'429':
  description: Rate limited.
  headers:
    Retry-After:
      schema: { type: integer, minimum: 0 }
      description: Seconds until the caller may retry.
  content:
    application/json:
      schema: { $ref: '#/components/schemas/ErrorEnvelope' }
```

- Missing `Retry-After` header declaration on any 429 response is a lint failure.
- The 429 body MUST also carry `RetryAfterSec` in `Error.RetryAfterSec` (duplicated with the header for browser clients that cannot read the header per `23-component-toast-banner.md` §5 `RetryAfterBanner` contract).

## 13. Drift detection (`check-openapi-parity.py`)

New linter under `linter-scripts/check-openapi-parity.py` enforces every parity rule cited above. Failure modes:

1. Operation in `spec/api/paths/*.yaml` without a matching row in `26-route-dto-index.md`.
2. Row in `26-route-dto-index.md` without an operation.
3. Enum value in OpenAPI missing from the normative source (12-, 28-, 43-, etc.) or vice versa.
4. Operation with `security: BearerAuth` but no `x-permission` extension.
5. Mutation without `x-audit-action`.
6. 429 response without `Retry-After` header declaration.
7. Blueprint route in `spec/24-app-ui-design-system/*route-blueprint*.md` with no `x-blueprint-ref` back-pointer from at least one operation.
8. Two operations sharing the same `operationId`.
9. `additionalProperties: false` OR `unevaluatedProperties: false` missing on any envelope or DTO schema.
10. Operation tagged `x-audience: public` but also `x-internal: true`.
11. `ErrorCode` enum drift against `12-error-taxonomy.md`.
12. `AuditAction` enum drift against `28-audit-action-enum.md`.
13. Non-PascalCase property, non-PascalCase `operationId`, or non-PascalCase enum value.
14. Deprecated operation without `x-sunset-utc`.
15. Bundled `dist/openapi.json` older than any source fragment (bundle-out-of-date).

The linter runs in CI and locally via `./linter-scripts/run.sh check-openapi-parity`. Green run mandatory before any release; drift-of-record is BANNED.

## 14. Acceptance criteria

- AC-OPENAPI-001: `redocly bundle spec/api/openapi.yaml -o dist/openapi.json` succeeds with zero warnings.
- AC-OPENAPI-002: `check-openapi-parity.py` passes.
- AC-OPENAPI-003: `/docs` serves the bundled `openapi.json` at `Cache-Control: public, max-age=300, stale-while-revalidate=3600` with ETag; renders via Scalar or Redoc; no interactive playground; `<meta name="robots" content="noindex">` present.
- AC-OPENAPI-004: Every authenticated path documents `429` with `Retry-After` header AND `Error.RetryAfterSec` body field.
- AC-OPENAPI-005: `ErrorEnvelope` and `Response<T>` set both `additionalProperties: false` AND `unevaluatedProperties: false`.
- AC-OPENAPI-006: `ErrorCode` and `AuditAction` enums are generated from their normative sources; drift is a lint failure.
- AC-OPENAPI-007: Every mutation carries `x-audit-action`; every operation carries `x-permission` or explicitly `x-public-endpoint: true`.
- AC-OPENAPI-008: Every blueprint route file has at least one operation citing it via `x-blueprint-ref`.

## 15. Open items (for follow-up commits)

- SDK generation (TypeScript, Python, Go, C#) deferred to a `spec/api/generators/*.yaml` config set.
- Interactive playground at `/docs/playground` behind Admin permission deferred.
- Cursor pagination deferred to v2 with an additive `PageCursor` parameter kept out of v1 responses.
- Async / long-running operation pattern (`202 Accepted` + `Location` polling) deferred; when built, MUST document via `x-async-poll-ref` extension.
- Webhook subscriber contract (outgoing webhooks TO integrators) deferred; when built, gets its own `paths/webhooks-outbound.yaml`.
