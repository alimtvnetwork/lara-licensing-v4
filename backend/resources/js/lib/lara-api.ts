// Plan 06 step 66. Minimal envelope-aware fetch client for the Inertia
// console. Mirrors the SPA contract in src/lib/lara-client.ts: every
// mutating request carries X-Request-Id, POSTs carry Idempotency-Key,
// and errors surface `Error.Code` + `Error.Message` from the envelope.
//
// Step 74 moved the correlation-id minter into lib/lara-request-id.ts so the
// axios interceptor in bootstrap.ts and this client agree on the format.

import { mintRequestId } from "./lara-request-id";
import { captureEtag, readEtag } from "./lara-etag";
import { idempotencyKeyFor, releaseAttempt } from "./lara-idempotency";

export interface LaraEnvelope<T> {
  Success: boolean;
  Message: string;
  Results: T[];
  Attributes?: Record<string, unknown>;
  Error?: { Code: string; Message: string; Details?: unknown } | null;
  RequestId?: string;
  OperationId?: string;
}

/**
 * Closed-set error identifiers from `spec/21-app/12-error-taxonomy.md`.
 * Spec 49 §5 AC-CONFLICT-005 forbids branching on HTTP status or message
 * substrings, so conflict detection compares against this constant.
 */
export const ApiErrorCode = {
  PreconditionFailed: "PreconditionFailed",
  PreconditionRequired: "PreconditionRequired",
  ValidationFailed: "ValidationFailed",
} as const;

export class LaraApiError extends Error {

  readonly code: string;
  readonly requestId: string;
  readonly operationId: string;

  constructor(code: string, message: string, requestId: string, operationId: string) {
    super(message);
    this.name = "LaraApiError";
    this.code = code;
    this.requestId = requestId;
    this.operationId = operationId;
  }
}

/**
 * Plan 06 step 74: the correlation-id minter now lives in
 * `lib/lara-request-id.ts` so this fetch client and the axios interceptor in
 * `bootstrap.ts` produce identically shaped ids. The old local fallback
 * (`Date.now()`-based) could emit fewer than 16 characters, which
 * `RequestIdMiddleware::REQUEST_ID_REGEX` rejects with `RequestIdMissing`.
 */
const uuid = mintRequestId;

function csrfToken(): string {
  if (typeof document === "undefined") return "";
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "";
}

interface RequestOptions {
  method?: "GET" | "POST" | "PATCH" | "DELETE";
  body?: unknown;
  idempotencyKey?: string;
  ifMatch?: string;
}

export async function laraRequest<T>(path: string, options: RequestOptions = {}): Promise<LaraEnvelope<T>> {
  const method = options.method ?? "GET";
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Request-Id": uuid(),
    "X-Requested-With": "XMLHttpRequest",
  };
  const token = csrfToken();
  if (token !== "") headers["X-CSRF-TOKEN"] = token;
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  // IdempotencyKeyMiddleware::REQUIRED_PREFIXES covers every mutating verb
  // (POST/PUT/PATCH/DELETE) under `api/admin/licenses`, so PATCH and DELETE
  // need the header too or they 400 with IdempotencyKeyRequired.
  // Plan 06 step 76: the key is per ATTEMPT, not per call. `idempotencyKeyFor`
  // returns the same key while an identical mutation keeps failing, so a retry
  // after a lost response replays the stored snapshot instead of executing
  // twice (IdempotencyKeyMiddleware AC-IDL-004).
  if (method !== "GET") {
    headers["Idempotency-Key"] =
      options.idempotencyKey ?? idempotencyKeyFor(method, path, options.body);
  }

  // Plan 06 step 75: prefer the freshest captured validator over whatever the
  // caller held (an Inertia page prop can be several mutations stale). Falls
  // back to the caller value, then to nothing at all - never to a wildcard.
  const ifMatch = readEtag(path) ?? options.ifMatch;
  if (ifMatch) headers["If-Match"] = ifMatch;

  const response = await fetch(path, {
    method,
    headers,
    credentials: "same-origin",
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  // Response-side capture: `EtagMiddleware` only emits ETag on GET JSON, so
  // this is the single point where the console learns the current validator.
  captureEtag(path, response.headers);

  let envelope: LaraEnvelope<T> | null = null;
  try {
    envelope = (await response.json()) as LaraEnvelope<T>;
  } catch {
    envelope = null;
  }

  if (!response.ok || envelope === null || envelope.Success !== true) {
    throw new LaraApiError(
      envelope?.Error?.Code ?? `Http${response.status}`,
      envelope?.Error?.Message ?? envelope?.Message ?? "Request failed.",
      envelope?.RequestId ?? "unknown",
      envelope?.OperationId ?? "unknown",
    );
  }
  // Confirmed success: the attempt is over, so a deliberate repeat of the same
  // mutation later is a new attempt with a new key, not a swallowed replay.
  if (method !== "GET" && options.idempotencyKey === undefined) {
    releaseAttempt(method, path, options.body);
  }
  return envelope;
}
