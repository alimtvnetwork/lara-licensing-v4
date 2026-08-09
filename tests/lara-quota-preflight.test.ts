import { describe, expect, it } from "vitest";

import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import { preflightLicenseQuota, type ResellerQuota } from "@/lib/lara-quota";

/**
 * Locks the client-side preflight envelope shape against
 * spec/21-app/11-api-contracts/02-license-contracts.md §Reseller quota
 * decrement steps 3-4 (AC-API-LIC-006, AC-ERR-006, AC-ERR-007).
 * The preflight MUST throw the same errorCode+httpStatus as the server
 * would, and MUST stay silent when the cache is empty so the wire trip
 * remains authoritative.
 */
function row(overrides: Partial<ResellerQuota>): ResellerQuota {
  return {
    ResellerId: 42,
    LicenseCategoryId: 1,
    LicenseTierId: 1,
    LicensesGranted: 10,
    LicensesConsumed: 0,
    LicensesRemaining: 10,
    PeriodStart: "2026-01-01T00:00:00.000Z",
    ...overrides,
  };
}

describe("preflightLicenseQuota", () => {
  it("is a no-op when the cache is empty (server remains authoritative)", () => {
    expect(() => preflightLicenseQuota(undefined, 1, 1)).not.toThrow();
    expect(() => preflightLicenseQuota([], 1, 1)).not.toThrow();
  });

  it("throws QuotaCategoryUnauthorized (403) when no row matches (AC-ERR-007)", () => {
    try {
      preflightLicenseQuota([row({ LicenseCategoryId: 2 })], 1, 1);
      throw new Error("expected throw");
    } catch (error) {
      expect(error).toBeInstanceOf(LaraApiError);
      const err = error as LaraApiError;
      expect(err.errorCode).toBe(ApiErrorCodeType.QuotaCategoryUnauthorized);
      expect(err.httpStatus).toBe(403);
    }
  });

  it("throws QuotaExhausted (409) when LicensesRemaining <= 0 (AC-ERR-006)", () => {
    try {
      preflightLicenseQuota([row({ LicensesRemaining: 0 })], 1, 1);
      throw new Error("expected throw");
    } catch (error) {
      expect(error).toBeInstanceOf(LaraApiError);
      const err = error as LaraApiError;
      expect(err.errorCode).toBe(ApiErrorCodeType.QuotaExhausted);
      expect(err.httpStatus).toBe(409);
    }
  });

  it("does not leak LicensesGranted/LicensesConsumed in the thrown message", () => {
    try {
      preflightLicenseQuota([row({ LicensesGranted: 999, LicensesConsumed: 999, LicensesRemaining: 0 })], 1, 1);
    } catch (error) {
      const message = (error as Error).message;
      expect(message).not.toContain("999");
    }
  });

  it("passes silently when a matching row still has capacity", () => {
    expect(() => preflightLicenseQuota([row({ LicensesRemaining: 3 })], 1, 1)).not.toThrow();
  });
});
