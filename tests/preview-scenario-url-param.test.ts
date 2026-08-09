/**
 * Plan 16 Step 81: `?preview=offline|slow|rate-limited` URL param parser.
 *
 * Verifies parseScenarioFromSearch returns the closed-set value, treats
 * empty `?preview=` as an explicit reset (null), logs+ignores unknown
 * values (no silent swallow, no throw), and returns undefined when the
 * param is absent so the current scenario is preserved.
 */
import { describe, it, expect, vi, afterEach } from "vitest";
import { parseScenarioFromSearch } from "../src/lib/preview-scenario";

afterEach(() => vi.restoreAllMocks());

describe("parseScenarioFromSearch (Plan 16 Step 81)", () => {
  it("returns closed-set values for offline / slow / rate-limited", () => {
    expect(parseScenarioFromSearch("?preview=offline")).toBe("offline");
    expect(parseScenarioFromSearch("?preview=slow")).toBe("slow");
    expect(parseScenarioFromSearch("preview=rate-limited")).toBe("rate-limited");
    expect(parseScenarioFromSearch("?preview=OFFLINE")).toBe("offline");
  });

  it("returns undefined when the param is absent (preserve current scenario)", () => {
    expect(parseScenarioFromSearch("")).toBeUndefined();
    expect(parseScenarioFromSearch(undefined)).toBeUndefined();
    expect(parseScenarioFromSearch("?foo=bar")).toBeUndefined();
  });

  it("returns null for explicit empty `?preview=` (reset)", () => {
    expect(parseScenarioFromSearch("?preview=")).toBeNull();
  });

  it("logs and ignores unknown values (no silent swallow)", () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    expect(parseScenarioFromSearch("?preview=nope")).toBeUndefined();
    expect(warn).toHaveBeenCalledWith(
      "preview-scenario: ignoring unknown ?preview= search value",
      { value: "nope" },
    );
  });
});
