import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";

/**
 * Proves the Idempotency-Key contract for mutating quota helpers in
 * src/lib/lara-quota.ts, sourced from
 * spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md and
 * spec/21-app/11-api-contracts/05-quota-request-contracts.md
 * (AC-API-QR-003). Every mutating call MUST attach an Idempotency-Key
 * header verbatim; a replay MUST NOT strip or rewrite it.
 *
 * We intercept the low-level transport (requestLaraApi) to inspect the
 * outgoing options without hitting the network.
 */
vi.mock("@/lib/lara-api-client", async () => {
  const actual = await vi.importActual<typeof import("@/lib/lara-api-client")>(
    "@/lib/lara-api-client",
  );
  return {
    ...actual,
    requestLaraApi: vi.fn(),
  };
});

import { HttpMethodType, requestLaraApi } from "@/lib/lara-api-client";
import {
  submitQuotaRequest,
  approveQuotaRequest,
  denyQuotaRequest,
  cancelQuotaRequest,
  adjustQuota,
  quotaRequestSubmitSchema,
} from "@/lib/lara-quota";

const fakeRow = {
  QuotaRequestId: 1,
  ResellerId: 42,
  LicenseCategoryId: 1,
  LicenseTierId: 1,
  RequestedDelta: 10,
  Status: "Pending" as const,
  SubmittedByUserId: 7,
  SubmittedAt: "2026-07-22T00:00:00.000Z",
};

const fakeAdjust = {
  ResellerId: 42,
  LicenseCategoryId: 1,
  LicenseTierId: 1,
  Delta: 5,
  LicensesGranted: 105,
  LicensesConsumed: 0,
  LicensesRemaining: 105,
  LedgerId: 999,
  ActorUserId: 7,
  CreatedAt: "2026-07-22T00:00:00.000Z",
};

const mocked = vi.mocked(requestLaraApi);

beforeEach(() => {
  mocked.mockReset();
});

afterEach(() => {
  vi.clearAllMocks();
});

function lastOptions(): { method?: string; headers?: Record<string, string> } {
  const call = mocked.mock.calls.at(-1);
  if (!call) throw new Error("requestLaraApi was not called");
  return call[2] ?? {};
}

describe("lara-quota Idempotency-Key contract (AC-API-QR-003)", () => {
  it("submitQuotaRequest attaches Idempotency-Key header verbatim", async () => {
    mocked.mockResolvedValueOnce([fakeRow]);
    await submitQuotaRequest(42, { LicenseCategoryId: 1, LicenseTierId: 1, RequestedDelta: 10 }, "idem-abc");
    const opts = lastOptions();
    expect(opts.method).toBe(HttpMethodType.Post);
    expect(opts.headers).toEqual({ "Idempotency-Key": "idem-abc" });
  });

  it("approveQuotaRequest attaches Idempotency-Key header verbatim", async () => {
    mocked.mockResolvedValueOnce([fakeRow]);
    await approveQuotaRequest(1, "acme", { ApprovedDelta: 5 }, "idem-approve");
    expect(lastOptions().headers).toEqual({ "Idempotency-Key": "idem-approve" });
  });

  it("denyQuotaRequest attaches Idempotency-Key header verbatim", async () => {
    mocked.mockResolvedValueOnce([fakeRow]);
    await denyQuotaRequest(1, "acme", { Reason: "no" }, "idem-deny");
    expect(lastOptions().headers).toEqual({ "Idempotency-Key": "idem-deny" });
  });

  it("cancelQuotaRequest attaches Idempotency-Key header verbatim", async () => {
    mocked.mockResolvedValueOnce([fakeRow]);
    await cancelQuotaRequest(1, "idem-cancel");
    expect(lastOptions().headers).toEqual({ "Idempotency-Key": "idem-cancel" });
  });

  it("adjustQuota attaches Idempotency-Key header verbatim", async () => {
    mocked.mockResolvedValueOnce([fakeAdjust]);
    await adjustQuota(42, 1, { LicenseTierId: 1, Delta: 5, Reason: "seed" }, "idem-adjust");
    expect(lastOptions().headers).toEqual({ "Idempotency-Key": "idem-adjust" });
  });
});

describe("quotaRequestSubmitSchema guards", () => {
  it("rejects RequestedDelta <= 0", () => {
    expect(() => quotaRequestSubmitSchema.parse({ LicenseCategoryId: 1, LicenseTierId: 1, RequestedDelta: 0 })).toThrow();
  });

  it("rejects RequestedDelta > 10000", () => {
    expect(() => quotaRequestSubmitSchema.parse({ LicenseCategoryId: 1, LicenseTierId: 1, RequestedDelta: 10001 })).toThrow();
  });

  it("accepts a well-formed submission", () => {
    const parsed = quotaRequestSubmitSchema.parse({
      LicenseCategoryId: 1,
      LicenseTierId: 1,
      RequestedDelta: 10,
      Justification: "Need capacity for Q3.",
    });
    expect(parsed.RequestedDelta).toBe(10);
  });
});
