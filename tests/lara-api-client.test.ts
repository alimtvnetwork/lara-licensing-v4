import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { z } from "zod";

import { ApiErrorCodeType } from "@/lib/lara-api-error";
import {
  clearLaraSession,
  getLaraAccessToken,
  getLaraRefreshToken,
  setLaraAccessToken,
  setLaraRefreshToken,
} from "@/lib/lara-api-session";

const ItemSchema = z.object({ Id: z.string() });

function successBody(results: Array<{ Id: string }>): string {
  return JSON.stringify({
    Status: { IsSuccess: true, Code: 200, Message: "ok" },
    Attributes: { RequestId: "env-req", RequestedAt: "2026-01-01T00:00:00Z" },
    Results: results,
  });
}

function errorBody(code: ApiErrorCodeType, httpCode: number): string {
  return JSON.stringify({
    Status: { IsSuccess: false, Code: httpCode, Message: "err" },
    Attributes: {
      RequestId: "env-req",
      RequestedAt: "2026-01-01T00:00:00Z",
      Error: { ErrorCode: code, ErrorMessage: code },
    },
    Results: [],
  });
}

function jsonResponse(body: string, status: number): Response {
  return new Response(body, { status, headers: { "Content-Type": "application/json" } });
}

async function loadClient() {
  vi.resetModules();
  const rt = await import("@/lib/runtime-mode");
  rt.freezeRuntimeMode({ Mode: "dev", ApiBaseUrl: null, PreviewSeed: "default" });
  return await import("@/lib/lara-api-client");
}

beforeEach(() => {
  vi.stubEnv("VITE_LARA_API_BASE_URL", "https://lara.test");
  clearLaraSession();
  setLaraAccessToken("access-old");
  setLaraRefreshToken("refresh-old");
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
  clearLaraSession();
});

