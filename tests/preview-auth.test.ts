import { describe, it, expect, beforeEach, vi } from "vitest";
import { 
  signInWithSeedIdentity, 
  DEMO_IDENTITIES 
} from "@/lib/preview-auth";
import { getLaraAccessToken, getLaraRefreshToken, clearLaraSession } from "@/lib/lara-api-session";
import { LaraApiError, ApiErrorCodeType } from "@/lib/lara-api-error";

describe("preview-auth", () => {
  beforeEach(() => {
    clearLaraSession();
    vi.clearAllMocks();
  });

  it("should write synthetic tokens for valid admin identity", async () => {
    await signInWithSeedIdentity("admin");
    
    const accessToken = getLaraAccessToken();
    const refreshToken = getLaraRefreshToken();
    
    expect(accessToken).toMatch(/^seed_access_admin_/);
    expect(refreshToken).toMatch(/^seed_refresh_admin_/);
  });

  it("should write synthetic tokens for valid reseller identity", async () => {
    await signInWithSeedIdentity("reseller");
    
    const accessToken = getLaraAccessToken();
    expect(accessToken).toMatch(/^seed_access_reseller_/);
  });

  it("should throw LaraApiError for unknown identity", async () => {
    try {
      await signInWithSeedIdentity("non-existent");
      expect.fail("Should have thrown");
    } catch (error) {
      expect(error).toBeInstanceOf(LaraApiError);
      const laraError = error as LaraApiError;
      expect(laraError.errorCode).toBe(ApiErrorCodeType.AuthInvalidCredentials);
      expect(laraError.httpStatus).toBe(401);
    }
  });

  it("should have all three standard identities in DEMO_IDENTITIES", () => {
    const roles = DEMO_IDENTITIES.map(i => i.role);
    expect(roles).toContain("admin");
    expect(roles).toContain("reseller");
    expect(roles).toContain("portal");
    expect(DEMO_IDENTITIES).toHaveLength(3);
  });
});
