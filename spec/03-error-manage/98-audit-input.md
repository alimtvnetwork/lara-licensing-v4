# Audit Input: Coding-Guidelines + Error-Manage Rules Applied to Error Paths

Version: 1.0.0
Created: 2026-07-19
Parent plan: .lovable/plans/pending/11-coding-guidelines-error-manage-integration.md
Status: baseline (feeds steps 3, 4, 13, 14, 15, 16, 18, 41, 43 of Plan 11)

## 1. Sources Consulted

Coding-guidelines (spec/02-coding-guidelines/):
- 01-cross-language/11-key-naming-pascalcase.md: ALL serialized keys (JSON, log context, PHP array keys, DB columns) PascalCase.
- 01-cross-language/13-strict-typing.md: every PHP/TS parameter, return, property fully typed.
- 01-cross-language/15-master-coding-guidelines.md: 15-line function body cap, no swallow, no magic literals.
- 01-cross-language/19-null-pointer-safety.md: error-before-value; separate creation from execution.
- 01-cross-language/26-magic-values-and-immutability.md: no magic strings/numbers; enums or typed constants; immutable by default.
- 02-typescript/08-typescript-standards-reference.md and 11-eslint-enforcement.md: TS strict, no implicit any, discriminated unions for state.
- 02-typescript/12-discriminated-union-patterns.md: error/success unions with a `Kind` discriminant.
- 04-php/02-forbidden-patterns.md: `catch (Throwable $e)` only; no `wp_die` / `error_log` in handlers; ErrorLog($e, 'Context:'); Throwable is first arg.
- 04-php/05-response-array-standard.md: canonical envelope keys and shape.
- 04-php/07-php-standards-reference.md: FormRequest, Policy, Resource, Service class primitives.
- 11-security/00-overview.md: never leak stack traces, secrets, or internal paths to callers.

