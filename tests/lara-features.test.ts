import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";

/**
 * Proves the closed-set + ValueType + Idempotency-Key contract for
 * src/lib/lara-features.ts, per spec/21-app/45-license-features.md §2/§3
 * and spec/21-app/11-api-contracts/02-license-contracts.md v1.4.0
 * §Feature admin endpoints (AC-API-LIC-013, AC-API-LIC-014).
 */
vi.mock("@/lib/lara-api-client", async () => {
  const actual = await vi.importActual<typeof import("@/lib/lara-api-client")>(
    "@/lib/lara-api-client",
  );
  return { ...actual, requestLaraApi: vi.fn() };
});

import { HttpMethodType, requestLaraApi } from "@/lib/lara-api-client";
import {
  putTierFeature,
  putLicenseFeature,
  deleteTierFeature,
  validateFeatureValue,
  featureKeySchema,
} from "@/lib/lara-features";

const mocked = vi.mocked(requestLaraApi);

beforeEach(() => mocked.mockReset());
afterEach(() => vi.clearAllMocks());

describe("lara-features closed-set FeatureKey guard (AC-API-LIC-013)", () => {
  it("featureKeySchema rejects forbidden synonyms verbatim", () => {
    for (const forbidden of ["feature.reports", "max_users", "usersLimit", "watermark", "supportLevel"]) {
      expect(() => featureKeySchema.parse(forbidden)).toThrow();
    }
  });
  it("featureKeySchema accepts every registry key", () => {
    for (const allowed of [
      "Modules.Reports",
      "Modules.Api",
      "Limits.MaxUsers",
      "Limits.MaxProjects",
      "Branding.Watermark",
      "Support.Tier",
    ]) {
      expect(featureKeySchema.parse(allowed)).toBe(allowed);
    }
  });
});

describe("validateFeatureValue ValueType guard (AC-API-LIC-014)", () => {
  it("Boolean rejects coercions 0/1/'true'/'false'", () => {
    expect(() => validateFeatureValue("Modules.Reports", 0)).toThrow();
    expect(() => validateFeatureValue("Modules.Reports", 1)).toThrow();
    expect(() => validateFeatureValue("Modules.Reports", "true")).toThrow();
    expect(() => validateFeatureValue("Modules.Reports", "false")).toThrow();
    expect(validateFeatureValue("Modules.Reports", true)).toBe(true);
  });
  it("Number rejects string digits and NaN", () => {
    expect(() => validateFeatureValue("Limits.MaxUsers", "5")).toThrow();
    expect(() => validateFeatureValue("Limits.MaxUsers", Number.NaN)).toThrow();
    expect(validateFeatureValue("Limits.MaxUsers", 42)).toBe(42);
    expect(validateFeatureValue("Limits.MaxUsers", -1)).toBe(-1);
  });
  it("Support.Tier only accepts closed enum", () => {
    expect(() => validateFeatureValue("Support.Tier", "Premium")).toThrow();
    expect(validateFeatureValue("Support.Tier", "Priority")).toBe("Priority");
  });
});

describe("PUT/DELETE Idempotency-Key contract", () => {
  const fakeTierRow = { LicenseTierId: 1, FeatureKey: "Modules.Reports" as const, Value: true };
  const fakeLicRow = { LicenseId: 42, FeatureKey: "Limits.MaxUsers" as const, Value: 10 };

  it("putTierFeature sends PUT with body.Value and Idempotency-Key", async () => {
    mocked.mockResolvedValueOnce([fakeTierRow]);
    await putTierFeature(1, "Modules.Reports", true, "idem-tier-1");
    const [url, , opts] = mocked.mock.calls[0]!;
    expect(url).toBe("/Tiers/1/Features/Modules.Reports");
    expect(opts?.method).toBe(HttpMethodType.Put);
    expect(opts?.body).toEqual({ Value: true });
    expect(opts?.headers).toEqual({ "Idempotency-Key": "idem-tier-1" });
  });

  it("putLicenseFeature validates locally BEFORE any network call", async () => {
    await expect(
      putLicenseFeature(42, "Limits.MaxUsers", "10", "idem-x"),
    ).rejects.toThrow();
    expect(mocked).not.toHaveBeenCalled();
  });

  it("putLicenseFeature sends valid numeric value", async () => {
    mocked.mockResolvedValueOnce([fakeLicRow]);
    await putLicenseFeature(42, "Limits.MaxUsers", 10, "idem-lic-1");
    const [, , opts] = mocked.mock.calls[0]!;
    expect(opts?.body).toEqual({ Value: 10 });
    expect(opts?.headers).toEqual({ "Idempotency-Key": "idem-lic-1" });
  });

  it("deleteTierFeature sends DELETE with Idempotency-Key", async () => {
    mocked.mockResolvedValueOnce([fakeTierRow]);
    await deleteTierFeature(1, "Branding.Watermark", "idem-del-1");
    const [, , opts] = mocked.mock.calls[0]!;
    expect(opts?.method).toBe(HttpMethodType.Delete);
    expect(opts?.headers).toEqual({ "Idempotency-Key": "idem-del-1" });
  });
});
