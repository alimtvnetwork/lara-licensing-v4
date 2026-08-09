# Error Management Cheatsheet (contributor side)

**Audience**: anyone writing TypeScript in `src/` or PHP in `backend/`. Every rule below has an automated enforcement point (ESLint, Pest, Vitest, CI). Follow the "do this" column. The "not that" column is what CI will reject.

Operator side (grepping `lara-diag-*.log` by `ErrorId`) is a separate document: `spec/03-error-manage/02-error-architecture/07-logging-and-diagnostics/03-grep-lara-diag-by-error-id.md`.

## 1. TL;DR

| You want to... | Do this | Not this | Enforced by |
| --- | --- | --- | --- |
| Call a backend endpoint | `import { laraFetch } from "@/lib/lara-fetch"` | `fetch(...)` in `src/` | `no-restricted-globals` in `eslint.config.js` (Plan 11 step 25) |
| Throw a client-side error | `throw new LaraApiError({ code, ... })` from `@/lib/lara-api-error` | `throw new Error("...")` | `no-restricted-syntax` (Plan 11 step 39, arrives next) |
| Throw a server-side error | `throw LaraException::make('CodeName', 'message', $details)` | `throw new \Exception(...)` or `\RuntimeException(...)` | PHP CS Fixer / Rector (Plan 11 step 40, arrives next) |
| Surface an error to the user | `pushLaraApiError(error)` from `@/lib/error-store` | Custom toast + custom modal | Global surfaces (`GlobalErrorModal`, `GlobalRateLimitBanner`, `useLaraErrorToast`) already subscribe |
| Decide whether to retry | `classifyRetry(error)` from `@/lib/lara-retry` | Hard-coding `if (code === "RateLimited")` | `tests/lara-retry.test.ts` locks the classifier table |
| Add a new error code | Add to `config/lara.php` `error_codes` AND `src/lib/lara-api-error.ts` `ApiErrorCodeType` | Add only on one side | `scripts/check-error-code-parity.mjs` gate |

## 2. Frontend: the four seams you must use

### 2.1 `laraFetch` (not raw `fetch`)

`src/lib/lara-fetch.ts` is the only permitted entry point for Lara API calls. It:

1. Generates `X-Request-Id`.
2. Parses the `{Status, Attributes, Results}` envelope via Zod (`src/lib/lara-envelope.ts`).
3. Throws `LaraApiError` on any non-2xx.
4. Converts raw network failures into `LaraApiError(ServerError, httpStatus=0)` (no more "why did this reject with a raw `TypeError`" bugs).
5. Attaches the bearer token and orchestrates one-shot refresh on `AuthTokenExpired`.

```ts
// do
import { laraFetch } from "@/lib/lara-fetch";
const users = await laraFetch<User>("/App/Admin/Users", { method: "GET" });
// users is typed as User[] because Results is unwrapped for you.

// not that
const r = await fetch("/App/Admin/Users");   // ESLint: no-restricted-globals
const j = await r.json();
if (!j.ok) { /* now what shape does j.error have? */ }
```

### 2.2 `LaraApiError` (not raw `Error`)

`src/lib/lara-api-error.ts` carries `code` (closed-set), `httpStatus`, `requestId`, `errorId` (5xx only, matches `bootstrap/app.php` line 97), `details`, and `rateLimit`.

```ts
// do
throw new LaraApiError({
  code: ApiErrorCodeType.ValidationInputInvalid,
  message: "Row id required",
  httpStatus: 400,
  requestId: "local",
});

// not that
throw new Error("row id required");   // no code, no requestId, no closed-set membership
```

### 2.3 `pushLaraApiError` (not custom toast/modal)

`src/lib/error-store.ts` is the single subscribe point. `GlobalErrorModal` renders fatal codes (`NoRetry`/`FatalClear`), `useLaraErrorToast` renders retryable codes (`RetryAfter` filtered out because the banner owns it), and `GlobalRateLimitBanner` owns `RateLimited`/`AbuseBlocked`/`MachineRebindCooldownActive`.

```ts
// do
import { pushLaraApiError } from "@/lib/error-store";
try {
  await laraFetch("/App/User/Something");
} catch (e) {
  if (e instanceof LaraApiError) pushLaraApiError(e);
  throw e;
}

// not that
toast.error(e.message);                      // bypasses classifier
setLocalModal({ open: true, msg: e.message }); // bypasses global modal + a11y coverage
```

`laraFetch` already forwards to `pushLaraApiError` for unhandled rejections through `error-capture.ts`; you only need to call it manually when you catch and re-render locally (form submit path is the common case).

### 2.4 `classifyRetry` (not hard-coded code checks)

`src/lib/lara-retry.ts` returns one of `NoRetry | RetryAfter | RefreshThenRetry | ExpBackoff | FatalClear`. Downstream code branches on the policy, not on the code string.

```ts
// do
import { classifyRetry, RetryPolicyType } from "@/lib/lara-retry";
const policy = classifyRetry(error);
if (policy === RetryPolicyType.RetryAfter) return; // banner owns the UX
if (policy === RetryPolicyType.NoRetry)    return; // modal owns the UX

// not that
if (error.code === "RateLimited") { /* your own countdown */ }
if (error.code === "AuthTokenExpired") { /* your own refresh */ }
```

### 2.5 Form submit lock

Any form that mounts `<RetryAfterBanner>` must also gate its submit button through `useSubmitLock` (`src/lib/use-submit-lock.ts`). Precedent: `src/components/admin/license-issue-form.tsx` line 209, `src/components/admin/serial-issue-form.tsx` line 180.

```tsx
const submitLock = useSubmitLock(localError);
<Button disabled={busy || submitLock.locked} data-submit-locked={submitLock.locked}>
  {submitLock.locked ? `Retry in ${submitLock.remainingSeconds}s` : "Submit"}
</Button>
```

