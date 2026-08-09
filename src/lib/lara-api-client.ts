import { z } from "zod";

import { ApiErrorCodeType, LaraApiError } from "./lara-api-error";
import { parseLaraResponse } from "./lara-api-response";
import { classifyRetryPolicy, RetryPolicyType } from "./lara-retry";
import {
  clearLaraSession,
  getLaraAccessToken,
  getLaraRefreshToken,
  setLaraAccessToken,
  setLaraRefreshToken,
} from "./lara-api-session";
import { getRuntimeMode } from "./runtime-mode";

export enum HttpMethodType {
  Delete = "DELETE",
  Get = "GET",
  Patch = "PATCH",
  Post = "POST",
  Put = "PUT",
}

export interface LaraApiRequest {
  method?: HttpMethodType;
  body?: object;
  signal?: AbortSignal;
  headers?: Record<string, string>;
  /**
   * Invoked once with the raw `Response.headers` after `fetch` resolves,
   * whether the status is 2xx or an error envelope. Callers use this to
   * capture concurrency metadata such as `ETag` per
   * spec/21-app/11-api-contracts/09-concurrency-control.md §ETag shape
   * without a second round-trip. Never throws; exceptions bubble up.
   */
  onResponseHeaders?: (headers: Headers) => void;
  /**
   * Invoked once with the parsed success-envelope `Attributes` object
   * (RequestId, RequestedAt, plus controller-specific extras like
   * `Warnings[]` from Admin\MetricsController). Only fires on 2xx.
   * Callers use it to surface per-shard fanout warnings without a
   * second round-trip. Never throws; exceptions bubble up.
   */
  onAttributes?: (attributes: Record<string, unknown>) => void;
}

const CONTENT_TYPE = "application/json";
const REFRESH_PATH = "/Auth/Refresh";
const REQUEST_ID_HEADER = "X-Request-Id";

// Error codes that indicate the refresh token itself is unusable.
// Only these clear the session. Network/server errors preserve tokens
// so the user is not silently logged out on transient failures.
//
// NON-membership (spec/21-app/12-error-taxonomy.md v1.4.0):
//   - AuthRefreshRaceLost: sibling tab already rotated; caller re-reads
//     storage and retries once with the new token (see performRefresh).
//     Session MUST be preserved.
//   - AuthSaltRotationFailed: server-side infra, transient; propagate.
const REFRESH_FATAL_CODES: ReadonlySet<ApiErrorCodeType> = new Set([
  ApiErrorCodeType.AuthRefreshReused,
  ApiErrorCodeType.AuthInvalidCredentials,
  ApiErrorCodeType.AuthUnauthorized,
  ApiErrorCodeType.AuthForbidden,
  ApiErrorCodeType.AuthTokenExpired,
]);

function getApiBaseUrl(): string {
  const runtimeUrl = getRuntimeMode().ApiBaseUrl;
  const baseUrl = runtimeUrl ?? import.meta.env.VITE_LARA_API_BASE_URL;
  if (typeof baseUrl === "string" && baseUrl.length > 0) return baseUrl.replace(/\/$/, "");
  throw new LaraApiError("Backend API URL is not configured.", ApiErrorCodeType.ServerError, 0);
}

