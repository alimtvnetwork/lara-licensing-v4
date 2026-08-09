import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { z } from "zod";

import { laraFetch, NETWORK_FAILURE_STATUS } from "@/lib/lara-fetch";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import {
  freezeRuntimeMode,
  getCompileTimeDefault,
  resetRuntimeMode,
} from "@/lib/runtime-mode";

const ItemSchema = z.object({ Id: z.string() });

const originalFetch = globalThis.fetch;
const originalBaseUrl = import.meta.env.VITE_LARA_API_BASE_URL;

function mockOnce(body: unknown, init: ResponseInit): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => new Response(JSON.stringify(body), init)),
  );
}

beforeEach(() => {
  import.meta.env.VITE_LARA_API_BASE_URL = "http://api.test";
  // Compile-time default is "preview", which trips the preview-bypass
  // guards in `laraFetch` and `requestLaraApi`. These tests exercise the
  // live transport, so pin mode to "dev" for the duration of each case.
  freezeRuntimeMode({ ...getCompileTimeDefault(), Mode: "dev" });
});

afterEach(() => {
  vi.unstubAllGlobals();
  globalThis.fetch = originalFetch;
  import.meta.env.VITE_LARA_API_BASE_URL = originalBaseUrl;
  resetRuntimeMode();
});


describe("laraFetch", () => {
  it("parses a 2xx envelope and returns Results", async () => {
    mockOnce(
      {
        Status: { IsSuccess: true, Code: 200, Message: "ok" },
        Attributes: { RequestId: "req-ok", RequestedAt: "2026-01-01T00:00:00Z" },
        Results: [{ Id: "a" }, { Id: "b" }],
      },
      { status: 200, headers: { "X-Request-Id": "req-ok" } },
    );
    const out = await laraFetch("/Test", ItemSchema);
    expect(out).toEqual([{ Id: "a" }, { Id: "b" }]);
  });

  it("preserves Details on a 4xx ValidationFailed envelope", async () => {
    const details = [{ Field: "Email", Value: "Required" }];
    mockOnce(
      {
        Status: { IsSuccess: false, Code: 400, Message: "bad" },
        Attributes: {
          RequestId: "req-4xx",
          RequestedAt: "2026-01-01T00:00:00Z",
          Error: {
            ErrorCode: ApiErrorCodeType.ValidationFailed,
            ErrorMessage: "validation failed",
            Details: details,
          },
        },
        Results: [],
      },
      { status: 400, headers: { "X-Request-Id": "req-4xx" } },
    );
    await expect(laraFetch("/Test", ItemSchema)).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ValidationFailed,
      httpStatus: 400,
      requestId: "req-4xx",
      details,
      errorId: undefined,
    });
  });

  it("preserves ErrorId on a 5xx ServerError envelope", async () => {
    const errorId = "11111111-2222-4333-8444-555555555555";
    mockOnce(
      {
        Status: { IsSuccess: false, Code: 500, Message: "boom" },
        Attributes: {
          RequestId: "req-5xx",
          RequestedAt: "2026-01-01T00:00:00Z",
          ErrorId: errorId,
          Error: { ErrorCode: ApiErrorCodeType.ServerError, ErrorMessage: "boom" },
        },
        Results: [],
      },
      { status: 500, headers: { "X-Request-Id": "req-5xx" } },
    );
    await expect(laraFetch("/Test", ItemSchema)).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ServerError,
      httpStatus: 500,
      requestId: "req-5xx",
      errorId,
    });
  });

  it("wraps network failures as LaraApiError(ServerError, 0)", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );
    const err = await laraFetch("/Test", ItemSchema).catch((e) => e);
    expect(err).toBeInstanceOf(LaraApiError);
    expect((err as LaraApiError).errorCode).toBe(ApiErrorCodeType.ServerError);
    expect((err as LaraApiError).httpStatus).toBe(NETWORK_FAILURE_STATUS);
    expect((err as LaraApiError).message).toContain("Failed to fetch");
  });

  // Plan 11 step 34: a malformed body (non-LaraApiError throw bubbling
  // up from requestLaraApi, e.g. an upstream 200 that returned HTML
  // instead of an envelope) must still exit as a LaraApiError so the
  // error store / Global Error Modal never see a raw parse error.
  // httpStatus=0 is the canonical client-synthesized marker; a
  // dedicated NetworkUnavailable code would break BE/FE closed-set
  // parity (scripts/check-error-code-parity.mjs) because the backend
  // never emits it, so we reuse ServerError + status 0.
  it("wraps malformed envelope responses as LaraApiError(ServerError, 0)", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response("<!doctype html><html>gateway error</html>", {
            status: 200,
            headers: { "content-type": "text/html" },
          }),
      ),
    );
    const err = await laraFetch("/Test", ItemSchema).catch((e) => e);
    expect(err).toBeInstanceOf(LaraApiError);
    expect((err as LaraApiError).errorCode).toBe(ApiErrorCodeType.ServerError);
  });
});
