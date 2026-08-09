import { beforeEach, describe, expect, it, vi } from "vitest";

import { clearLaraSession, setLaraAccessToken } from "@/lib/lara-api-session";
import { adminMetricsQueryOptions, adminMetricsSchema } from "@/lib/lara-metrics";

const OK_BODY = JSON.stringify({
  Status: { IsSuccess: true, Code: 200, Message: "ok" },
  Attributes: {
    RequestId: "req-metrics",
    RequestedAt: "2026-07-19T00:00:00Z",
    Warnings: [
      { ResellerSlug: "tenant-a", Error: "ShardUnavailable" },
    ],
  },
  Results: [
    {
      ResellersActive: 3,
      SessionsActive: 12,
      LicensesTotal: 174,
      QuotaRequestsPending: 5,
      GeneratedAt: "2026-07-19T12:34:56Z",
    },
  ],
});

beforeEach(() => {
  vi.stubEnv("VITE_LARA_API_BASE_URL", "https://lara.test");
  clearLaraSession();
  setLaraAccessToken("access-token");
});

describe("adminMetricsSchema", () => {
  it("accepts non-negative integer counts and ISO timestamp", () => {
    expect(() =>
      adminMetricsSchema.parse({
        ResellersActive: 0,
        SessionsActive: 0,
        LicensesTotal: 0,
        QuotaRequestsPending: 0,
        GeneratedAt: "2026-07-19T12:34:56Z",
      }),
    ).not.toThrow();
  });

  it("rejects negative counts", () => {
    expect(() =>
      adminMetricsSchema.parse({
        ResellersActive: -1,
        SessionsActive: 0,
        LicensesTotal: 0,
        QuotaRequestsPending: 0,
        GeneratedAt: "2026-07-19T12:34:56Z",
      }),
    ).toThrow();
  });
});

describe("adminMetricsQueryOptions.queryFn", () => {
  it("returns the single KPI payload from Results[0] plus Attributes.Warnings", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(OK_BODY, { status: 200, headers: { "Content-Type": "application/json" } }));
    vi.stubGlobal("fetch", fetchMock);
    const controller = new AbortController();
    const result = await adminMetricsQueryOptions.queryFn!({
      signal: controller.signal,
      queryKey: adminMetricsQueryOptions.queryKey,
      meta: undefined,
      client: {} as never,
    } as never);
    expect(result.metrics).toMatchObject({ ResellersActive: 3, LicensesTotal: 174, QuotaRequestsPending: 5 });
    expect(result.warnings).toEqual([{ ResellerSlug: "tenant-a", Error: "ShardUnavailable" }]);
    const call = fetchMock.mock.calls[0]?.[0] as string;
    expect(call).toContain("/Metrics");
  });
});
