import { describe, expect, it } from "vitest";
import {
  licenseCreateSchema,
  LICENSE_CATEGORY_IDS,
  LICENSE_TIER_IDS,
  ENVIRONMENT_IDS,
} from "@/lib/lara-license";

/**
 * Proves AC-CAT-005 (spec/21-app/05-license-categories.md),
 * AC-LT-002 (spec/21-app/43-license-tiers.md), and
 * AC-LENV-002 (spec/21-app/44-environments.md): the client rejects any id
 * outside the closed ordinal set BEFORE POST /Licenses fires, so callers
 * observe ValidationFailed on both sides of the wire without a round-trip.
 */
const validBase = {
  LicenseCategoryId: 1,
  LicenseTierId: 1,
  EnvironmentId: 1,
  ProductVersion: "1.0.0",
  IsSingleUse: false,
};

describe("licenseCreateSchema closed-set guards", () => {
  it("exposes the exact category ordinal set from spec §Canonical set", () => {
    expect(LICENSE_CATEGORY_IDS).toEqual([1, 2, 3, 4, 5, 6, 7]);
  });

  it("exposes the exact tier ordinal set from spec §2", () => {
    expect(LICENSE_TIER_IDS).toEqual([1, 2, 3, 4]);
  });

  it("exposes the exact environment ordinal set from spec §2", () => {
    expect(ENVIRONMENT_IDS).toEqual([1, 2, 3]);
  });

  it.each(LICENSE_CATEGORY_IDS)("accepts LicenseCategoryId=%d", (id) => {
    expect(licenseCreateSchema.parse({ ...validBase, LicenseCategoryId: id }).LicenseCategoryId).toBe(id);
  });

  it.each([0, 8, 9, 15, 99, -1])("rejects LicenseCategoryId=%d", (id) => {
    expect(() => licenseCreateSchema.parse({ ...validBase, LicenseCategoryId: id })).toThrow();
  });

  it.each([0, 5, 6, 99, -1])("rejects LicenseTierId=%d", (id) => {
    expect(() => licenseCreateSchema.parse({ ...validBase, LicenseTierId: id })).toThrow();
  });

  it.each([0, 4, 5, 99, -1])("rejects EnvironmentId=%d", (id) => {
    expect(() => licenseCreateSchema.parse({ ...validBase, EnvironmentId: id })).toThrow();
  });
});
