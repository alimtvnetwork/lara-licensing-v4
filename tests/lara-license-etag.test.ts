import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";

/**
 * Locks the ETag / If-Match wire behaviour for `GET /Licenses/{id}` and its
 * paired `PATCH` / `DELETE` in `src/lib/lara-license.ts`, per
 * spec/21-app/11-api-contracts/09-concurrency-control.md §Request rules
 * and §Scope. Root cause these tests prevent regressing: before v0.182.0
 * the client discarded response headers, so `updateLicense` / `deleteLicense`
 * had no ETag to send and the server contract (AC-CONCUR-002 / 003) was
 * unenforceable at the UI layer.
 */

import { requestLaraApi } from "@/lib/lara-api-client";

const originalFetch = globalThis.fetch;
const BASE = "https://api.example.test";

beforeEach(() => {
  (import.meta.env as unknown as Record<string, string>).VITE_LARA_API_BASE_URL = BASE;
});

afterEach(() => {
  globalThis.fetch = originalFetch;
  vi.restoreAllMocks();
});

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { "Content-Type": "application/json", ...(init.headers ?? {}) },
    ...init,
  });
}

const licenseBody = {
  Status: { IsSuccess: true, Code: 200, Message: "ok" },
  Attributes: { RequestId: "req-1", RequestedAt: "2026-07-22T00:00:00.000Z" },
  Results: [
    {
      LicenseId: 1,
      LicenseCategoryId: 1,
      LicenseTierId: 1,
      EnvironmentId: 1,
      IssuedByUserId: 1,
      ProductVersion: "1.0.0",
      IsActive: true,
      IssuedAt: "2026-07-22T00:00:00.000Z",
      IsSingleUse: false,
    },
  ],
};

const deleteBody = {
  Status: { IsSuccess: true, Code: 200, Message: "ok" },
  Attributes: { RequestId: "req-2", RequestedAt: "2026-07-22T00:00:00.000Z" },
  Results: [{ LicenseId: 1, IsDeleted: true }],
};

describe("requestLaraApi onResponseHeaders", () => {
  it("captures ETag from a 200 response before parsing", async () => {
    const etag = "\"3fa9c1b2e4d5\"";
    globalThis.fetch = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse(licenseBody, { headers: { ETag: etag } })) as typeof fetch;
    let captured: string | undefined;
    const [row] = await requestLaraApi("/Licenses/1", (await import("zod")).z.any(), {
      onResponseHeaders: (h) => {
        captured = h.get("ETag") ?? undefined;
      },
    });
    expect(captured).toBe(etag);
    expect(row).toBeDefined();
  });
});

describe("getLicense and licenseQueryOptions surface the ETag", () => {
  it("licenseQueryOptions.queryFn returns { license, etag }", async () => {
    const etag = "\"abc123\"";
    globalThis.fetch = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse(licenseBody, { headers: { ETag: etag } })) as typeof fetch;
    const { licenseQueryOptions } = await import("@/lib/lara-license");
    const opts = licenseQueryOptions(1);
    const result = await opts.queryFn!({
      queryKey: opts.queryKey,
      signal: new AbortController().signal,
      meta: undefined,
      client: undefined as never,
    } as never);
    expect(result).toMatchObject({ etag });
    expect((result as { license: { LicenseId: number } }).license.LicenseId).toBe(1);
  });

  it("returns undefined etag when the server omits the header", async () => {
    globalThis.fetch = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse(licenseBody)) as typeof fetch;
    const { licenseQueryOptions } = await import("@/lib/lara-license");
    const opts = licenseQueryOptions(1);
    const result = await opts.queryFn!({
      queryKey: opts.queryKey,
      signal: new AbortController().signal,
      meta: undefined,
      client: undefined as never,
    } as never);
    expect((result as { etag: string | undefined }).etag).toBeUndefined();
  });
});

describe("updateLicense and deleteLicense require If-Match at the type level", () => {
  it("sends If-Match verbatim on PATCH", async () => {
    const etag = "\"etag-patch\"";
    globalThis.fetch = vi.fn().mockResolvedValueOnce(jsonResponse(licenseBody)) as typeof fetch;
    const { updateLicense } = await import("@/lib/lara-license");
    await updateLicense(1, { IsActive: false }, "idem-1", etag);
    const call = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0]!;
    const init = call[1] as RequestInit;
    const headers = init.headers as Headers;
    expect(headers.get("If-Match")).toBe(etag);
    expect(headers.get("Idempotency-Key")).toBe("idem-1");
  });

  it("sends If-Match verbatim on DELETE", async () => {
    const etag = "\"etag-delete\"";
    globalThis.fetch = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse(deleteBody)) as typeof fetch;
    const { deleteLicense } = await import("@/lib/lara-license");
    await deleteLicense(1, "idem-2", etag);
    const call = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0]!;
    const init = call[1] as RequestInit;
    const headers = init.headers as Headers;
    expect(headers.get("If-Match")).toBe(etag);
    expect(headers.get("Idempotency-Key")).toBe("idem-2");
  });
});