Error-manage (spec/03-error-manage/):
- 00-overview.md: Universal Response Envelope is the single contract.
- 02-error-architecture/01-error-handling-reference.md: three-tier flow (delegated -> Go/Laravel -> FE), stack captured server-side, RequestId + ErrorId correlate FE <-> logs.
- 02-error-architecture/05-response-envelope/01-adr.md: PascalCase keys, {Status:{IsSuccess,Code,Message}, Attributes:{RequestId,...}, Results, Errors?}.
- 02-error-architecture/06-apperror-package/*: server-side wrap-and-log pattern; every throw carries a closed-set code.
- 02-error-architecture/07-logging-and-diagnostics/*: dedicated diag channel; ErrorId links envelope to log line.
- 03-error-code-registry/*: closed-set enum is the ONLY source of ErrorCode values; both BE and FE must be in lockstep.

## 2. Rules That Apply to Error Paths (canonical, project-specific)

R1 Envelope: every 200/4xx/5xx response body is `{Status, Attributes, Results, Errors?}` with PascalCase keys. Enforced by BE middleware + BE Pest EnvelopeShapeTest (Plan 11 step 11) and FE Zod parser in lara-fetch (step 26).

R2 ErrorCode closed set: every throw on BE uses `LaraException::make('<Code>', ...)`; every FE consumer references `ApiErrorCodeType.<Code>`. The set is defined in `backend/config/lara.php::error_codes` and mirrored in `src/lib/lara-api-error.ts::ApiErrorCodeType`. Adding a code requires bumping BOTH in the same commit (Plan 11 step 4 parity CI).

R3 HTTP status is derived, not chosen: `LaraException::make` reads `config('lara.error_http_status')`. Callers cannot pass a status. Forbids `abort(400)`, `response()->json([...], 400)` in controllers.

R4 ErrorId + RequestId correlation: RequestIdMiddleware attaches X-Request-Id both ways; ErrorId is generated once at throw and included in Attributes for 5xx. FE surfaces both via copy button in GlobalErrorModal.

R5 Stack traces are logged, never returned: `lara-diag` Monolog channel captures full trace on LaraException + Throwable. Response body has NO `Trace` key. Enforced by Plan 11 step 8 grep test.

R6 No magic literals in error paths: no bare HTTP status ints in controllers, no bare error-code strings outside `backend/config/lara.php` and `src/lib/lara-api-error.ts`. Enforced by linter-scripts/check-magic-literals.py extension (step 18).

R7 PascalCase keys everywhere: envelope keys, log context keys, DB columns, JSON request/response fields. Enforced by JsonResource serialization test (step 16).

R8 15-line function body cap: applies to controllers, services, exception factories. Enforced by Pest architecture test using PhpParser (step 17).

R9 Strict typing: every PHP parameter/return typed; every TS signature typed. No `mixed` in domain code. Enforced by phpstan level 8 in backend + tsc strict on FE.

R10 Never swallow errors: bare `catch (\Throwable $e) {}` is forbidden. Every catch must rethrow as `LaraException::make(...)` or log via `Log::channel('lara-diag')` and rethrow. Enforced by grep test.

R11 Retryability is explicit: FE `LaraApiError.isRetryable()` reads a closed `RETRYABLE_CODES` set that matches `Retry-After`-emitting codes on BE (RateLimited, QuotaExhausted, AuthRefreshRaceLost, ServerError-transient).

R12 SSR-safe FE handlers: error-capture.ts and lovable-error-reporting.ts no-op when window is undefined. Ensures no build:dev prerender crashes.

R13 Global surface, single owner: unhandled LaraApiError -> Zustand error-store -> GlobalErrorModal. useLaraErrorToast suppresses toast when the same error is already in the store, so users do not see duplicate surfaces.

R14 Every controller path has a Pest feature test that asserts the exact ErrorCode, HttpStatus, and Details schema (step 43).

R15 CI is the gate, not review: parity, envelope shape, magic-literals, PHP architecture, Vitest error tests, Playwright modal spec, and axe all run in the error-contract.yml workflow (step 41).

## 3. BE vs FE Closed-Set Delta (baseline snapshot for parity CI)

Counts as of v0.403.0:
- BE `error_codes` in backend/config/lara.php: 82
- FE `ApiErrorCodeType` in src/lib/lara-api-error.ts: 84
- Common: 82

FE-only (must be added to BE `error_codes` + `error_http_status`):
- LoginCaptchaInvalid  (spec/21-app/12-error-taxonomy.md line 65, HTTP 401, retryable)
- LoginCaptchaRequired (spec/21-app/12-error-taxonomy.md line 60, HTTP 428, retryable)

BE-only: none.

Action required in Plan 11 step 4 (parity CI): the parity script MUST currently exit 1 due to LoginCaptcha*. Step 4 lands the script, step 13 backfills BE to close the gap. Do NOT relax the script to allow-list these codes; fix the BE config instead.

## 4. Files and Functions Touched by This Contract

Backend:
- backend/app/Exceptions/LaraException.php: `make()`, `resolveStatus()`, `newErrorId()`.
- backend/bootstrap/app.php: `withExceptions(...)` block (LaraException, ValidationException, AuthenticationException, Throwable renderers).
- backend/app/Support/ApiEnvelope.php (or Http/Resources): `success()`, `failure()`.
- backend/config/lara.php: `error_codes`, `error_http_status`.
- backend/config/logging.php: add `lara-diag` channel (Plan 11 step 6).
- backend/app/Http/Middleware/RequestIdMiddleware.php: new (Plan 11 step 9).
- backend/app/Http/Controllers/**: sweep for abort/response()->json (step 13).
- backend/app/Services/**: sweep for RuntimeException/InvalidArgumentException (step 14).

Frontend:
- src/lib/lara-api-error.ts: enum + LaraApiError class + isRetryable.
- src/lib/lara-fetch.ts: envelope parser (step 24-26).
- src/lib/lara-envelope.ts: Zod schema (step 26).
- src/lib/error-store.ts: Zustand store (step 28).
- src/lib/error-messages.ts: exhaustive code -> human string (step 46).
- src/components/errors/GlobalErrorModal.tsx: modal + copy buttons (step 29).
- src/lib/use-lara-error-toast.ts: dedupe with store (step 31).
- src/lib/error-capture.ts + lovable-error-reporting.ts: SSR-safe guards (step 45).
- src/routes/__root.tsx: mount modal (step 30).

CI:
- scripts/check-error-code-parity.mjs (step 4).
- linter-scripts/check-magic-literals.py (step 18).
- .github/workflows/error-contract.yml (step 41).

## 5. Verification Signals Available

- `php -r "print_r(require 'backend/config/lara.php');"` prints the closed set.
- `grep -c '"[A-Z][A-Za-z]* = "' src/lib/lara-api-error.ts` counts FE codes.
- `curl -H "X-Request-Id: probe" .../Api/UnknownRoute -w '\n%{http_code}\n' | jq .` shows envelope shape + RequestId echo.
- `tail -f backend/storage/logs/lara-diag-*.log` shows Trace lines for the same ErrorId.

## 6. Open Questions Handed to Later Steps

- Q1: should FE surface the server ErrorId even for 4xx (not just 5xx)? Current BE only injects ErrorId in Attributes for the unhandled Throwable path. Recommendation for step 10: always inject, so support tickets always have an ErrorId.
- Q2: what is the retention on `lara-diag-*.log`? Step 6 proposes 14 days; confirm against backend/log rotation ops runbook.
- Q3: axe rule set for GlobalErrorModal (step 36): WCAG 2.1 AA is the current default; confirm no project override before running.
