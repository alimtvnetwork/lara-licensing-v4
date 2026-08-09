/**
 * Backend health probe unit tests (v0.673.0).
 *
 * Covers the contract that guards runtime flips into production:
 *   - 200 OK => `Ok: true` and RequestId echo.
 *   - 503 envelope => `Ok: false`, envelope Message surfaced.
 *   - Transport failure (network throw) => `Ok: false`, `Status: 0`.
 *   - Abort timeout => `Ok: false`, `Status: 0`.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";

import { HEALTH_PATH, HEALTH_TIMEOUT_MS, probeBackendHealth } from "../src/lib/backend-health";

const BASE_URL = "https://backend.example.test";

function jsonResponse(body: unknown, init: ResponseInit): Response {
  return new Response(JSON.stringify(body), {
    ...init,
    headers: { "Content-Type": "application/json", ...(init.headers ?? {}) },
  });
}

describe("probeBackendHealth", () => {
  beforeEach(() => vi.restoreAllMocks());
  afterEach(() => vi.restoreAllMocks());

  test("returns Ok on 200 envelope and echoes RequestId", async () => {
    const fetchMock = vi.fn(async (input: unknown) => {
      expect(String(input)).toBe(`${BASE_URL}${HEALTH_PATH}`);
      return jsonResponse({ Success: true }, { status: 200, headers: { "X-Request-Id": "req-01" } });
    });
    vi.stubGlobal("fetch", fetchMock);
    const result = await probeBackendHealth(BASE_URL);
    expect(result).toEqual({ Ok: true, Status: 200, RequestId: "req-01", Message: null });
  });

  test("surfaces envelope Message on 503", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(
        { Success: false, Message: "Service Unavailable", Errors: [{ ErrorMessage: "Root down" }] },
        { status: 503 },
      ),
    );
    vi.stubGlobal("fetch", fetchMock);
    const result = await probeBackendHealth(BASE_URL);
    expect(result.Ok).toBe(false);
    expect(result.Status).toBe(503);
    expect(result.Message).toBe("Service Unavailable");
  });

  test("captures transport failure without throwing", async () => {
    const fetchMock = vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    });
    vi.stubGlobal("fetch", fetchMock);
    const result = await probeBackendHealth(BASE_URL);
    expect(result).toEqual({ Ok: false, Status: 0, RequestId: null, Message: "Failed to fetch" });
  });

  test("aborts on timeout", async () => {
    const fetchMock = vi.fn(async (_url: unknown, init: RequestInit) => {
      return await new Promise<Response>((_, reject) => {
        init.signal?.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
      });
    });
    vi.stubGlobal("fetch", fetchMock);
    const result = await probeBackendHealth(BASE_URL, 10);
    expect(result.Ok).toBe(false);
    expect(result.Status).toBe(0);
    expect(HEALTH_TIMEOUT_MS).toBeGreaterThan(0);
  });
});
