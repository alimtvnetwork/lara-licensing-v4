import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { ApiErrorCodeType } from "../src/lib/lara-api-error";
import { PlatformType } from "../src/lib/lara-self-update";
import { APP_ROLE_VALUES } from "../src/lib/lara-user-role";

/**
 * Drift guard: every runtime enum value that has a normative spec home MUST
 * appear as backticked code in that spec file. This catches the class of bug
 * where an enum is extended in code but the spec forgets to register it.
 *
 * History:
 * - v0.114.0: initial coverage for `ApiErrorCodeType` <-> 12-error-taxonomy.md.
 * - v0.115.0: extended to `PlatformType` <-> 17-self-update-endpoint.md and
 *   `APP_ROLE_VALUES` <-> 04-roles.md (Canonical set).
 */
const TAXONOMY_PATH = resolve(__dirname, "../spec/21-app/12-error-taxonomy.md");
const PLATFORM_SPEC_PATH = resolve(__dirname, "../spec/21-app/17-self-update-endpoint.md");
const ROLES_SPEC_PATH = resolve(__dirname, "../spec/21-app/04-roles.md");

describe("ApiErrorCodeType <-> spec/21-app/12-error-taxonomy.md parity", () => {
  const taxonomy = readFileSync(TAXONOMY_PATH, "utf8");
  const codes = Object.values(ApiErrorCodeType);

  it.each(codes)("code %s appears in the taxonomy codes table", (code) => {
    expect(taxonomy.includes(`\`${code}\``)).toBe(true);
  });

  it("every enum value is listed in the log-level surface table", () => {
    const tableStart = taxonomy.indexOf("## Log level and `RequestId` surface");
    expect(tableStart).toBeGreaterThan(-1);
    const tableSlice = taxonomy.slice(tableStart);
    const rowPattern = /\|\s*`([A-Za-z.]+)`\s*\|\s*`[A-Za-z]+`\s*\|\s*(?:yes|no)\s*\|/g;
    const listed = new Set<string>();
    for (const match of tableSlice.matchAll(rowPattern)) {
      listed.add(match[1]);
    }
    const missing = codes.filter((code) => !listed.has(code));
    expect(missing, `Missing from log-level table: ${missing.join(", ")}`).toEqual([]);
  });
});

describe("PlatformType <-> spec/21-app/17-self-update-endpoint.md parity", () => {
  const spec = readFileSync(PLATFORM_SPEC_PATH, "utf8");
  const platforms = Object.values(PlatformType);

  it("spec declares the canonical Platform enum section", () => {
    expect(spec).toContain("## Platform enum (canonical set, single source of truth)");
  });

  it.each(platforms)("platform %s appears as backticked code in the spec", (platform) => {
    expect(spec.includes(`\`${platform}\``)).toBe(true);
  });

  it("AC-SU-PLAT-001 pins the closed Platform set to exactly the runtime enum", () => {
    // The AC line lists the closed set inside `{...}`; runtime and spec MUST agree.
    const acMatch = spec.match(/AC-SU-PLAT-001:[^\n]*\{([^}]+)\}/);
    expect(acMatch, "AC-SU-PLAT-001 with a {...} platform set is required").not.toBeNull();
    const listed = new Set(
      acMatch![1]
        .split(",")
        .map((part) => part.trim().replace(/^`|`$/g, ""))
        .filter(Boolean),
    );
    for (const platform of platforms) {
      expect(listed.has(platform), `AC-SU-PLAT-001 missing ${platform}`).toBe(true);
    }
    expect(
      listed.size,
      `AC-SU-PLAT-001 lists ${listed.size} values; runtime has ${platforms.length}`,
    ).toBe(platforms.length);
  });
});

describe("APP_ROLE_VALUES <-> spec/21-app/04-roles.md parity", () => {
  const spec = readFileSync(ROLES_SPEC_PATH, "utf8");
  const roles = APP_ROLE_VALUES;

  it("spec declares the canonical role set section", () => {
    expect(spec).toContain("## Canonical set (single source of truth)");
  });

  it.each(roles)("role %s appears as backticked code in the canonical section", (role) => {
    // Slice the Canonical set section so a stray mention elsewhere does not mask
    // a missing row in the canonical table.
    const start = spec.indexOf("## Canonical set (single source of truth)");
    expect(start).toBeGreaterThan(-1);
    const nextHeading = spec.indexOf("\n## ", start + 1);
    const section = nextHeading > -1 ? spec.slice(start, nextHeading) : spec.slice(start);
    expect(section.includes(`\`${role}\``)).toBe(true);
  });

  it("no forbidden synonym is smuggled into APP_ROLE_VALUES", () => {
    // Guard against future refactors that would swap `AppBuilder` for `Builder`
    // or similar. The spec explicitly lists forbidden synonyms per row; the
    // runtime enum MUST contain only the canonical strings.
    const forbidden = ["Administrator", "Owner", "SuperAdmin", "Partner", "Vendor", "Builder", "Integrator", "Developer", "Customer", "Consumer", "Auditor"];
    for (const bad of forbidden) {
      expect(roles).not.toContain(bad as (typeof roles)[number]);
    }
  });
});
