/**
 * Plan 09 Step 69: theme-token drift guard.
 *
 * Root cause guarded: `src/styles.css` `@theme inline { ... }` declares
 * every design token the app depends on (radius, color roles, motion,
 * spacing, typography). An accidental rename or removal here silently
 * breaks Tailwind utilities and shadcn variants across the whole
 * surface, and by the time a component renders wrong the offending
 * commit is long gone. This test parses the first `@theme` block and
 * snapshots the ordered list of token names. Any drop, rename, or
 * reorder trips a snapshot diff; adding a new token is a one-line
 * update to `__snapshots__/theme-tokens-snapshot.test.ts.snap`.
 *
 * Values are intentionally NOT snapshotted: OKLCH triples change often
 * during a palette tune. What must not silently change is the token
 * inventory itself.
 */
import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function extractThemeTokenNames(): readonly string[] {
  const source = readFileSync(resolve(process.cwd(), "src/styles.css"), "utf8");
  const themeMatch = source.match(/@theme inline \{([\s\S]*?)\n\}/);
  if (themeMatch === null) {
    throw new Error("theme-tokens-snapshot: @theme block not found in src/styles.css");
  }
  const names: string[] = [];
  for (const rawLine of themeMatch[1].split("\n")) {
    const declaration = rawLine.match(/^\s*(--[a-z0-9-]+)\s*:/i);
    if (declaration !== null) names.push(declaration[1]);
  }
  if (names.length === 0) {
    throw new Error("theme-tokens-snapshot: no CSS custom properties parsed");
  }
  return names;
}

describe("@theme token inventory", () => {
  it("matches the locked-down token list", () => {
    expect(extractThemeTokenNames()).toMatchSnapshot();
  });
});
