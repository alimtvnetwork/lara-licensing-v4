/**
 * Typed API client dispatcher (Plan 16 Step 31).
 *
 * Single entrypoint every UI call site MUST use:
 *
 *   const res = await apiClient.call("admin.licenses.show", { Id: "lic-7" });
 *
 * Responsibilities:
 *   1. Resolve the operation from `Operations` (typed `Method` + `Path`).
 *   2. Substitute `:Name` path params from `params` (URL-encoded).
 *   3. Split remaining params: body for POST/PATCH/PUT, query for GET/DELETE.
 *   4. Select transport by current runtime mode:
 *        - preview -> `dispatchPreview()` in `./preview-transport.ts`.
 *        - dev/production -> live transport (Step 32 folds `laraFetch`
 *          here; Step 31 surfaces an explicit LaraApiError so silent
 *          fall-through is impossible).
 *   5. Forward `If-Match` header for optimistic-concurrency mutations.
 *
 * INV-RM-05: preview and live paths return the same typed `Response`
 * shape; envelope-shaping happens INSIDE each transport, not here.
 *
 * NB: live transport folds `lara-fetch.ts` (Plan 16 Step 32). Envelope
 * parsing, X-Request-Id, bearer + one-shot refresh, error-store capture
 * and Retry-After propagation all live in `laraFetch`; this module only
 * shape-maps the `Results[]` array to the typed single-object
 * `OperationResponse<K>` (or `EmptyResponse` when Results is empty).
 */

import { z } from "zod";

import {
  Operations,
  type OperationDefinition,
  type HttpMethod,
  type OperationId,
  type OperationRequest,
  type OperationResponse,
} from "@/generated/api/operations";
import { ApiErrorCodeType, LaraApiError } from "./lara-api-error";
import { getLaraAccessToken } from "./lara-api-session";
import { HttpMethodType } from "./lara-api-client";
import { laraFetch } from "./lara-fetch";
import { dispatchPreview, type PreviewSeed } from "./preview-transport";
import {
  applyPreviewScenario,
  getPreviewScenario,
  parseScenarioHeader,
  PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S,
} from "./preview-scenario";
import { getRuntimeMode } from "./runtime-mode";

const PATH_PARAM_RE = /:([A-Za-z][A-Za-z0-9]*)/g;
const METHODS_WITH_BODY: ReadonlySet<HttpMethod> = new Set(["POST", "PATCH", "PUT"]);

export interface ApiCallOptions {
  signal?: AbortSignal;
  ifMatch?: string;
  headers?: Record<string, string>;
  onAttributes?: (attributes: Record<string, unknown>) => void;
  onResponseHeaders?: (headers: Headers) => void;
}

export interface BuiltRequest {
  Method: HttpMethod;
  Path: string;
  Query: Record<string, string>;
  Body: Record<string, unknown> | null;
}

function requireParam(template: string, key: string, value: unknown): string {
  if (value === undefined || value === null || value === "") {
    throw new LaraApiError(
      `api-client: missing path param ":${key}" for ${template}`,
      ApiErrorCodeType.ValidationFailed,
      0,
    );
  }

  return encodeURIComponent(String(value));
}

function substitutePath(
  template: string,
  params: Record<string, unknown>,
): { Path: string; Remaining: Record<string, unknown> } {
  const remaining: Record<string, unknown> = { ...params };
  const Path = template.replace(PATH_PARAM_RE, (_match, key: string) => {
    const encoded = requireParam(template, key, remaining[key]);
    delete remaining[key];

    return encoded;
  });

  return { Path, Remaining: remaining };
}

function splitByMethod(
  method: HttpMethod,
  remaining: Record<string, unknown>,
): { Body: Record<string, unknown> | null; Query: Record<string, string> } {
  if (METHODS_WITH_BODY.has(method)) return { Body: remaining, Query: {} };
  const Query: Record<string, string> = {};
  for (const [k, v] of Object.entries(remaining)) {
    if (v === undefined || v === null) continue;
    Query[k] = String(v);
  }

  return { Body: null, Query };
}

