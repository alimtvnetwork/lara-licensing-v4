import { describe, expect, it, vi, beforeEach } from "vitest";

/**
 * Proves the Idempotency-Key contract for every mutating helper in
 * src/lib/lara-license.ts, src/lib/lara-reseller.ts, and
 * src/lib/lara-prefix.ts per
 * spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md.
 *
 * Root cause these tests lock in: before v0.179.0 the license /
 * reseller / prefix mutations shipped without the header, so a network
 * retry could double-consume quota or duplicate a prefix. Retrofit made
 * the key a required parameter; these tests fail the build if anyone
 * removes it again.
 */
vi.mock("@/lib/lara-api-client", async () => {
  const actual = await vi.importActual<typeof import("@/lib/lara-api-client")>(
    "@/lib/lara-api-client",
  );
  return { ...actual, requestLaraApi: vi.fn() };
});

import { requestLaraApi } from "@/lib/lara-api-client";
import { createLicense, updateLicense, deleteLicense } from "@/lib/lara-license";
import { createReseller, updateReseller, deleteReseller } from "@/lib/lara-reseller";
import { createResellerPrefix, deletePrefix } from "@/lib/lara-prefix";

const mocked = vi.mocked(requestLaraApi);

const fakeLicense = {
  LicenseId: 1,
  LicenseCategoryId: 1 as const,
  LicenseTierId: 1 as const,
  EnvironmentId: 1 as const,
  IssuedByUserId: 1,
  ProductVersion: "1.0.0",
  IsActive: true,
  IssuedAt: "2026-07-22T00:00:00.000Z",
  IsSingleUse: false,
};

const fakeReseller = {
  ResellerId: 1,
  ResellerName: "A",
  ContactEmail: "a@b.co",
  IsActive: true,
  CreatedAt: "2026-07-22T00:00:00.000Z",
  UpdatedAt: "2026-07-22T00:00:00.000Z",
};

const fakePrefix = { PrefixId: 1, ResellerId: 1, PrefixValue: "ACME01", IsActive: true };

beforeEach(() => mocked.mockReset());

function headers(): Record<string, string> | undefined {
  return mocked.mock.calls.at(-1)?.[2]?.headers as Record<string, string> | undefined;
}

describe("lara-license mutations attach Idempotency-Key", () => {
  it("createLicense", async () => {
    mocked.mockResolvedValueOnce([fakeLicense]);
    await createLicense(
      { LicenseCategoryId: 1, LicenseTierId: 1, EnvironmentId: 1, ProductVersion: "1", IsSingleUse: false },
      "k1",
    );
    expect(headers()).toEqual({ "Idempotency-Key": "k1" });
  });
  it("updateLicense", async () => {
    mocked.mockResolvedValueOnce([fakeLicense]);
    await updateLicense(1, { IsActive: false }, "k2", "\"etag-abc\"");
    expect(headers()).toEqual({ "Idempotency-Key": "k2", "If-Match": "\"etag-abc\"" });
  });
  it("deleteLicense", async () => {
    mocked.mockResolvedValueOnce([{ LicenseId: 1, IsDeleted: true }]);
    await deleteLicense(1, "k3", "\"etag-xyz\"");
    expect(headers()).toEqual({ "Idempotency-Key": "k3", "If-Match": "\"etag-xyz\"" });
  });
});

describe("lara-reseller mutations attach Idempotency-Key", () => {
  it("createReseller", async () => {
    mocked.mockResolvedValueOnce([fakeReseller]);
    await createReseller({ ResellerName: "A", ContactEmail: "a@b.co", IsActive: true }, "r1");
    expect(headers()).toEqual({ "Idempotency-Key": "r1" });
  });
  it("updateReseller", async () => {
    mocked.mockResolvedValueOnce([fakeReseller]);
    await updateReseller(1, { IsActive: false }, "r2");
    expect(headers()).toEqual({ "Idempotency-Key": "r2" });
  });
  it("deleteReseller", async () => {
    mocked.mockResolvedValueOnce([{}]);
    await deleteReseller(1, "r3");
    expect(headers()).toEqual({ "Idempotency-Key": "r3" });
  });
});

describe("lara-prefix mutations attach Idempotency-Key", () => {
  it("createResellerPrefix", async () => {
    mocked.mockResolvedValueOnce([fakePrefix]);
    await createResellerPrefix(1, { PrefixValue: "ACME01" }, "p1");
    expect(headers()).toEqual({ "Idempotency-Key": "p1" });
  });
  it("deletePrefix", async () => {
    mocked.mockResolvedValueOnce([{}]);
    await deletePrefix(1, "p2");
    expect(headers()).toEqual({ "Idempotency-Key": "p2" });
  });
});
