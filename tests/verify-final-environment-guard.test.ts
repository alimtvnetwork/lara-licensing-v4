import { describe, expect, it, vi, beforeEach } from "vitest";

import * as apiClient from "../src/lib/lara-api-client";
import { verifyFinal } from "../src/lib/lara-serial";

describe("verifyFinal environment guard (spec/21-app/44-environments.md AC-LENV-004)", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it("rejects an out-of-set environmentId BEFORE the wire", async () => {
    const spy = vi.spyOn(apiClient, "requestLaraApi");
    await expect(
      verifyFinal({
        serialValue: "S-1",
        hashKey: "H",
        verifyKey: "V",
        environmentId: 7 as unknown as 1,
      }),
    ).rejects.toThrow(/environmentId must be one of/);
    expect(spy).not.toHaveBeenCalled();
  });

  it("passes the coerced numeric EnvironmentId on the wire", async () => {
    const spy = vi
      .spyOn(apiClient, "requestLaraApi")
      .mockResolvedValue([
        {
          IsAuthorized: true,
          LicenseId: 1,
          LicenseTierId: 1,
          EnvironmentId: 1,
          Features: {},
          AuthorizedAt: "2026-01-01T00:00:00.000Z",
        },
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
      ] as any);
    await verifyFinal({
      serialValue: "S-1",
      hashKey: "H",
      verifyKey: "V",
      environmentId: 1,
    });
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const body = (spy.mock.calls[0]![2] as any).body;
    expect(body.EnvironmentId).toBe(1);
  });
});
