import { describe, expect, it } from "vitest";

import { ApiErrorCodeType } from "../src/lib/lara-api-error";
import { copyForErrorCode, errorsByCode } from "../src/lib/copy";

/**
 * v0.301.0 (Plan 09 §91). Drift guard: every `ApiErrorCodeType` member
 * MUST have a user-visible copy entry, and no orphan copy entries may
 * exist. Root cause this test guards: v0.300 added
 * `AuthRegistrationClosed` and the register route had to hand-write the
 * error string because `copy.errors` had no matching row, resulting in
 * an out-of-band string that bypasses spec/24-app-ui-design-system/56.
 */
describe("error copy coverage parity", () => {
  const enumValues = Object.values(ApiErrorCodeType) as ReadonlyArray<ApiErrorCodeType>;

  it("has a copy entry for every ApiErrorCodeType member", () => {
    const missing = enumValues.filter((code) => errorsByCode[code] === undefined);
    expect(missing, `Missing copy for codes: ${missing.join(", ")}`).toEqual([]);
  });

  it("has no orphan copy entries not present in the enum", () => {
    const enumSet = new Set<string>(enumValues);
    const orphans = Object.keys(errorsByCode).filter((key) => !enumSet.has(key));
    expect(orphans, `Orphan copy entries: ${orphans.join(", ")}`).toEqual([]);
  });

  it("returns non-empty strings for every code", () => {
    // v0.302.0: RateLimited requires a Retry-After context to render
    // (see src/lib/error-copy.ts); pass a sentinel so the placeholder
    // never leaks to callers who forgot to attach metadata.
    const empty = enumValues.filter((code) => {
      const value = copyForErrorCode(code, { retryAfterSeconds: 1 });
      return typeof value !== "string" || value.trim().length === 0;
    });
    expect(empty).toEqual([]);
  });


  it("interpolates RetryAfterSec for RateLimited", () => {
    const rendered = copyForErrorCode(ApiErrorCodeType.RateLimited, {
      retryAfterSeconds: 42,
    });
    expect(rendered).toContain("42");
    expect(rendered).not.toContain("{RetryAfterSec}");
  });
});
