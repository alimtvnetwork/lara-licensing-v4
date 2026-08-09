/**
 * Dead-op linter test (Plan 16 Step 72).
 *
 * Every operationId in `src/generated/api/operations.ts` MUST be
 * referenced by at least one caller in `src/` outside the generated
 * folder and the test tree. Prevents a typed op from being added and
 * then forgotten (or a caller from being deleted without pruning the
 * op), which would leave preview handlers wired to code no route hits.
 *
 * INV-RM-04 pins preview-handler coverage; this test pins caller
 * coverage on the opposite side of the same contract.
 */

import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { Operations } from "@/generated/api/operations";

const SRC_ROOT = resolve(__dirname, "..", "src");
const GENERATED_DIR = resolve(SRC_ROOT, "generated");

function walk(dir: string, out: string[]): void {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (full.startsWith(GENERATED_DIR)) continue;
    const st = statSync(full);
    if (st.isDirectory()) {
      walk(full, out);
      continue;
    }
    if (/\.(ts|tsx)$/.test(entry)) out.push(full);
  }
}

function collectSources(): string[] {
  const files: string[] = [];
  walk(SRC_ROOT, files);
  return files;
}

function findCallers(operationId: string, files: string[]): string[] {
  const needle = `"${operationId}"`;
  const hits: string[] = [];
  for (const f of files) {
    const body = readFileSync(f, "utf8");
    if (body.includes(needle)) hits.push(f);
  }
  return hits;
}

describe("Operations dead-op coverage (Step 72)", () => {
  const files = collectSources();
  const ids = Object.keys(Operations);

  it("has at least one operation to check", () => {
    expect(ids.length).toBeGreaterThan(0);
    expect(files.length).toBeGreaterThan(0);
  });

  it.each(ids)("operationId %s has at least one caller in src/", (id) => {
    const callers = findCallers(id, files);
    if (callers.length === 0) {
      // Loud, contextual failure: which op is dead, where we searched.
      console.error("dead-op: no caller found", { operationId: id, searchedFiles: files.length });
    }
    expect(callers.length).toBeGreaterThan(0);
  });
});
