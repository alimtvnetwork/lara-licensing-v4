/**
 * Plan 11 step 46 (v0.443.0): parity guard between the canonical
 * `errorMessages` map (`src/lib/error-messages.ts`) and every seam that
 * depends on the closed set:
 *
 *   - `ApiErrorCodeType` enum in `src/lib/lara-api-error.ts` (build-time
 *     `satisfies` already enforces this, but a runtime check catches
 *     accidental `any`/`ts-ignore` widening).
 *   - `errorsByCode` runtime table in `src/lib/error-copy.ts` used by
 *     `copyForErrorCode` at render time.
 *
 * If this test fails, do NOT edit only one side. Update all three so the
 * closed set stays synchronized (spec/24-app-ui-design-system §56).
 */

import { describe, expect, it } from "vitest";

import { ApiErrorCodeType } from "../src/lib/lara-api-error";
import { errorsByCode } from "../src/lib/error-copy";
import { errorMessages } from "../src/lib/error-messages";

describe("error-messages canonical map (Plan 11 step 46)", () => {
  const enumValues = Object.values(ApiErrorCodeType) as ReadonlyArray<string>;
  const canonicalKeys = Object.keys(errorMessages) as ReadonlyArray<string>;
  const mirrorKeys = Object.keys(errorsByCode) as ReadonlyArray<string>;

  it("has an entry for every ApiErrorCodeType member", () => {
    const missing = enumValues.filter(
      (code) => (errorMessages as Record<string, string>)[code] === undefined,
    );
    expect(missing, `Missing errorMessages entry for: ${missing.join(", ")}`).toEqual([]);
  });

  it("has no orphan keys outside ApiErrorCodeType", () => {
    const enumSet = new Set<string>(enumValues);
    const orphans = canonicalKeys.filter((key) => !enumSet.has(key));
    expect(orphans, `Orphan errorMessages keys: ${orphans.join(", ")}`).toEqual([]);
  });

  it("matches errorsByCode key-for-key (no drift between mirrors)", () => {
    expect([...canonicalKeys].sort()).toEqual([...mirrorKeys].sort());
  });

  it("every value is a non-empty string", () => {
    const empties = canonicalKeys.filter((key) => {
      const value = (errorMessages as Record<string, string>)[key];
      return typeof value !== "string" || value.trim().length === 0;
    });
    expect(empties, `Empty message for: ${empties.join(", ")}`).toEqual([]);
  });
});
