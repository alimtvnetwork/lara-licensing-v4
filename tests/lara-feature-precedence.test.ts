import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";

/**
 * Locks two contracts in `src/lib/lara-features.ts`:
 *
 * 1. `putLicenseFeature` / `deleteLicenseFeature` MUST send `If-Match`
 *    per spec/21-app/11-api-contracts/09-concurrency-control.md §Scope
 *    rows 3 and 4. Root cause these tests prevent regressing: before
 *    v0.183.0 the helpers sent only `Idempotency-Key`, so every write
 *    against the ratified contract would have returned
 *    `428 PreconditionRequired`.
 *
 * 2. `resolveFeatureMap` MUST resolve the runtime feature map per
 *    spec/21-app/45-license-features.md §4 (LicenseFeatures overrides
 *    TierFeatures; absence means "not licensed"). Locks AC-FEAT-003
 *    (§4 precedence) and AC-FEAT-004 (override semantics).
 */

import {
  putLicenseFeature,
  deleteLicenseFeature,
  resolveFeatureMap,
  type LicenseFeatureResource,
  type TierFeatureResource,
} from "@/lib/lara-features";

const originalFetch = globalThis.fetch;
const BASE = "https://api.example.test";

beforeEach(() => {
  (import.meta.env as unknown as Record<string, string>).VITE_LARA_API_BASE_URL = BASE;
});

afterEach(() => {
  globalThis.fetch = originalFetch;
  vi.restoreAllMocks();
});

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  });
}

const putBody = {
  Status: { IsSuccess: true, Code: 200, Message: "ok" },
  Attributes: { RequestId: "req-1", RequestedAt: "2026-07-22T00:00:00.000Z" },
  Results: [{ LicenseId: 1, FeatureKey: "Modules.Reports", Value: true }],
};

const deleteBody = {
  Status: { IsSuccess: true, Code: 200, Message: "ok" },
  Attributes: { RequestId: "req-2", RequestedAt: "2026-07-22T00:00:00.000Z" },
  Results: [{ LicenseId: 1, FeatureKey: "Modules.Reports", Value: true }],
};

describe("license feature mutations attach If-Match + Idempotency-Key", () => {
  it("putLicenseFeature sends both headers verbatim", async () => {
    globalThis.fetch = vi.fn().mockResolvedValueOnce(jsonResponse(putBody)) as typeof fetch;
    await putLicenseFeature(1, "Modules.Reports", true, "idem-p", "\"etag-1\"");
    const call = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0]!;
    const init = call[1] as RequestInit;
    const headers = init.headers as Headers;
    expect(headers.get("If-Match")).toBe("\"etag-1\"");
    expect(headers.get("Idempotency-Key")).toBe("idem-p");
    expect(init.method).toBe("PUT");
  });

  it("deleteLicenseFeature sends both headers verbatim", async () => {
    globalThis.fetch = vi.fn().mockResolvedValueOnce(jsonResponse(deleteBody)) as typeof fetch;
    await deleteLicenseFeature(1, "Modules.Reports", "idem-d", "\"etag-2\"");
    const call = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0]!;
    const init = call[1] as RequestInit;
    const headers = init.headers as Headers;
    expect(headers.get("If-Match")).toBe("\"etag-2\"");
    expect(headers.get("Idempotency-Key")).toBe("idem-d");
    expect(init.method).toBe("DELETE");
  });

  it("rejects an invalid value locally before any network call", async () => {
    const fetchMock = vi.fn();
    globalThis.fetch = fetchMock as typeof fetch;
    await expect(
      putLicenseFeature(1, "Modules.Reports", "true", "idem-x", "\"etag-x\""),
    ).rejects.toThrow();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("resolveFeatureMap implements §4 precedence", () => {
  const tier: TierFeatureResource[] = [
    { LicenseTierId: 1, FeatureKey: "Modules.Reports", Value: false },
    { LicenseTierId: 1, FeatureKey: "Limits.MaxUsers", Value: 10 },
    { LicenseTierId: 1, FeatureKey: "Support.Tier", Value: "Community" },
  ];

  it("returns the tier layer when no license overrides exist", () => {
    const map = resolveFeatureMap(tier, []);
    expect(map).toEqual({
      "Modules.Reports": false,
      "Limits.MaxUsers": 10,
      "Support.Tier": "Community",
    });
  });

  it("license overrides win over tier defaults (AC-FEAT-004)", () => {
    const license: LicenseFeatureResource[] = [
      { LicenseId: 1, FeatureKey: "Modules.Reports", Value: true },
      { LicenseId: 1, FeatureKey: "Support.Tier", Value: "Priority" },
    ];
    const map = resolveFeatureMap(tier, license);
    expect(map["Modules.Reports"]).toBe(true);
    expect(map["Support.Tier"]).toBe("Priority");
    expect(map["Limits.MaxUsers"]).toBe(10);
  });

  it("absent keys stay absent (no synthesized defaults)", () => {
    const map = resolveFeatureMap([], []);
    expect(Object.keys(map)).toHaveLength(0);
    expect("Modules.Api" in map).toBe(false);
  });

  it("does not mutate the input arrays", () => {
    const tierCopy = structuredClone(tier);
    resolveFeatureMap(tier, [
      { LicenseId: 1, FeatureKey: "Modules.Reports", Value: true },
    ]);
    expect(tier).toEqual(tierCopy);
  });
});