function generateRequestId(): string {
  if (typeof crypto === "object" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  return `req-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

function createHeaders(
  hasBody: boolean,
  requestId: string,
  extra?: Record<string, string>,
): Headers {
  const headers = new Headers({ Accept: CONTENT_TYPE, [REQUEST_ID_HEADER]: requestId });
  const accessToken = getLaraAccessToken();
  if (hasBody) headers.set("Content-Type", CONTENT_TYPE);
  if (typeof accessToken === "string") headers.set("Authorization", `Bearer ${accessToken}`);
  if (extra) for (const [key, value] of Object.entries(extra)) headers.set(key, value);

  return headers;
}

function createRequestInit(request: LaraApiRequest, requestId: string): RequestInit {
  const hasBody = typeof request.body === "object";

  return {
    method: request.method ?? HttpMethodType.Get,
    headers: createHeaders(hasBody, requestId, request.headers),
    body: hasBody ? JSON.stringify(request.body) : undefined,
    signal: request.signal,
  };
}

async function send(path: string, request: LaraApiRequest, requestId: string): Promise<Response> {
  try {
    // eslint-disable-next-line no-restricted-globals -- canonical low-level transport; all envelope-aware callers go through `laraFetch`.
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

async function requestLaraApiOnce<T>(
  path: string,
  schema: z.ZodType<T>,
  request: LaraApiRequest,
): Promise<T[]> {
  const requestId = generateRequestId();
  const response = await send(path, request, requestId);
  if (typeof request.onResponseHeaders === "function") request.onResponseHeaders(response.headers);

  return parseLaraResponse(response, path, schema, request.onAttributes);
}

const refreshResultSchema = z.object({
  AccessToken: z.string().min(1),
  RefreshToken: z.string().min(1),
  TokenType: z.literal("Bearer"),
  ExpiresIn: z.number().int().positive(),
});

let refreshInFlight: Promise<boolean> | undefined;

function isFatalRefreshError(error: unknown): boolean {
  if (!(error instanceof LaraApiError)) return false;

  return REFRESH_FATAL_CODES.has(error.errorCode);
}

async function performRefresh(): Promise<boolean> {
  const refreshToken = getLaraRefreshToken();
  if (typeof refreshToken !== "string") return false;
  try {
    return await attemptRefresh(refreshToken);
  } catch (error) {
    if (error instanceof LaraApiError && error.errorCode === ApiErrorCodeType.AuthRefreshRaceLost) {
      // F2: sibling tab rotated first. Re-read storage; if a newer token
      // is present, retry once with the fresh token. Session preserved
      // regardless per spec/21-app/12-error-taxonomy.md.
      const rotated = getLaraRefreshToken();
      if (typeof rotated === "string" && rotated !== refreshToken) {
        console.warn("Lara API refresh race lost; retrying with rotated token", {
          requestId: error.requestId,
        });

        return await attemptRefresh(rotated);
      }
      console.warn("Lara API refresh race lost with no rotated token; preserving session", {
        requestId: error.requestId,
      });

      return false;
    }

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

async function attemptRefresh(refreshToken: string): Promise<boolean> {
  const [result] = await requestLaraApiOnce(REFRESH_PATH, refreshResultSchema, {
    method: HttpMethodType.Post,
    body: { RefreshToken: refreshToken },
  });
  setLaraAccessToken(result.AccessToken);
  setLaraRefreshToken(result.RefreshToken);

  return true;
}

function refreshLaraSession(): Promise<boolean> {
  if (refreshInFlight === undefined) {
    refreshInFlight = performRefresh().finally(() => {
      refreshInFlight = undefined;
    });
  }

  return refreshInFlight;
}

function shouldRetryAfterRefresh(error: unknown, path: string): boolean {
  if (path === REFRESH_PATH) return false;
  if (!(error instanceof LaraApiError)) return false;

  // Plan 11 step 27: read the canonical retry table instead of
  // hard-coding `AuthTokenExpired`. `RefreshThenRetry` is the exact
  // class bound to `AuthTokenExpired` in
  // spec/21-app/21-error-management-binding.md line 49.
  return classifyRetryPolicy(error) === RetryPolicyType.RefreshThenRetry;
}

/**
 * Preview-mode bypass guard (INV-RM-05). Mode selection lives ONLY in
 * `api-client.ts`; any `requestLaraApi` call reached while
 * `getRuntimeMode().Mode === "preview"` means a resource lib
 * (`src/lib/lara-*.ts`) or hook is bypassing `apiClient`, which would
 * silently hit a network the preview iframe cannot reach. Fail loud
 * with the offending path so the offender surfaces in the error store.
 * The `laraFetch` wrapper carries its own guard for defense in depth,
 * but the 20 resource libs go straight through this seam and must be
 * caught here.
 */
export const PREVIEW_BYPASS_REQUEST_MESSAGE =
  "requestLaraApi invoked in preview mode; use apiClient.call() instead";

function assertRequestNotPreview(path: string): void {
  if (getRuntimeMode().Mode !== "preview") return;
  console.error("requestLaraApi preview bypass", { path });
  throw new LaraApiError(
    `${PREVIEW_BYPASS_REQUEST_MESSAGE} (path=${path})`,
    ApiErrorCodeType.ServerError,
    0,
  );
}

export async function requestLaraApi<T>(
  path: string,
  schema: z.ZodType<T>,
  request: LaraApiRequest = {},
): Promise<T[]> {
  assertRequestNotPreview(path);
  try {
    return await requestLaraApiOnce(path, schema, request);
  } catch (error) {
    if (shouldRetryAfterRefresh(error, path) === false) throw error;
    const refreshed = await refreshLaraSession();
    const isFailed = !refreshed;
    if (isFailed) throw error;

    return requestLaraApiOnce(path, schema, request);
  }
}
