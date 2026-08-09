/**
 * Plan 16 Step 79: Preview-handler error-code parity.
 *
 * Root cause guard: a preview fixture could easily throw a fresh string
 * code (typo, ad-hoc name, or a legacy code deleted from the closed set)
 * because `previewError` accepts `ApiErrorCodeType` values but nothing at
 * runtime verifies the CORPUS of codes fixtures use is a subset of the
 * closed set registered in `ApiErrorCodeType` (which is BE/FE-parity
 * locked by `error-taxonomy-parity.test.ts`). Without this test, a
 * fixture emitting a rogue code would ship green.
 *
 * This test statically scans every file under `src/lib/preview-fixtures/`
 * and asserts:
 *   1. Every `ApiErrorCodeType.<Name>` reference names an actual enum
 *      member (guards renames + deletions in the closed set).
 *   2. No fixture throws a raw `new LaraApiError("...", "<string>", ...)`
 *      with a string-literal code that bypasses the enum.
 *   3. No fixture throws a `new Error(...)` or a bare rejected value:
 *      every failure MUST route through `previewError` /
 *      `new LaraApiError(...)` per INV-ERR-04.
 *
 * See spec/03-error-manage/03-error-code-registry and
 * spec/28-runtime-modes/03-preview-fixture-contract.md.
 */
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { ApiErrorCodeType } from "../src/lib/lara-api-error";

const FIXTURES_DIR = join(__dirname, "..", "src", "lib", "preview-fixtures");
const CLOSED_SET = new Set<string>(Object.values(ApiErrorCodeType));

function fixtureFiles(): string[] {
  return readdirSync(FIXTURES_DIR)
    .filter((f) => f.endsWith(".ts") && !f.endsWith(".d.ts"))
    .map((f) => join(FIXTURES_DIR, f));
}

function extractCodes(source: string): string[] {
  const matches = source.matchAll(/ApiErrorCodeType\.([A-Za-z_][A-Za-z0-9_]*)/g);
  return Array.from(matches, (m) => m[1]);
}

describe("preview-fixtures error-code parity (Plan 16 Step 79)", () => {
  const files = fixtureFiles();

  it("scans at least one fixture file", () => {
    expect(files.length).toBeGreaterThan(0);
  });

  it("every ApiErrorCodeType.<Name> reference resolves to the closed set", () => {
    const offenders: Array<{ file: string; code: string }> = [];
    for (const file of files) {
      const src = readFileSync(file, "utf8");
      for (const code of extractCodes(src)) {
        if (!CLOSED_SET.has(code)) offenders.push({ file, code });
      }
    }
    expect(offenders).toEqual([]);
  });

  it("no fixture constructs LaraApiError with a string-literal code", () => {
    // Matches `new LaraApiError("msg", "SomeCode", ...)` where the 2nd arg
    // is a raw string literal instead of an enum reference.
    const badPattern = /new\s+LaraApiError\s*\(\s*[^,]+,\s*(["'`])[^"'`]+\1/;
    const offenders: string[] = [];
    for (const file of files) {
      const src = readFileSync(file, "utf8");
      if (badPattern.test(src)) offenders.push(file);
    }
    expect(offenders).toEqual([]);
  });

  it("no fixture throws a bare Error (INV-ERR-04)", () => {
    const badPattern = /\bthrow\s+new\s+Error\s*\(/;
    const offenders: string[] = [];
    for (const file of files) {
      // _shared.ts documents the invariant in a comment; skip meta files.
      if (file.endsWith("_shared.ts") || file.endsWith("_module.ts")) continue;
      const src = readFileSync(file, "utf8");
      if (badPattern.test(src)) offenders.push(file);
    }
    expect(offenders).toEqual([]);
  });
});
