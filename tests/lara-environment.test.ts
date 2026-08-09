import { describe, expect, it, vi, afterEach } from "vitest";

import {
  EnvironmentIdType,
  ENVIRONMENT_IDS,
  environmentIdSchema,
  isEnvironmentId,
  parseEnvironmentId,
  resolveCallerEnvironmentId,
} from "../src/lib/lara-environment";

describe("lara-environment closed set (spec/21-app/44-environments.md §2)", () => {
  it("exposes exactly ordinals 1..3 mapped to the canonical names", () => {
    expect(EnvironmentIdType).toEqual({ Production: 1, Staging: 2, Development: 3 });
    expect([...ENVIRONMENT_IDS]).toEqual([1, 2, 3]);
  });

  it("environmentIdSchema accepts 1,2,3 and rejects everything else", () => {
    for (const id of [1, 2, 3]) {
      expect(environmentIdSchema.safeParse(id).success).toBe(true);
    }
    for (const id of [0, 4, 7, -1, "1", null, undefined]) {
      expect(environmentIdSchema.safeParse(id).success).toBe(false);
    }
  });

  it("isEnvironmentId narrows correctly", () => {
    expect(isEnvironmentId(2)).toBe(true);
    expect(isEnvironmentId(9)).toBe(false);
  });

  it("parseEnvironmentId coerces numeric strings and echoes the field name", () => {
    expect(parseEnvironmentId("3", "EnvironmentId")).toBe(3);
    expect(() => parseEnvironmentId("prod", "EnvironmentId")).toThrow(
      /EnvironmentId must be one of/,
    );
  });
});

describe("resolveCallerEnvironmentId", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("throws when the env var is missing", () => {
    vi.stubEnv("VITE_LARA_ENVIRONMENT_ID", "");
    expect(() => resolveCallerEnvironmentId()).toThrow(
      /VITE_LARA_ENVIRONMENT_ID is not configured/,
    );
  });

  it("returns the parsed ordinal when the env var is 1..3", () => {
    vi.stubEnv("VITE_LARA_ENVIRONMENT_ID", "2");
    expect(resolveCallerEnvironmentId()).toBe(2);
  });

  it("throws when the env var is out of set", () => {
    vi.stubEnv("VITE_LARA_ENVIRONMENT_ID", "7");
    expect(() => resolveCallerEnvironmentId()).toThrow(/must be one of/);
  });
});
