/**
 * Canonical envelope-aware fetch entry point for Plan 11 step 24.
 *
 * Every `src/` caller that talks to a Lara API MUST go through
 * `laraFetch`. Steps 25 (ESLint `no-restricted-globals: ["fetch"]`),
 * 26 (typed envelope parser reuse), 27 (`isRetryable()`), 33
 * (`error-capture.ts` forwarding), and 34 (Vitest coverage) all attach
 * to this single seam.
 *
 * Responsibilities:
 *   1. Generate `X-Request-Id` on every call (see `lara-api-client.ts`).
 *   2. Parse the `{Status, Attributes, Results}` envelope via Zod
 *      (`lara-api-response.ts`) so callers receive `Results` typed as
 *      `T[]` without a second parse pass.
 *   3. Throw `LaraApiError` on any non-2xx envelope, preserving
 *      `requestId`, `errorId` (5xx only, AC-ERR-003 in
 *      `backend/bootstrap/app.php` line 90), and `details` (4xx
 *      field-level entries per `ApiEnvelope::failure` line 66).
 *   4. Convert network failures (raw `fetch` throw) into a
 *      `LaraApiError(ServerError, httpStatus=0)` so downstream toast /
 *      Global Error Modal / retry logic can rely on a single error
 *      class. Never swallow: the underlying error is logged with
 *      `console.error` in `send()` before this wrapper rethrows.
 *   5. Attach the bearer token and orchestrate one-shot refresh on
 *      `AuthTokenExpired` (delegated to `requestLaraApi`).
 *
 * This module intentionally re-exports `requestLaraApi` under the
 * `laraFetch` name rather than duplicating the refresh/session logic;
 * having two divergent wrappers is the exact failure mode Plan 11
 * step 25's ESLint rule is designed to prevent.
 */

import type { z } from "zod";

import { HttpMethodType, requestLaraApi, type LaraApiRequest } from "./lara-api-client";
import { ApiErrorCodeType, LaraApiError } from "./lara-api-error";
import { getRuntimeMode } from "./runtime-mode";

export { HttpMethodType, type LaraApiRequest };

/**
 * Fixed marker for wrapped network failures. Kept as a constant so the
 * FE toast layer (`use-lara-error-toast.ts`) and future
 * `error-messages.ts` (Plan 11 step 46) can key off `httpStatus === 0`
 * without string-matching the message.
 */
export const NETWORK_FAILURE_STATUS = 0;

function wrapNetworkFailure(error: unknown, path: string): LaraApiError {
  const cause = error instanceof Error ? error.message : String(error);

  return new LaraApiError(
    `Network request failed: ${cause}`,
    ApiErrorCodeType.ServerError,
    NETWORK_FAILURE_STATUS,
  );
}

/**
 * Preview-mode marker code. INV-RM-05: mode selection lives ONLY in
 * `api-client.ts`. Any `laraFetch` call reached while
 * `getRuntimeMode().Mode === "preview"` means a resource lib
 * (`src/lib/lara-*.ts`) is bypassing `apiClient`, which would silently
 * hit a network the preview iframe cannot reach. Fail loud instead so
 * the offending path shows up in the error store immediately.
 */
export const PREVIEW_BYPASS_MESSAGE =
  "laraFetch invoked in preview mode; use apiClient.call() instead";

function assertNotPreview(path: string): void {
  if (getRuntimeMode().Mode !== "preview") return;
  throw new LaraApiError(
    `${PREVIEW_BYPASS_MESSAGE} (path=${path})`,
    ApiErrorCodeType.ServerError,
    NETWORK_FAILURE_STATUS,
  );
}

export async function laraFetch<T>(
  path: string,
  schema: z.ZodType<T>,
  request: LaraApiRequest = {},
): Promise<T[]> {
  try {
    assertNotPreview(path);

    return await requestLaraApi(path, schema, request);
  } catch (error) {
    const laraError = error instanceof LaraApiError ? error : wrapNetworkFailure(error, path);
    // Plan 11 step 28: single capture point. Every failure that exits
    // `laraFetch` lands in the error store so the Global Error Modal
    // (step 29), toast layer (step 30), and history panel (step 36)
    // read from one source. Import is local to avoid a cycle with
    // `error-store.ts` when it grows retry/backoff helpers later.
    const { pushLaraApiError } = await import("./error-store");
    pushLaraApiError(laraError);
    throw laraError;
  }
}
