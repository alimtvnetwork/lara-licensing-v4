# Catch, Log, Rethrow Patterns

**Version:** 1.1.0
**Updated:** 2026-07-16

---

## Purpose

Canonical handler patterns for LaraLicensingV1 that satisfy the "no
swallowed errors" rule from `.lovable/coding-guidelines/` and the log-line
contract in [`22-log-line-contract.md`](./22-log-line-contract.md).
Every code sample here is a template. Deviation must cite a specific
justification in the file that deviates.

## Normative sources

- [`../02-coding-guidelines/`](../02-coding-guidelines/): master rules.
- [`.lovable/coding-guidelines/`](../../.lovable/coding-guidelines/): no swallowed errors, 15-line function cap, positive boolean names.
- [`21-error-management-binding.md`](./21-error-management-binding.md): log level and retry class.
- [`../03-error-manage/02-error-architecture/06-apperror-package/`](../03-error-manage/02-error-architecture/06-apperror-package/): server error type.

## Golden rule

A catch block MUST do exactly one of:

1. **Log and rethrow.** Add correlation context, rethrow the original error.
2. **Log and translate.** Convert to a domain error, preserve the cause chain, rethrow.
3. **Log and handle.** Only in top-level request boundaries. MUST emit the response envelope with `ErrorCode` and `RequestId`.

A catch block MUST NOT: silently swallow, return a default, or convert
into a boolean success flag. Any such block is a rejected symptom patch.

## Server pattern (Laravel)

```php
public function issueSerial(IssueSerialRequest $request): JsonResponse
{
    $requestId = $request->header('X-Request-Id');
    try {
        $serial = $this->serials->issue($request->validated());
        Log::info('serial issued', [
            'RequestId' => $requestId,
            'Route' => 'POST /Licenses/{LicenseId}/Serials',
            'Actor' => $request->user()->id,
            'LicenseId' => $serial->LicenseId,
        ]);
        return $this->envelope($serial, $requestId);
    } catch (IdempotencyConflict $e) {
        // Pattern 2: log and translate; RetryClass=NoRetry per binding.
        Log::warning('idempotency conflict', [
            'RequestId' => $requestId,
            'Route' => 'POST /Licenses/{LicenseId}/Serials',
            'ErrorCode' => 'IdempotencyConflict',
            'RetryClass' => 'NoRetry',
        ]);
        throw new ApiError('IdempotencyConflict', 409, previous: $e);
    }
}
```

Never catch `\Throwable` and return a plain 500. The framework
exception handler owns unknown faults and MUST log at `Error` with
`ErrorCode=ServerError`, `RetryClass=ExpBackoff`.

## Client pattern (TypeScript, `src/lib/lara-api-client.ts`)

```ts
try {
  return await fetchLara(path, init)
} catch (error) {
  if (error instanceof LaraApiError) {
    // Pattern 1: log with full context, rethrow.
    console.error('Lara API error', {
      path,
      method: init.method,
      requestId: error.requestId,
      errorCode: error.errorCode,
      httpStatus: error.httpStatus,
      retryClass: error.retryClass,
    })
    throw error
  }
  // Pattern 2: unknown network fault, translate + preserve cause.
  const wrapped = new LaraApiError('ServerError', 0, {
    cause: error,
    requestId: init.headers['X-Request-Id'],
  })
  console.error('Lara API network fault', { path, cause: String(error) })
  throw wrapped
}
```

## Anti-patterns (rejected)

- `catch (e) { return null }` : swallows, no log, no rethrow.
- `catch (e) { console.log(e) }` : `log` not `error`, no context, no rethrow.
- `catch (e) { throw new Error(e.message) }` : loses cause chain and error code.
- `catch { toast.error('Something went wrong') }` : hides `ErrorCode` and `RequestId` from the operator; UI MUST use `formatLaraApiError` per [`16-ui-surfaces.md`](./16-ui-surfaces.md).
- Empty `catch` blocks: never permitted, no exceptions.

## Handler split rule

If a handler exceeds the 15-line cap after adding the catch block,
extract the try body into a private method (for example `submitCore()`
in `src/components/admin/serial-issue-form.tsx`). The catch stays in the
public entry point so the log line is one hop from the request boundary.

## Verified examples (Step 10 of Plan 02 remediation)

Each snippet below is verbatim from the current codebase (line refs pinned as of v0.103.0). Any refactor that changes these line ranges MUST update the anchors here in the same commit.

### V1: network fault translation, `src/lib/lara-api-client.ts` lines 83-95

```ts
async function send(path: string, request: LaraApiRequest, requestId: string): Promise<Response> {
  try {
    return await fetch(`${getApiBaseUrl()}${path}`, createRequestInit(request, requestId));
  } catch (error) {
    console.error("Lara API network request failed", {
      path,
      method: request.method,
      requestId,
      error,
    });
    throw error;
  }
}
```

