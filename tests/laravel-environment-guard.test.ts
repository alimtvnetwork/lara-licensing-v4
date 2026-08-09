// Plan 06 step 82 regression tests. Locks the Inertia console environment
// guard (backend/resources/js/lib/environment.ts) to spec/21-app/44-environments.md
// section 2, backend/config/lara.php `environments`, EnvironmentService.php,
// and src/lib/lara-environment.ts.

import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

import {
  ENVIRONMENT_IDS,
  ENVIRONMENT_NAMES,
  EnvironmentId,
  assertEnvironmentMatch,
  environmentLabel,
  environmentMismatchMarker,
  environmentName,
  environmentOptions,
  environmentOrdinal,
  environmentOrdinalMax,
  isEnvironmentId,
  isEnvironmentName,
  parseEnvironmentId,
} from "../backend/resources/js/lib/environment";
import { ENVIRONMENT_LABELS, closedSetOptions } from "../backend/resources/js/lib/closed-sets";
import { ENVIRONMENT_IDS as SPA_ENVIRONMENT_IDS, EnvironmentIdType } from "../src/lib/lara-environment";

describe("environment closed set", () => {
  it("carries exactly the spec 44 members in ordinal order", () => {
    expect(ENVIRONMENT_NAMES).toEqual(["Production", "Staging", "Development"]);
    expect(EnvironmentId).toEqual({ Production: 1, Staging: 2, Development: 3 });
    expect(ENVIRONMENT_IDS).toEqual([1, 2, 3]);
    expect(environmentOrdinalMax()).toBe(3);
  });

  it("matches backend/config/lara.php environments order", () => {
    const php = readFileSync("backend/config/lara.php", "utf8");
    const block = php.slice(php.indexOf("'environments' => ["));
    const names = [...block.slice(0, block.indexOf("]")).matchAll(/'([A-Za-z]+)'/g)]
      .map((m) => m[1])
      .filter((name) => name !== "environments");
    expect(names).toEqual([...ENVIRONMENT_NAMES]);
  });

  it("matches the SPA half of the closed set", () => {
    expect(EnvironmentId).toEqual(EnvironmentIdType);
    expect([...ENVIRONMENT_IDS]).toEqual([...SPA_ENVIRONMENT_IDS]);
  });

  it("stays the single owner for the console label map", () => {
    expect(ENVIRONMENT_LABELS).toEqual({ 1: "Production", 2: "Staging", 3: "Development" });
    expect(closedSetOptions("Environment")).toEqual([...environmentOptions]);
  });
});

describe("membership guards", () => {
  it("accepts only integer ordinals inside the set", () => {
    expect(isEnvironmentId(1)).toBe(true);
    expect(isEnvironmentId(3)).toBe(true);
    expect(isEnvironmentId(0)).toBe(false);
    expect(isEnvironmentId(4)).toBe(false);
    expect(isEnvironmentId(2.5)).toBe(false);
    expect(isEnvironmentId("2")).toBe(false);
    expect(isEnvironmentId(null)).toBe(false);
  });

  it("accepts only exact spec names, no forbidden synonyms", () => {
    expect(isEnvironmentName("Staging")).toBe(true);
    expect(isEnvironmentName("staging")).toBe(false);
    expect(isEnvironmentName("Prod")).toBe(false);
    expect(isEnvironmentName("Test")).toBe(false);
  });

  it("parses numeric strings from form inputs", () => {
    expect(parseEnvironmentId("2")).toBe(2);
    expect(parseEnvironmentId(" 3 ")).toBe(3);
  });

  it("throws with the field name and legal ordinals on non-members", () => {
    expect(() => parseEnvironmentId(9, "EnvironmentId")).toThrow(
      /EnvironmentId must be one of 1 \(Production\), 2 \(Staging\), 3 \(Development\); received 9\./,
    );
    expect(() => parseEnvironmentId("", "EnvironmentId")).toThrow(/received /);
    expect(() => parseEnvironmentId(undefined)).toThrow(/EnvironmentId/);
  });
});

describe("ordinal and name mapping", () => {
  it("round-trips every member", () => {
    for (const ordinal of ENVIRONMENT_IDS) {
      expect(environmentOrdinal(environmentName(ordinal))).toBe(ordinal);
    }
  });

  it("throws on a name outside the closed set", () => {
    expect(() => environmentOrdinal("Sandbox")).toThrow(
      /Environment must be one of Production, Staging, Development; received Sandbox\./,
    );
  });

  it("renders unknown instead of an empty cell for drifted ordinals", () => {
    expect(environmentLabel(1)).toBe("Production");
    expect(environmentLabel(null)).toBe("unknown");
    expect(environmentLabel(undefined)).toBe("unknown");
    expect(environmentLabel(7)).toBe("unknown");
  });
});

describe("mismatch marker parity with EnvironmentService::assertMatch", () => {
  it("returns null when licensed and requested agree", () => {
    expect(environmentMismatchMarker("Staging", 2)).toBeNull();
    expect(() => assertEnvironmentMatch("Production", 1)).not.toThrow();
  });

  it("emits opaque <Requested>/<Licensed> ordinals only", () => {
    expect(environmentMismatchMarker("Production", 3)).toBe("3/1");
    expect(environmentMismatchMarker("Development", 2)).toBe("2/3");
  });

  it("never leaks the licensed environment name in the thrown message", () => {
    let message = "";
    try {
      assertEnvironmentMatch("Development", 1);
    } catch (error) {
      message = (error as Error).message;
    }
    expect(message).toContain("(1/3)");
    expect(message).not.toContain("Development");
  });
});
