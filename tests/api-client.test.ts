/**
 * Vitest for `apiClient` dispatcher and `preview-transport` registry
 * (Plan 16 Step 31; the wider suite lives in Step 71).
 */

import { afterEach, describe, expect, it, vi } from "vitest";

import { apiClient, buildRequest } from "@/lib/api-client";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import {
  clearPreviewHandlersForTest,
  findMissingPreviewHandlers,
  registerPreviewHandler,
} from "@/lib/preview-transport";
import * as runtime from "@/lib/runtime-mode";
import { Operations } from "@/generated/api/operations";
import type { License } from "@/generated/api/schema";

function mockMode(mode: runtime.RuntimeMode, seed = "default"): void {
  vi.spyOn(runtime, "getRuntimeMode").mockReturnValue({
    Mode: mode,
    ApiBaseUrl: null,
    PreviewSeed: seed,
  });
}

afterEach(() => {
  clearPreviewHandlersForTest();
  vi.restoreAllMocks();
});

describe("buildRequest", () => {
  it("T-01: substitutes and URL-encodes path params", () => {
    const req = buildRequest("admin.licenses.show", { Id: "lic 7/x" });
    expect(req.Path).toBe("/api/admin/licenses/lic%207%2Fx");
    expect(req.Method).toBe("GET");
  });

  it("T-02: GET routes leftover params to Query, PATCH to Body", () => {
    const get = buildRequest("admin.licenses.list", { Cursor: "abc", Query: "acme" });
    expect(get.Body).toBeNull();
    expect(get.Query).toEqual({ Cursor: "abc", Query: "acme" });

    const patch = buildRequest("admin.users.update", { Id: "u1", IfMatch: 'W/"3"', DisplayName: "N" });
    expect(patch.Query).toEqual({});
    expect(patch.Body).toEqual({ IfMatch: 'W/"3"', DisplayName: "N" });
    expect(patch.Path).toBe("/api/admin/users/u1");
  });

  it("T-03: missing path param throws LaraApiError(ValidationFailed)", () => {
    try {
      buildRequest("admin.licenses.show", { Id: "" as unknown as string });
      throw new Error("expected throw");
    } catch (err) {
      expect(err).toBeInstanceOf(LaraApiError);
      expect((err as LaraApiError).errorCode).toBe(ApiErrorCodeType.ValidationFailed);
    }
  });
});

describe("apiClient.call (preview)", () => {
  it("T-04: dispatches to registered handler and returns typed response", async () => {
    mockMode("preview", "default");
    const now = new Date().toISOString();
    const fakeLicense = {
      Id: "lic-7",
      Serial: "LIC-7",
      Status: "active",
      CustomerName: "Acme",
      CustomerEmail: "a@b.co",
      ResellerId: null,
      IssuedAt: now,
      ExpiresAt: null,
      Features: [],
      MaxActivations: 1,
      ActiveActivations: 0,
      Version: 1,
      CreatedAt: now,
      UpdatedAt: now,
    } as unknown as License;
    const handler = vi.fn(async (_ctx) => fakeLicense);
    registerPreviewHandler("admin.licenses.show", handler);

    const res = await apiClient.call("admin.licenses.show", { Id: "lic-7" });
    expect(handler).toHaveBeenCalledOnce();
    expect(handler.mock.calls[0][0].Seed).toBe("default");
    expect(handler.mock.calls[0][0].RequestId).toMatch(/.+/);
    expect(res.Id).toBe("lic-7");
  });

  it("T-05: unregistered handler in preview throws LaraApiError(ServerError)", async () => {
    mockMode("preview");
    await expect(apiClient.call("admin.licenses.show", { Id: "x" })).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ServerError,
    });
  });
});