## 3. Backend: the two seams you must use

### 3.1 `LaraException::make` (not raw exceptions)

`backend/app/Exceptions/LaraException.php` line 52 is the canonical factory. It:

1. Resolves the HTTP status from `config('lara.error_http_status')`, so callers cannot pick their own status.
2. Generates the `errorId` (UUID v4) once at throw time.
3. Carries `details` in the closed shape `{Field, Rule, Value?, Message?}`.
4. Accepts `headers` for `Retry-After` and friends.

```php
// do
throw LaraException::make(
    'QuotaExhausted',
    'Reseller has no remaining seats.',
    [['Field' => 'reseller_id', 'Rule' => 'QuotaExhausted']],
);

// not that
throw new \RuntimeException('reseller has no seats');   // no ErrorCode, no ErrorId, no envelope shape
abort(422, 'reseller has no seats');                    // Laravel default renderer, envelope drift
return response()->json(['error' => 'x'], 422);         // hand-rolled shape, bypasses AC-ENV-001
```

Only `bootstrap/app.php` (line 66 for `LaraException`, line 158 for `\Throwable`) may render an exception into a response. Do not build a JSON response inline.

### 3.2 `ApiEnvelope::failure` / `::success` (not `response()->json`)

`backend/app/Support/ApiEnvelope.php` is the only permitted builder for API responses. Lines 66 (failure) and its `success` sibling guarantee AC-ENV-001 through AC-ENV-004 and pipe `details` through `DetailsRedactor::redact` (Plan 11 step 33) so credentials never cross the wire.

```php
// do (controllers)
return ApiEnvelope::success($request, [$resource]);

// not that
return response()->json(['data' => $resource]);         // wrong shape
return response()->json(['Status' => ..., ...]);        // right shape, wrong redaction
```

### 3.3 Adding a new error code

1. Add to `backend/config/lara.php`: `error_codes` (closed-set), `error_http_status` (integer), `error_default_messages` (English string).
2. Add to `src/lib/lara-api-error.ts` `ApiErrorCodeType`.
3. Add to `src/lib/lara-retry.ts` `POLICY_TABLE` with the correct `RetryPolicyType`.
4. Run `node scripts/check-error-code-parity.mjs` locally; it must pass.
5. If the new code is banner-owned (`RateLimited`-like), add to `BANNER_OWNED_ERROR_CODES` in `lara-retry.ts`.
6. Write a Pest test that throws it and asserts the envelope shape (see `backend/tests/Feature/Errors/EnvelopeShapeMatrixTest.php` for the pattern).

Skip any step and CI will reject the PR: parity check is wired into the `error-contract.yml` chain (arriving in Plan 11 step 41).

## 4. Never do this (silent failure catalog)

Every item below is a real regression this cheatsheet exists to prevent.

- `try { ... } catch { /* swallow */ }` in `src/`. Log with `console.error` at minimum; prefer letting `LaraApiError` bubble to the store.
- `catch (e) { toast.error(e.message) }` without classifier. Retryable codes get toast, banner-owned codes get banner, fatal codes get modal. The classifier decides.
- `if (response.ok)` after `fetch`. Bypasses the envelope. Use `laraFetch`.
- `throw new Error(\`unexpected: ${JSON.stringify(x)}\`)`. Choose a code from `ApiErrorCodeType`. If nothing fits, add one (see 3.3).
- Backend: `\Log::error($e->getMessage())` before rethrowing. The global handler at `bootstrap/app.php` line 83 already writes the full redacted trace to `lara-diag`. Duplicate logging just makes the diag noisier.
- Backend: putting sensitive values in `details.Value` or `details.Message` unmasked. `DetailsRedactor` will catch matched keys but hand-crafted messages that echo the raw value (`"password 'hunter2' too short"`) leak. Prefer `Message => "Value does not meet minimum length"`.
- Backend: returning `abort(...)` in a controller. Use `LaraException::make(...)`.

## 5. When something breaks

- Lint says "raw fetch is banned" (step 25): swap to `laraFetch`, do not add `// eslint-disable-next-line`.
- Lint says "raw throw new Error is banned" (step 39): swap to `LaraApiError`.
- PHP CS Fixer says "raw \Exception is banned" (step 40): swap to `LaraException::make`.
- Envelope contract test (`EnvelopeShapeMatrixTest`) fails: you changed a response shape. Route back through `ApiEnvelope`.
- Parity check fails: you added a code on one side only. See 3.3.
- Global Error Modal a11y test (`tests/e2e/specs/error-modal-a11y.spec.ts`) fails: you changed modal markup. Restore aria-labels/focus-trap; do not tag the test.

## 6. Deeper reading

- Envelope shape: `backend/app/Support/ApiEnvelope.php` header comment (lines 1-25).
- Retry policies: `spec/21-app/21-error-management-binding.md` §"Retry policy classes" (lines 43-52).
- Error taxonomy: `spec/21-app/12-error-taxonomy.md` (AC-ERR-001, AC-ERR-003, AC-ERR-005).
- FE seams: `src/lib/lara-fetch.ts`, `src/lib/lara-envelope.ts`, `src/lib/lara-retry.ts`, `src/lib/error-store.ts`, `src/lib/use-submit-lock.ts`.
- BE seams: `backend/app/Exceptions/LaraException.php`, `backend/app/Support/ApiEnvelope.php`, `backend/app/Support/DetailsRedactor.php`, `backend/app/Support/TraceRedactor.php`, `backend/bootstrap/app.php` (lines 66-190).
- Operator runbook (grep by `ErrorId`): `spec/03-error-manage/02-error-architecture/07-logging-and-diagnostics/03-grep-lara-diag-by-error-id.md`.
