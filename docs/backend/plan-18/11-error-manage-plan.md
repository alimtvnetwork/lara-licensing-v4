# Plan 18 · Step 11 · Backend Error-Manage Plan

Status: draft (produced by Plan 18 Step 11).

Depends on: `backend/app/Exceptions/LaraException.php`,
`backend/bootstrap/app.php` (renderable handler, lines ~80-125),
`src/lib/lara-api-error.ts` (`LaraApiError` class, line 219),
`spec/03-error-manage/02-error-architecture/`,
`spec/03-error-manage/03-error-code-registry/error-codes-master.json`,
`spec/21-app/20-observability.md`, Plan 11 SS-01 (existing envelope).

## 1. Current shape (ground truth, not speculation)

- Single exception class today: `App\Exceptions\LaraException` (final, no
  hierarchy). Constructor at `LaraException.php:32`; factory
  `LaraException::make()` at `LaraException.php:53`; `errorId` minted at
  `LaraException.php:71` (UUID v4, no dependency).
- Handler lives in `backend/bootstrap/app.php` around lines 80-125:
  logs the full trace to the `lara-diag` channel, builds
  `ApiEnvelope::failure(...)` with `extraAttributes: ['ErrorId' => ...]`,
  echoes `Retry-After` and other headers, and sets `X-Request-Id`. This
  behaviour is already the canonical envelope. **Plan 18 will NOT rewrite
  it.**
- FE ingestion: `LaraApiError` (`src/lib/lara-api-error.ts:219`) already
  carries `errorCode`, `httpStatus`, `requestId`, `rateLimit`, `errorId`,
  `details`, and (Plan 17 Step 40) an optional `operationId` stamped by
  hooks.

Root question the plan must answer: **what MUST change in Steps 81-120
to satisfy AC-08 (unified error surface + notification center) without
regressing the existing envelope?**

## 2. LaraException hierarchy (Steps 81-85)

Introduce a thin subclass tree under `backend/app/Exceptions/` so
domain code stops passing raw `errorCode` strings and the handler can
attach category metadata for the notification center.

```
LaraException                    (existing, KEEP as base; remove `final`)
├── AuthException                (401/403 family)
├── ValidationException          (422 family; wraps field details)
├── RateLimitException           (429; owns Retry-After header)
├── DomainConflictException      (409 family; license/serial/quota)
├── NotFoundException            (404 family)
└── InternalException            (500 family; forces 5xx-only log ceremony)
```

Rules:

- Subclasses expose typed factories, e.g.
  `AuthException::sessionNotFound(): self` returning a
  `LaraException::make('AuthSessionNotFound', ...)` result. This
  eliminates stringly-typed throws sprinkled across controllers.
- No behavioural divergence from the base class today; the handler
  keeps matching `instanceof LaraException`. The hierarchy is a
  **domain-organisation** move, not a routing move.
- `LaraException` loses `final`; every child is `final`. A linter step
  (177) will grep for `throw new LaraException(` and require it live
  behind a subclass factory.

## 3. ErrorId correlation contract (Steps 86-90)

Already exists (UUID v4 at throw time; echoed as `Attributes.ErrorId`;
logged to `lara-diag`). Plan 18 tightens the contract:

- `X-Error-Id` response header, in addition to the envelope attribute,
  is added in Step 86 by `bootstrap/app.php` so browser DevTools show
  the id without expanding the JSON body. Idempotent: if the id is
  already in the envelope, the header is a mirror.
- FE change (Step 87): `parseLaraApiError` (in
  `src/lib/lara-api-error.ts`, near the top of the file) reads
  `X-Error-Id` as a fallback when the envelope attribute is missing;
  this handles proxies that strip response bodies on early aborts.
- Log line contract (Step 88): every handler branch must emit
  ONE `lara.exception` line with keys
  `{RequestId, ErrorId, ErrorCode, HttpStatus, OperationId?, Route}`.
  `OperationId` is populated when the caller sent
  `X-Lara-Operation` (added FE-side in Step 89 by patching
  `src/lib/lara-transport.ts` fetch wrapper to include the operation
  id header on every request).

## 4. HTTP envelope (no schema change)

`ApiEnvelope::failure(...)` already emits:

```json
{
  "Status":     { "IsSuccess": false, "Code": 409, "Message": "LicenseConflict" },
  "Attributes": { "ErrorId": "…", "RequestedAt": "…", "Details": [ … ] },
  "Results":    []
}
```

Plan 18 keeps this shape. Adjustments:

- Add `Attributes.Category` in Step 91 (`"Auth" | "Validation" |
  "RateLimit" | "DomainConflict" | "NotFound" | "Internal"`), derived
  from the exception subclass. FE consumers ignore unknown categories
  (`z.enum(...).catch("Internal")` in the response Zod). This drives
  the notification center's grouping (Step 12).
