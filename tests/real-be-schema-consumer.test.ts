import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * Plan 16 step 68. Root cause pinned by this test:
 *
 * The `src/generated/api/real-be-schema.ts` barrel (added v0.573) had zero
 * route consumers, so a rename or accidental deletion of its type
 * re-exports would pass every existing test and only surface as a TS error
 * at some unknown future call site. This suite fails loudly if no route
 * file under `src/routes/**` imports the barrel, keeping it load-bearing.
 *
 * When more routes migrate (audit, licenses), keep the assertion at
 * `>= 1` and add per-file spot checks if a specific route MUST consume a
 * specific type export.
 */

const ROUTES_DIR = "src/routes";
const BARREL_IMPORT =
  /from\s+["'](?:@\/generated\/api\/real-be-schema|(?:\.\.\/)+generated\/api\/real-be-schema)["']/;

function walk(dir: string, acc: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const info = statSync(full);
    if (info.isDirectory()) walk(full, acc);
    else if (entry.endsWith(".tsx") || entry.endsWith(".ts")) {
      acc.push(full.replace(/\\/g, "/"));
    }
  }
  return acc;
}

describe("real-be-schema barrel consumers", () => {
  const files = walk(ROUTES_DIR);
  const consumers = files.filter((f) => BARREL_IMPORT.test(readFileSync(f, "utf8")));

  it("at least one route imports @/generated/api/real-be-schema", () => {
    expect(
      consumers.length,
      "The real-be-schema barrel has no route consumers. If v0.573 barrel was intentionally retired, delete it and this test; otherwise wire a route to import a type from it.",
    ).toBeGreaterThanOrEqual(1);
  });

  it.each([
    "src/routes/_authenticated/admin.serials.tsx",
    "src/routes/_authenticated/admin.audit.tsx",
    "src/routes/_authenticated/admin.licenses.$licenseId.tsx",
  ])("%s is a barrel consumer (Plan 16 steps 68-69 pin)", (target) => {
    expect(consumers).toContain(target);
  });
});
