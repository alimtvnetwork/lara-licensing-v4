/**
 * Locks the closed-set registry per spec 24/19-component-select.md §2.
 * Parity between the runtime enum schemas (in `src/lib/lara-*.ts`) and the
 * options exposed via `useClosedSet` is normative; drift here breaks
 * AC-SEL-002.
 */
import { describe, expect, it } from "vitest";

import {
  resolveClosedSet,
  findClosedSetOption,
} from "@/lib/closed-sets";
import { LicenseCategoryIdType, LicenseTierIdType } from "@/lib/lara-license";
import { EnvironmentIdType } from "@/lib/lara-environment";
import { APP_ROLE_VALUES } from "@/lib/lara-user-role";
import { QuotaRequestStatusType } from "@/lib/lara-quota";

describe("closed-sets registry (spec 24 §19.2)", () => {
  it("LicenseCategory matches ordinals 1..7 in ascending order", () => {
    const options = resolveClosedSet("LicenseCategory");
    expect(options.map((o) => o.value)).toEqual([1, 2, 3, 4, 5, 6, 7]);
    expect(options[0]?.value).toBe(LicenseCategoryIdType.Daily);
    expect(options[6]?.value).toBe(LicenseCategoryIdType.Key);
  });

  it("LicenseTier matches Tier1..Unlimited spec table order", () => {
    const options = resolveClosedSet("LicenseTier");
    expect(options.map((o) => o.value)).toEqual([
      LicenseTierIdType.Tier1,
      LicenseTierIdType.Tier2,
      LicenseTierIdType.Tier3,
      LicenseTierIdType.Unlimited,
    ]);
  });

  it("Environment ordering is canonical Production/Staging/Development", () => {
    expect(resolveClosedSet("Environment").map((o) => o.value)).toEqual([
      EnvironmentIdType.Production,
      EnvironmentIdType.Staging,
      EnvironmentIdType.Development,
    ]);
  });

  it("AppRole is derived from APP_ROLE_VALUES (single source of truth)", () => {
    expect(resolveClosedSet("AppRole").map((o) => o.value)).toEqual([...APP_ROLE_VALUES]);
  });

  it("QuotaRequestStatus follows state-machine order", () => {
    expect(resolveClosedSet("QuotaRequestStatus").map((o) => o.value)).toEqual([
      QuotaRequestStatusType.Pending,
      QuotaRequestStatusType.Approved,
      QuotaRequestStatusType.Denied,
      QuotaRequestStatusType.Cancelled,
    ]);
  });

  it("findClosedSetOption returns the option for a known value", () => {
    const opt = findClosedSetOption("Environment", EnvironmentIdType.Staging);
    expect(opt?.label).toBe("Staging");
  });

  it("findClosedSetOption returns undefined for an unknown value (no silent fallback)", () => {
    // 99 is not a valid EnvironmentId ordinal.
    const opt = findClosedSetOption("Environment", 99 as never);
    expect(opt).toBeUndefined();
  });
});
