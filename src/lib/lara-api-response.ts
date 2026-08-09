import { type z } from "zod";

import { ApiErrorCodeType, LaraApiError, type RateLimitMetadata } from "./lara-api-error";
import {
  decodeLaraFailure,
  decodeLaraSuccess,
  type ApiFailure,
  type ApiFailureLenient,
} from "./lara-envelope";

const HEADER = {
  Bucket: "X-RateLimit-Bucket",
  Limit: "X-RateLimit-Limit",
  RequestId: "X-Request-Id",
  Reset: "X-RateLimit-Reset",
  RetryAfter: "Retry-After",
  Window: "X-RateLimit-Window",
} as const;

function readNumber(headers: Headers, name: string): number | undefined {
  const value = headers.get(name);
  if (value === null) return undefined;
  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : undefined;
}

function readRateLimit(headers: Headers): RateLimitMetadata {
  return {
    retryAfterSeconds: readNumber(headers, HEADER.RetryAfter),
    bucket: headers.get(HEADER.Bucket) ?? undefined,
    limit: readNumber(headers, HEADER.Limit),
    windowSeconds: readNumber(headers, HEADER.Window),
    resetAt: readNumber(headers, HEADER.Reset),
  };
}

async function readJson(response: Response, path: string): Promise<unknown> {
  try {
    return await response.json();
  } catch (error) {
    pushLaraApiError(new Error());
    throw new LaraApiError(
      "The API returned invalid JSON.",
      ApiErrorCodeType.ServerError,
      response.status,
    );
  }
}

export async function parseLaraResponse<T>(
  response: Response,
  path: string,
  schema: z.ZodType<T>,
  onAttributes?: (attributes: Record<string, unknown>) => void,
): Promise<T[]> {
  const body = await readJson(response, path);
  if (response.ok) return parseSuccess(body, response, path, schema, onAttributes);
  throw parseFailure(body, response, path);
}

function parseSuccess<T>(
  body: unknown,
  response: Response,
  path: string,
  schema: z.ZodType<T>,
  onAttributes: ((attributes: Record<string, unknown>) => void) | undefined,
): T[] {
  const decoded = decodeLaraSuccess(body, schema);
  if (decoded.kind === "success") {
    if (typeof onAttributes === "function" && isRecord(body)) {
      const attrs = (body as { Attributes?: unknown }).Attributes;
      if (isRecord(attrs)) onAttributes(attrs);
    }

    return decoded.results;
  }
  console.error("Lara API success envelope mismatch", {
    path,
    status: response.status,
    issues: decoded.issues,
  });
  throw new LaraApiError(
    "The API response did not match its contract.",
    ApiErrorCodeType.ServerError,
    response.status,
  );
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function parseFailure(body: unknown, response: Response, path: string): LaraApiError {
  const decoded = decodeLaraFailure(body);
  if (decoded.kind === "strict") return createFailure(decoded.failure, response, path);
  if (decoded.kind === "lenient") return createUnknownCodeFailure(decoded.failure, response, path);
  console.error("Lara API failure envelope mismatch", {
    path,
    status: response.status,
    issues: decoded.issues,
  });

  return new LaraApiError("The API request failed.", ApiErrorCodeType.ServerError, response.status);
}

/**
 * Well-formed envelope carrying an ErrorCode the client hasn't learned yet.
 * We warn (never silent) with the raw code preserved, then return a distinct
 * UnknownServerError so telemetry can distinguish "server introduced a new
 * code" from a real ServerError. See F4 in
 * .lovable/pending-issues/issue-002-lib-runtime-spec-drift.md.
 */
function createUnknownCodeFailure(
  failure: ApiFailureLenient,
  response: Response,
  path: string,
): LaraApiError {
  const requestId = response.headers.get(HEADER.RequestId) ?? failure.Attributes.RequestId;
  const unknownCode = failure.Attributes.Error.ErrorCode;
  console.warn("Lara API unknown error code", {
    path,
    status: response.status,
    requestId,
    unknownCode,
  });
  const message = `${failure.Attributes.Error.ErrorMessage} [server code: ${unknownCode}]`;
  const errorId = failure.Attributes.ErrorId ?? response.headers.get("X-Error-Id") ?? undefined;
  const category = failure.Attributes.Category as
    | import("./lara-api-error").LaraErrorCategory
    | undefined;
  const err = new LaraApiError(
    message,
    ApiErrorCodeType.UnknownServerError,
    response.status,
    requestId,
    undefined,
    errorId,
    failure.Attributes.Error.Details,
    category,
  );
  if (failure.Attributes.OperationId) {
    err.operationId = failure.Attributes.OperationId;
  }

  return err;
}

function createFailure(failure: ApiFailure, response: Response, path: string): LaraApiError {
  const requestId = response.headers.get(HEADER.RequestId) ?? failure.Attributes.RequestId;
  const errorCode = failure.Attributes.Error.ErrorCode;
  const rateLimit =
    errorCode === ApiErrorCodeType.RateLimited ? readRateLimit(response.headers) : undefined;
  console.error("Lara API request failed", {
    path,
    status: response.status,
    requestId,
    errorCode,
    errorId: failure.Attributes.ErrorId,
  });
  const errorId = failure.Attributes.ErrorId ?? response.headers.get("X-Error-Id") ?? undefined;
  const category = failure.Attributes.Category as
    | import("./lara-api-error").LaraErrorCategory
    | undefined;
  const err = new LaraApiError(
    failure.Attributes.Error.ErrorMessage,
    errorCode,
    response.status,
    requestId,
    rateLimit,
    errorId,
    failure.Attributes.Error.Details,
    category,
  );
  if (failure.Attributes.OperationId) {
    err.operationId = failure.Attributes.OperationId;
  }

  return err;
}
