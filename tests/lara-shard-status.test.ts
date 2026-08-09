import { beforeEach, describe, expect, it, vi } from "vitest";

import { clearLaraSession, setLaraAccessToken } from "@/lib/lara-api-session";
import { fetchShardStatus, shardStatusRowSchema } from "@/lib/lara-shard-status";

const OK_BODY = JSON.stringify({
  Status: { IsSuccess: true, Code: 200, Message: "ok" },
  Attributes: {
    RequestId: "req-shard-status",
    RequestedAt: "2026-07-19T00:00:00Z",
    CheckedAt: "2026-07-19T12:00:00Z",
    UnreachableCount: 1,
  },
  Results: [
    { ResellerSlug: "tenant-a", Reachable: true, Error: null },
    { ResellerSlug: "tenant-b", Reachable: false, Error: "ShardUnavailable" },
  ],
});

beforeEach(() => {
  vi.stubEnv("VITE_LARA_API_BASE_URL", "https://lara.test");
  clearLaraSession();
  setLaraAccessToken("access-token");
});

describe("shardStatusRowSchema", () => {
  it("accepts a null Error when Reachable is true", () => {
    expect(() =>
      shardStatusRowSchema.parse({ ResellerSlug: "a", Reachable: true, Error: null }),
    ).not.toThrow();
  });

  it("rejects a missing ResellerSlug", () => {
    expect(() =>
      shardStatusRowSchema.parse({ ResellerSlug: "", Reachable: true, Error: null }),
    ).toThrow();
  });
});

describe("fetchShardStatus", () => {
  it("returns rows plus CheckedAt / UnreachableCount from Attributes", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        new Response(OK_BODY, { status: 200, headers: { "Content-Type": "application/json" } }),
      );
    vi.stubGlobal("fetch", fetchMock);
    const snapshot = await fetchShardStatus();
    expect(snapshot.rows).toHaveLength(2);
    expect(snapshot.rows[1]).toMatchObject({ ResellerSlug: "tenant-b", Reachable: false });
    expect(snapshot.checkedAt).toBe("2026-07-19T12:00:00Z");
    expect(snapshot.unreachableCount).toBe(1);
    const call = fetchMock.mock.calls[0]?.[0] as string;
    expect(call).toContain("/Metrics/ShardStatus");
  });
});
