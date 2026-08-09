/**
 * Shared preview-handler helpers (Plan 16 Step 40).
 *
 * Two responsibilities:
 *  1. `previewError(code, message, httpStatus?)` throws a `LaraApiError`
 *     shaped like the production envelope so preview-mode failures flow
 *     through the exact same `LaraApiError` handling the live transport
 *     uses. Steps 41-50 reuse this so envelope drift is impossible.
 *  2. `previewSuccess(value)` is an identity helper documenting that
 *     preview handlers return the typed `OperationResponse<K>` payload
 *     directly (api-client.callLive collapses `Results[0]` to the same
 *     shape). Kept as a helper so a future envelope-in-transport swap
 *     is a one-file change, not 12 domain modules.
 *
 * INV-RM-05: preview + live callers observe identical typed responses.
 * INV-ERR-04: every preview failure is a LaraApiError (never a raw
 * `Error` or a plain rejected promise), so `formatLaraApiError` renders
 * the canonical `errorCode: message (Request <id>)` line unchanged.
 */

import type { OperationResponse, OperationId } from "@/generated/api/operations";
import { type ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

const HTTP_UNAUTHORIZED = 401;

export function previewError(
  code: ApiErrorCodeType,
  message: string,
  httpStatus: number = HTTP_UNAUTHORIZED,
  requestId?: string,
): never {
  throw new LaraApiError(message, code, httpStatus, requestId);
}

export function previewSuccess<K extends OperationId>(
  value: OperationResponse<K>,
): OperationResponse<K> {
  return value;
}

export function isErrorSeed(seed: string): boolean {
  return seed === "error";
}