- Add `Attributes.OperationId` in Step 92, echoed back from the
  request header so the notification center can render "which call
  broke" without depending on FE-side tagging.
- No change to `Status`, `Results`, or `Details` layout. Any change
  there is out of scope for Plan 18.

## 5. Log sink (Step 93)

- Primary sink stays `lara-diag` (rotating daily channel already
  configured under `config/logging.php`; verified live in
  `bootstrap/app.php:84`).
- Add a secondary sink `lara-audit-errors` in Step 93:
  monolog channel writing NDJSON to
  `storage/logs/lara-audit-errors-YYYY-MM-DD.log`, redacted via the
  existing `DetailsRedactor` and `TraceRedactor`. Retention 30 days.
  This sink is what the admin "Errors" screen (Step 108) tails.
- No PII in either sink; the redactors already enforce that.

## 6. Mapping table (BE exception -> FE `LaraApiError`)

| BE field (Attributes / header) | FE field on `LaraApiError` | Source line |
|---|---|---|
| `Status.Code` | `httpStatus` | `LaraException.php:33` -> envelope `httpCode` |
| `Status.Message` | `errorCode` | envelope `message: $e->errorCode` |
| `Attributes.ErrorId` | `errorId` | `LaraException.php:71` |
| `Attributes.RequestedAt` | (not surfaced; kept for audit) | envelope |
| `Attributes.Details` | `details` | `LaraException.php:37` |
| `Attributes.Category` (NEW, Step 91) | `category?: LaraErrorCategory` (NEW, Step 94) | subclass |
| `Attributes.OperationId` (NEW, Step 92) | `operationId` (existing, Plan 17 Step 40) | header echo |
| `X-Request-Id` header | `requestId` | `bootstrap/app.php:115` |
| `X-Error-Id` header (NEW, Step 86) | fallback for `errorId` | Step 87 |
| `Retry-After` header | `rateLimit.retryAfterSeconds` | `bootstrap/app.php:107` |

FE changes required to consume the new fields (Steps 94-95):

- Extend `LaraApiError` with `readonly category?: LaraErrorCategory`.
- Update `parseLaraApiError` to read `Attributes.Category` and
  `Attributes.OperationId`, with safe fallbacks (unknown -> `Internal`,
  missing -> `undefined`).
- Extend `PREVIEW_RESPONSE_SHAPES` error branch in
  `src/lib/preview-fixtures/_shapes.ts` so preview fixtures can emit
  the new attributes without tripping `assertPreviewShape`.

## 7. Error-code registry alignment

`spec/03-error-manage/03-error-code-registry/error-codes-master.json`
already indexes ecosystem-wide codes; `config/lara.php` holds the
project-local `error_http_status` map consumed by
`LaraException::resolveStatus()` (`LaraException.php:60`). Plan 18
Step 96 will:

1. Reconcile every string thrown from controllers against
   `config/lara.php`.
2. Emit a build script `linter-scripts/check-error-codes.php` that
   fails CI if a `LaraException::make('X', ...)` uses a code not in
   the config map, or if an entry in the map is unreachable.
3. Cross-check against `ApiErrorCodeType` in
   `src/lib/lara-api-error.ts:1-95` so BE-only codes surface as a
   Zod parse failure on FE.

## 8. Non-goals for Plan 18

- Introducing a Go-tier `apperror` package (Tier 2 in the spec):
  this repo has no Go backend, so Tier 2 is empty by design.
- Rewriting `ApiEnvelope::failure` signature: additions are strictly
  additive (`extraAttributes`).
- Changing HTTP status assignments in `config/lara.php`: any
  reassignment is a breaking wire change and belongs in a dedicated
  plan.
- Changing the `lara-diag` channel configuration: only additive.

## 9. Step budget crosswalk (informs Steps 81-120)

| Sub-range | Work |
|---|---|
| 81-85 | Ship exception subclasses + factories; migrate controller throws. |
| 86-90 | `X-Error-Id` header, FE fallback parse, log line contract, operation-id header. |
| 91-95 | `Category` + `OperationId` envelope attrs; FE `LaraApiError.category`. |
| 96-100 | Registry linter, controller sweep, unit tests for handler branches. |
| 101-105 | `lara-audit-errors` sink + admin errors screen backend. |
| 106-110 | Admin errors screen FE + notification-center bridge (see Step 12 plan). |
| 111-115 | Preview fixture parity for new attrs; Zod alignment. |
| 116-120 | Pest + Vitest coverage, drift lockfile update. |