Verdict: pattern 1 (log and rethrow). Preserves `requestId` context, does not swallow. Gap: log line does not yet carry `errorCode`/`retryClass` because the fault is pre-envelope; classification happens one frame up in `parseFailure`. Recorded as F4 in `.lovable/pending-issues/issue-002-lib-runtime-spec-drift.md` and scheduled for Plan 03 step 5.

### V2: refresh classification, `src/lib/lara-api-client.ts` lines 121-142

```ts
async function performRefresh(): Promise<boolean> {
  const refreshToken = getLaraRefreshToken();
  if (typeof refreshToken !== "string") return false;
  try {
    const [result] = await requestLaraApiOnce(REFRESH_PATH, refreshResultSchema, {
      method: HttpMethodType.Post,
      body: { RefreshToken: refreshToken },
    });
    setLaraAccessToken(result.AccessToken);
    setLaraRefreshToken(result.RefreshToken);
    return true;
  } catch (error) {
    if (isFatalRefreshError(error)) {
      const requestId = error instanceof LaraApiError ? error.requestId : undefined;
      console.warn("Lara API refresh rejected; clearing session", { requestId, error });
      clearLaraSession();
      return false;
    }
    console.error("Lara API refresh failed transiently; session preserved", { error });
    throw error;
  }
}
```

Verdict: pattern 3 (log and handle) for fatal codes (`FatalClear` retry class), pattern 1 (log and rethrow) for transient. `console.warn` matches the `Warn`-and-above binding for `AuthRefreshReused` at `21-error-management-binding.md` line 61. Do not downgrade to `console.log`; the log-level ladder is contractual.

### V3: envelope-mismatch translation, `src/lib/lara-api-response.ts` lines 75-84

```ts
function parseFailure(body: unknown, response: Response, path: string): LaraApiError {
  const parsed = apiFailureSchema.safeParse(body);
  if (parsed.success) return createFailure(parsed.data, response, path);
  console.error("Lara API failure envelope mismatch", {
    path,
    status: response.status,
    issues: parsed.error.issues,
  });
  return new LaraApiError("The API request failed.", ApiErrorCodeType.ServerError, response.status);
}
```

Verdict: pattern 2 (log and translate). Preserves envelope validation issues in the log; downgrades unknown responses to `ServerError`. Known gap tracked as F4 (see V1 note): a future unknown code should be preserved as a first-class `LaraApiError` rather than collapsed to `ServerError`. Do not add a broad `try/catch` symptom patch here.

### V4: failure envelope classification, `src/lib/lara-api-response.ts` lines 86-102

```ts
function createFailure(
  failure: z.infer<typeof apiFailureSchema>,
  response: Response,
  path: string,
): LaraApiError {
  const requestId = response.headers.get(HEADER.RequestId) ?? failure.Attributes.RequestId;
  const errorCode = failure.Attributes.Error.ErrorCode;
  const rateLimit =
    errorCode === ApiErrorCodeType.RateLimited ? readRateLimit(response.headers) : undefined;
  console.error("Lara API request failed", { path, status: response.status, requestId, errorCode });
  return new LaraApiError(
    failure.Attributes.Error.ErrorMessage,
    errorCode,
    response.status,
    requestId,
    rateLimit,
  );
}
```

Verdict: pattern 2 (log and translate). Every field required by [`22-log-line-contract.md`](./22-log-line-contract.md) §Client log lines is present (`path`, `requestId`, `errorCode`, `httpStatus`), the only omission is `retryClass` which is looked up per-code in the binding table and is a client-side derivation, not a server field. Retry-After capture is spec-exact per AC-RL-008 (`RateLimited` only).

## Handler split rule

If a handler exceeds the 15-line cap after adding the catch block,
extract the try body into a private method (for example `submitCore()`
in `src/components/admin/serial-issue-form.tsx`). The catch stays in the
public entry point so the log line is one hop from the request boundary.

## Acceptance

- AC-CLR-001: Every catch block in a server handler either rethrows or translates to `ApiError`; no silent return.
- AC-CLR-002: Every catch block emits at least one log line satisfying [`22-log-line-contract.md`](./22-log-line-contract.md).
- AC-CLR-003: Every translated error preserves the original as its `cause`.
- AC-CLR-004: Unit tests cover at least one happy path and one failure path per handler, asserting the log line fires with `RequestId` and `ErrorCode`.
- AC-CLR-005: Each verified example V1-V4 above is pinned to a real file:line range. A CI grep or code review MUST fail if the referenced lines no longer match the snippet.