describe("requestLaraApi 401 refresh + retry", () => {
  it("refreshes on AuthTokenExpired then retries the original call with the new access token", async () => {
    const { requestLaraApi } = await loadClient();
    const calls: Array<{ url: string; auth: string | null }> = [];
    const fetchMock = vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
      const headers = new Headers(init?.headers);
      calls.push({ url: String(url), auth: headers.get("Authorization") });
      if (calls.length === 1) return jsonResponse(errorBody(ApiErrorCodeType.AuthTokenExpired, 401), 401);
      if (calls.length === 2) {
        return jsonResponse(
          JSON.stringify({
            Status: { IsSuccess: true, Code: 200, Message: "ok" },
            Attributes: { RequestId: "env-req", RequestedAt: "2026-01-01T00:00:00Z" },
            Results: [
              { AccessToken: "access-new", RefreshToken: "refresh-new", TokenType: "Bearer", ExpiresIn: 3600 },
            ],
          }),
          200,
        );
      }
      return jsonResponse(successBody([{ Id: "ok" }]), 200);
    });
    vi.stubGlobal("fetch", fetchMock);

    const out = await requestLaraApi("/Widgets", ItemSchema);

    expect(out).toEqual([{ Id: "ok" }]);
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(calls[0].auth).toBe("Bearer access-old");
    expect(calls[1].url).toContain("/Auth/Refresh");
    expect(calls[2].auth).toBe("Bearer access-new");
    expect(getLaraAccessToken()).toBe("access-new");
    expect(getLaraRefreshToken()).toBe("refresh-new");
  });

  it("clears the session and rethrows original error when refresh is fatally rejected (AuthRefreshReused)", async () => {
    const { requestLaraApi } = await loadClient();
    const warnSpy = vi.spyOn(console, "warn").mockImplementation(() => {});
    const fetchMock = vi.fn(async (_u: RequestInfo | URL, _init?: RequestInit) => {
      const n = fetchMock.mock.calls.length;
      if (n === 1) return jsonResponse(errorBody(ApiErrorCodeType.AuthTokenExpired, 401), 401);
      return jsonResponse(errorBody(ApiErrorCodeType.AuthRefreshReused, 401), 401);
    });
    vi.stubGlobal("fetch", fetchMock);

    await expect(requestLaraApi("/Widgets", ItemSchema)).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.AuthTokenExpired,
    });
    expect(warnSpy).toHaveBeenCalled();
    expect(getLaraAccessToken()).toBeUndefined();
    expect(getLaraRefreshToken()).toBeUndefined();
  });

  it("preserves session and surfaces error when refresh fails transiently (ServerError)", async () => {
    const { requestLaraApi } = await loadClient();
    const errorSpy = vi.spyOn(console, "error").mockImplementation(() => {});
    const fetchMock = vi.fn(async () => {
      const n = fetchMock.mock.calls.length;
      if (n === 1) return jsonResponse(errorBody(ApiErrorCodeType.AuthTokenExpired, 401), 401);
      return jsonResponse(errorBody(ApiErrorCodeType.ServerError, 500), 500);
    });
    vi.stubGlobal("fetch", fetchMock);

    await expect(requestLaraApi("/Widgets", ItemSchema)).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ServerError,
    });
    expect(errorSpy).toHaveBeenCalled();
    // Session tokens preserved on transient refresh failure.
    expect(getLaraAccessToken()).toBe("access-old");
    expect(getLaraRefreshToken()).toBe("refresh-old");
  });

  it("does not refresh on non-AuthTokenExpired errors", async () => {
    const { requestLaraApi } = await loadClient();
    const fetchMock = vi.fn(async () =>
      jsonResponse(errorBody(ApiErrorCodeType.ValidationFailed, 400), 400),
    );
    vi.stubGlobal("fetch", fetchMock);

    await expect(requestLaraApi("/Widgets", ItemSchema)).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ValidationFailed,
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("does not retry a 401 originating from /Auth/Refresh itself", async () => {
    const { requestLaraApi } = await loadClient();
    const fetchMock = vi.fn(async () =>
      jsonResponse(errorBody(ApiErrorCodeType.AuthTokenExpired, 401), 401),
    );
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      requestLaraApi(
        "/Auth/Refresh",
        z.object({ AccessToken: z.string(), RefreshToken: z.string(), TokenType: z.literal("Bearer"), ExpiresIn: z.number() }),
        { method: "POST" as never, body: { RefreshToken: "refresh-old" } },
      ),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.AuthTokenExpired });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("deduplicates concurrent refreshes into a single in-flight refresh call", async () => {
    const { requestLaraApi } = await loadClient();
    let refreshCalls = 0;
    const fetchMock = vi.fn(async (url: RequestInfo | URL) => {
      const u = String(url);
      if (u.endsWith("/Auth/Refresh")) {
        refreshCalls++;
        return jsonResponse(
          JSON.stringify({
            Status: { IsSuccess: true, Code: 200, Message: "ok" },
            Attributes: { RequestId: "env-req", RequestedAt: "2026-01-01T00:00:00Z" },
            Results: [
              { AccessToken: "access-new", RefreshToken: "refresh-new", TokenType: "Bearer", ExpiresIn: 3600 },
            ],
          }),
          200,
        );
      }
      // First call for each path returns 401; retry returns success.
      const priorForPath = fetchMock.mock.calls.filter((c) => String(c[0]) === u).length;
      if (priorForPath === 1) return jsonResponse(errorBody(ApiErrorCodeType.AuthTokenExpired, 401), 401);
      return jsonResponse(successBody([{ Id: u }]), 200);
    });
    vi.stubGlobal("fetch", fetchMock);

    const [a, b] = await Promise.all([
      requestLaraApi("/A", ItemSchema),
      requestLaraApi("/B", ItemSchema),
    ]);

    expect(a[0].Id).toContain("/A");
    expect(b[0].Id).toContain("/B");
    expect(refreshCalls).toBe(1);
  });

  it("F2: on AuthRefreshRaceLost, re-reads rotated token from storage and retries refresh once", async () => {
    const { requestLaraApi } = await loadClient();
    const warnSpy = vi.spyOn(console, "warn").mockImplementation(() => {});
    let refreshCalls = 0;
    const fetchMock = vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
      const u = String(url);
      if (u.endsWith("/Auth/Refresh")) {
        refreshCalls++;
        const body = JSON.parse(String(init?.body ?? "{}")) as { RefreshToken: string };
        if (refreshCalls === 1) {
          // Simulate sibling tab rotating storage first.
          setLaraRefreshToken("refresh-rotated");
          return jsonResponse(errorBody(ApiErrorCodeType.AuthRefreshRaceLost, 409), 409);
        }
        expect(body.RefreshToken).toBe("refresh-rotated");
        return jsonResponse(
          JSON.stringify({
            Status: { IsSuccess: true, Code: 200, Message: "ok" },
            Attributes: { RequestId: "env-req", RequestedAt: "2026-01-01T00:00:00Z" },
            Results: [
              { AccessToken: "access-new", RefreshToken: "refresh-new", TokenType: "Bearer", ExpiresIn: 3600 },
            ],
          }),
          200,
        );
      }
      const prior = fetchMock.mock.calls.filter((c) => String(c[0]) === u).length;
      if (prior === 1) return jsonResponse(errorBody(ApiErrorCodeType.AuthTokenExpired, 401), 401);
      return jsonResponse(successBody([{ Id: "ok" }]), 200);
    });
    vi.stubGlobal("fetch", fetchMock);

    const out = await requestLaraApi("/Widgets", ItemSchema);
    expect(out).toEqual([{ Id: "ok" }]);
    expect(refreshCalls).toBe(2);
    expect(getLaraAccessToken()).toBe("access-new");
    expect(getLaraRefreshToken()).toBe("refresh-new");
    expect(warnSpy).toHaveBeenCalled();
  });

  it("F2: on AuthRefreshRaceLost with no rotated token in storage, preserves session and propagates", async () => {
    const { requestLaraApi } = await loadClient();
    vi.spyOn(console, "warn").mockImplementation(() => {});
    const fetchMock = vi.fn(async (url: RequestInfo | URL) => {
      const u = String(url);
      if (u.endsWith("/Auth/Refresh")) {
        return jsonResponse(errorBody(ApiErrorCodeType.AuthRefreshRaceLost, 409), 409);
      }
      return jsonResponse(errorBody(ApiErrorCodeType.AuthTokenExpired, 401), 401);
    });
    vi.stubGlobal("fetch", fetchMock);

    await expect(requestLaraApi("/Widgets", ItemSchema)).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.AuthTokenExpired,
    });
    expect(getLaraAccessToken()).toBe("access-old");
    expect(getLaraRefreshToken()).toBe("refresh-old");
  });
});

