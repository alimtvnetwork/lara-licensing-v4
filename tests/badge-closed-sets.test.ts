/**
 * Locks the closed-set Badge registry per
 * spec/24-app-ui-design-system/25-component-badge-status.md §4.
 * Any drift between spec and runtime is a failing test, not a warning
 * (AC-BDG-002 forbids silent neutral fallback).
 */
import { describe, expect, it, vi } from "vitest";

import {
  BuilderKeyStateBadge,
  LicenseStateBadge,
  LicenseTierBadge,
  QuotaRequestStatusBadge,
  SerialStateBadge,
  UserRoleBadge,
  resolveBadgeSpec,
} from "@/components/badge/registry";

describe("Badge closed-set registry (spec 24 §25.4)", () => {
  it("covers every LicenseState value exactly once", () => {
    expect(Object.keys(LicenseStateBadge).sort()).toEqual([
      "Active", "Draft", "Expired", "GracePeriod", "Issued", "Revoked", "Suspended",
    ]);
  });

  it("covers every SerialState value exactly once", () => {
    expect(Object.keys(SerialStateBadge).sort()).toEqual(["Bound", "Rebinding", "Retired", "Unbound"]);
  });

  it("covers every BuilderKeyState value exactly once", () => {
    expect(Object.keys(BuilderKeyStateBadge).sort()).toEqual(["Active", "Revoked", "Rotating"]);
  });

  it("covers every QuotaRequestStatus value exactly once", () => {
    expect(Object.keys(QuotaRequestStatusBadge).sort()).toEqual(["Approved", "Cancelled", "Denied", "Pending"]);
  });

  it("covers every UserRole value exactly once", () => {
    expect(Object.keys(UserRoleBadge).sort()).toEqual(["Admin", "AppBuilder", "EndUser", "Reseller", "SuperAdmin"]);
  });

  it("covers every LicenseTier value exactly once", () => {
    expect(Object.keys(LicenseTierBadge).sort()).toEqual(["Enterprise", "Professional", "Standard", "Trial"]);
  });

  it("resolveBadgeSpec throws in dev on unknown value (AC-BDG-002)", () => {
    expect(() => resolveBadgeSpec("LicenseState", "TotallyNotAState")).toThrowError(/BadgeUnknownValue/);
  });

  it("known value maps to spec entry", () => {
    const spec = resolveBadgeSpec("LicenseState", "Active");
    expect(spec.intent).toBe("success");
    expect(spec.label).toBe("Active");
  });

  it("resolveBadgeSpec warns and returns destructive fallback in prod", () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    vi.stubEnv("DEV", "");
    try {
      const spec = resolveBadgeSpec("SerialState", "Nope");
      expect(spec.intent).toBe("destructive");
      expect(warn).toHaveBeenCalled();
    } finally {
      vi.unstubAllEnvs();
      warn.mockRestore();
    }
  });
});