describe("apiClient.call (live)", () => {
  it("T-06: dev mode calls laraFetch, forwards If-Match, and collapses Results[0]", async () => {
    mockMode("dev");
    // Point base URL at a stable stub so the URL builder is deterministic.
    vi.stubEnv("VITE_LARA_API_BASE_URL", "http://api.test");
    const envelope = {
      Status: { IsSuccess: true, Code: 200, Message: "OK" },
      Attributes: { RequestId: "req-live-1", RequestedAt: new Date().toISOString() },
      Results: [{ Id: "lic-9", Version: 4, Name: "Acme" }],
    };
    const captured: { url?: string; init?: RequestInit } = {};
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      captured.url = String(input);
      captured.init = init;
      return new Response(JSON.stringify(envelope), {
        status: 200,
        headers: { "Content-Type": "application/json", "X-Request-Id": "req-live-1", ETag: 'W/"4"' },
      });
    });
    vi.stubGlobal("fetch", fetchMock);

    let capturedEtag: string | null = null;
    const res = await apiClient.call(
      "admin.licenses.update",
      { Id: "lic-9", IfMatch: 'W/"3"', Name: "Acme 2" },
      {
        ifMatch: 'W/"3"',
        onResponseHeaders: (h) => {
          capturedEtag = h.get("ETag");
        },
      },
    );

    expect(fetchMock).toHaveBeenCalledOnce();
    expect(captured.url).toBe("http://api.test/api/admin/licenses/lic-9");
    expect(captured.init?.method).toBe("PATCH");
    const sentHeaders = new Headers(captured.init?.headers as HeadersInit);
    expect(sentHeaders.get("If-Match")).toBe('W/"3"');
    expect(sentHeaders.get("X-Request-Id")).toMatch(/.+/);
    expect(capturedEtag).toBe('W/"4"');
    // Results[0] shape-map: single object, not an array.
    expect(res).toMatchObject({ Id: "lic-9", Version: 4, Name: "Acme" });
  });

  it("T-06b: live failure envelope raises LaraApiError with parsed code", async () => {
    mockMode("dev");
    vi.stubEnv("VITE_LARA_API_BASE_URL", "http://api.test");
    const failure = {
      Status: { IsSuccess: false, Code: 412, Message: "Precondition Failed" },
      Attributes: {
        RequestId: "req-live-2",
        RequestedAt: new Date().toISOString(),
        ErrorId: "err-1",
        Error: { ErrorCode: "PreconditionFailed", ErrorMessage: "stale", Details: [] },
      },
      Results: [],
    };
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response(JSON.stringify(failure), { status: 412 })),
    );
    await expect(
      apiClient.call("admin.licenses.update", { Id: "lic-9", IfMatch: 'W/"1"', Name: "x" }),
    ).rejects.toBeInstanceOf(LaraApiError);
  });
});

describe("preview coverage assertion (INV-RM-04)", () => {
  it("T-07: empty registry reports every operationId as missing", () => {
    const missing = findMissingPreviewHandlers();
    expect(missing.length).toBe(Object.keys(Operations).length);
    expect(missing).toContain("admin.licenses.show");
    expect(missing).toContain("auth.login");
  });
});

describe("apiClient.call: mode switching + error propagation (Step 71)", () => {
  it("T-08: production mode routes through the live transport (not preview)", async () => {
    mockMode("production");
    vi.stubEnv("VITE_LARA_API_BASE_URL", "http://api.test");
    const previewHandler = vi.fn(async () => ({ Id: "should-not-run" }));
    registerPreviewHandler("admin.licenses.show", previewHandler);

    const envelope = {
      Status: { IsSuccess: true, Code: 200, Message: "OK" },
      Attributes: { RequestId: "req-prod-1", RequestedAt: new Date().toISOString() },
      Results: [{ Id: "lic-prod", Version: 1, Name: "Prod" }],
    };
    const fetchMock = vi.fn(
      async () =>
        new Response(JSON.stringify(envelope), {
          status: 200,
          headers: { "Content-Type": "application/json", "X-Request-Id": "req-prod-1" },
        }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const res = await apiClient.call("admin.licenses.show", { Id: "lic-prod" });
    expect(previewHandler).not.toHaveBeenCalled();
    expect(fetchMock).toHaveBeenCalledOnce();
    expect(res).toMatchObject({ Id: "lic-prod" });
  });

  it("T-09: live 429 surfaces Retry-After via onResponseHeaders", async () => {
    mockMode("dev");
    vi.stubEnv("VITE_LARA_API_BASE_URL", "http://api.test");
    const failure = {
      Status: { IsSuccess: false, Code: 429, Message: "Too Many Requests" },
      Attributes: {
        RequestId: "req-live-429",
        RequestedAt: new Date().toISOString(),
        ErrorId: "err-429",
        Error: { ErrorCode: "RateLimited", ErrorMessage: "slow down", Details: [] },
      },
      Results: [],
    };
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(JSON.stringify(failure), {
            status: 429,
            headers: { "Content-Type": "application/json", "Retry-After": "7" },
          }),
      ),
    );
    let retryAfter: string | null = null;
    await expect(
      apiClient.call(
        "admin.licenses.list",
        { Cursor: "abc" },
        {
          onResponseHeaders: (h) => {
            retryAfter = h.get("Retry-After");
          },
        },
      ),
    ).rejects.toBeInstanceOf(LaraApiError);
    expect(retryAfter).toBe("7");
  });

  it("T-10: preview handler that throws LaraApiError preserves errorCode (INV-RM-05)", async () => {
    mockMode("preview");
    registerPreviewHandler("admin.licenses.update", async () => {
      throw new LaraApiError("stale version", ApiErrorCodeType.PreconditionFailed, 412);
    });
    await expect(
      apiClient.call("admin.licenses.update", { Id: "lic-x", IfMatch: 'W/"1"', Name: "n" }),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.PreconditionFailed,
      httpStatus: 412,
    });
  });
});
