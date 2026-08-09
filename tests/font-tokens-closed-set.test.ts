/**
 * Plan 09 Step 5: closed-set guard on font-family tokens.
 *
 * Root cause of prior drift: `src/styles.css` previously registered IBM
 * Plex Sans + JetBrains Mono under `@theme`, and any future edit could
 * silently reintroduce a third family. This test parses the `@theme`
 * block and asserts that every `--font-*` token resolves to exactly one
 * of the two mandated families (Ubuntu for --font-display, Poppins for
 * --font-sans and its --font-mono alias). Any new `--font-*` token, or
 * any change to the primary family listed first in the stack, fails
 * this test before it reaches review.
 *
 * Guarded invariants:
 *  - The set of `--font-*` token names is exactly the frozen list.
 *  - `--font-display` primary family is Ubuntu.
 *  - `--font-sans` primary family is Poppins.
 *  - `--font-mono` is an alias of `--font-sans` (var reference), not a
 *    third font family declaration.
 */
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

const stylesPath = resolve(__dirname, "../src/styles.css");
const source = readFileSync(stylesPath, "utf8").replace(/\/\*[\s\S]*?\*\//g, "");

function extractThemeBlock(css: string): string {
  const start = css.indexOf("@theme");
  if (start < 0) throw new Error("@theme block missing from src/styles.css");
  const openBrace = css.indexOf("{", start);
  let depth = 0;
  for (let i = openBrace; i < css.length; i++) {
    const ch = css[i];
    if (ch === "{") depth += 1;
    else if (ch === "}") {
      depth -= 1;
      if (depth === 0) return css.slice(openBrace + 1, i);
    }
  }
  throw new Error("@theme block is not balanced");
}

function collectFontTokens(themeBody: string): Map<string, string> {
  const tokens = new Map<string, string>();
  const declPattern = /(--font-[a-z-]+)\s*:\s*([^;]+);/g;
  let match: RegExpExecArray | null;
  while ((match = declPattern.exec(themeBody)) !== null) {
    const name = match[1];
    const value = match[2].replace(/\s+/g, " ").trim();
    if (name && !name.startsWith("--font-weight") && !name.startsWith("--font-variant")) {
      tokens.set(name, value);
    }
  }
  return tokens;
}

function primaryFamily(stack: string): string {
  const first = stack.split(",")[0]?.trim() ?? "";
  return first.replace(/^"|"$/g, "");
}

describe("font-family tokens closed set (Plan 09 Step 5)", () => {
  const theme = extractThemeBlock(source);
  const tokens = collectFontTokens(theme);

  it("exposes exactly the three frozen --font-* family tokens", () => {
    expect([...tokens.keys()].sort()).toEqual([
      "--font-display",
      "--font-mono",
      "--font-sans",
    ]);
  });

  it("--font-display primary family is Ubuntu", () => {
    expect(primaryFamily(tokens.get("--font-display") ?? "")).toBe("Ubuntu");
  });

  it("--font-sans primary family is Poppins", () => {
    expect(primaryFamily(tokens.get("--font-sans") ?? "")).toBe("Poppins");
  });

  it("--font-mono is aliased to --font-sans, not a third family", () => {
    expect(tokens.get("--font-mono")).toBe("var(--font-sans)");
  });
});