export function buildRequest<K extends OperationId>(
  id: K,
  params: OperationRequest<K>,
): BuiltRequest {
  const op = Operations[id] as OperationDefinition<OperationRequest<K>, OperationResponse<K>>;
  const source = (params ?? {}) as Record<string, unknown>;
  const { Path, Remaining } = substitutePath(op.Path, source);
  const { Body, Query } = splitByMethod(op.Method, Remaining);

  return { Method: op.Method, Path, Query, Body };
}

function newRequestId(): string {
  const g = globalThis as { crypto?: { randomUUID?: () => string } };
  if (g.crypto?.randomUUID) return g.crypto.randomUUID();

  return `req-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
}

async function callPreview<K extends OperationId>(
  id: K,
  params: OperationRequest<K>,
  opts: ApiCallOptions,
  seed: PreviewSeed,
): Promise<OperationResponse<K>> {
  const signal = opts.signal ?? new AbortController().signal;
  const requestId = newRequestId();
  const headerScenario = parseScenarioHeader(opts.headers);
  const scenario = headerScenario !== undefined ? headerScenario : getPreviewScenario();
  // Header-triggered rate-limit uses the short (3 s) Retry-After so QA
  // can observe the submit-lock countdown; process-global toggle keeps
  // the 30 s window used by the admin runtime page.
  const retryAfterOverride =
    headerScenario === "rate-limited" ? PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S : undefined;

  return applyPreviewScenario(
    scenario,
    requestId,
    signal,
    () =>
      dispatchPreview(id, {
        Params: params,
        Headers: opts.ifMatch
          ? { ...(opts.headers ?? {}), "If-Match": opts.ifMatch }
          : { ...(opts.headers ?? {}) },
        Signal: signal,
        Seed: seed,
        Scenario: scenario,
        RequestId: requestId,
        Token: getLaraAccessToken() ?? undefined,
      }),
    retryAfterOverride,
  );
}

const METHOD_MAP: Readonly<Record<HttpMethod, HttpMethodType>> = {
  GET: HttpMethodType.Get,
  POST: HttpMethodType.Post,
  PATCH: HttpMethodType.Patch,
  PUT: HttpMethodType.Put,
  DELETE: HttpMethodType.Delete,
};

function toQueryString(query: Record<string, string>): string {
  const entries = Object.entries(query);
  if (entries.length === 0) return "";
  const params = new URLSearchParams();
  for (const [k, v] of entries) params.set(k, v);

  return `?${params.toString()}`;
}

async function callLive<K extends OperationId>(
  id: K,
  params: OperationRequest<K>,
  opts: ApiCallOptions,
): Promise<OperationResponse<K>> {
  const { Method, Path, Query, Body } = buildRequest(id, params);
  const url = `${Path}${toQueryString(Query)}`;
  const extraHeaders: Record<string, string> = { ...(opts.headers ?? {}) };
  extraHeaders["X-Lara-Operation"] = id;
  if (opts.ifMatch) extraHeaders["If-Match"] = opts.ifMatch;
  // Passthrough schema: typed contract lives in `Operations`; envelope
  // shape is validated inside `parseLaraResponse`. A second Zod pass
  // would either duplicate the hand-written baseline or reject valid
  // server payloads whose optional keys are absent.
  const results = await laraFetch<unknown>(url, z.unknown(), {
    method: METHOD_MAP[Method],
    body: Body ?? undefined,
    signal: opts.signal,
    headers: Object.keys(extraHeaders).length > 0 ? extraHeaders : undefined,
    onResponseHeaders: opts.onResponseHeaders,
    onAttributes: opts.onAttributes,
  });
  // OperationResponse<K> is defined as `Results[0]` per
  // `src/generated/api/schema.d.ts` header. `EmptyResponse` ops return
  // an empty Results array from the backend; collapse to `{}` so
  // callers can safely destructure without an undefined guard.
  const first = results.length > 0 ? results[0] : ({} as unknown);

  return first as OperationResponse<K>;
}

export const apiClient = {
  async call<K extends OperationId>(
    id: K,
    params: OperationRequest<K>,
    opts: ApiCallOptions = {},
  ): Promise<OperationResponse<K>> {
    const mode = getRuntimeMode();
    if (mode.Mode === "preview") {
      const seed = (mode.PreviewSeed as PreviewSeed) ?? "default";

      return callPreview(id, params, opts, seed);
    }

    return callLive(id, params, opts);
  },
} as const;

export type { OperationId, OperationRequest, OperationResponse };
