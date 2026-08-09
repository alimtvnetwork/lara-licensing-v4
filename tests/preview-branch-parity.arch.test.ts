/**
 * Plan 17 Step 13: TS-level architecture test for preview-branch parity.
 *
 * Root cause guarded: `linter-scripts/check-preview-branch-parity.py`
 * (Plan 17 Step 12) enforces at CI-lint time that every `src/lib/lara-*.ts`
 * caller of `requestLaraApi(` either carries a `getRuntimeMode().Mode ===
 * "preview"` branch or is on the frozen baseline waiver. That Python
 * scan runs from `linter-scripts/run.sh`, not from `bunx vitest run`,
 * so a developer editing locally could ship a regression that only
 * surfaces later on the shell-linter step. This test mirrors the same
 * rule inside Vitest so it fails on the same signal a developer already
 * runs before opening a PR.
 *
 * Contract (must match the Python linter byte-for-byte):
 *   - Universe: `src/lib/lara-*.ts` excluding infra modules
 *     (`lara-fetch`, `lara-api-*`, `lara-envelope`, `lara-environment`,
 *     `lara-retry`, `lara-shell-role`, `lara-sidebar-collapsed`).
 *   - A file is BRANCHED iff it contains the literal
 *     `getRuntimeMode().Mode === "preview"`.
 *   - A file is UN-BRANCHED iff it calls `requestLaraApi(` and is not
 *     branched.
 *   - Waivers are loaded from `linter-scripts/check-preview-branch-parity.waivers.txt`
 *     (parsed the same way as the Python linter: strip, skip empty and
 *     `#`-prefixed lines).
 *   - Fail if: (a) any un-branched file is not on the waiver list, or
 *     (b) any waiver is stale (file no longer calls `requestLaraApi(` or
 *     is now branched), or (c) any waiver targets a non-existent file.
 */
import { readFileSync, existsSync, readdirSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const REPO_ROOT = resolve(__dirname, "..");
const LIB_DIR = join(REPO_ROOT, "src", "lib");
const WAIVER_FILE = join(
  REPO_ROOT,
  "linter-scripts",
  "check-preview-branch-parity.waivers.txt",
);

const INFRA_SKIP = new Set([
  "src/lib/lara-fetch.ts",
  "src/lib/lara-api-client.ts",
  "src/lib/lara-api-contract.ts",
  "src/lib/lara-api-error.ts",
  "src/lib/lara-api-response.ts",
  "src/lib/lara-api-session.ts",
  "src/lib/lara-envelope.ts",
  "src/lib/lara-environment.ts",
  "src/lib/lara-retry.ts",
  "src/lib/lara-shell-role.ts",
  "src/lib/lara-sidebar-collapsed.ts",
]);

const CALL_PATTERN = /\brequestLaraApi\s*\(/;
const BRANCH_LITERAL = 'getRuntimeMode().Mode === "preview"';

function loadWaivers(): Set<string> {
  if (!existsSync(WAIVER_FILE)) return new Set();
  const raw = readFileSync(WAIVER_FILE, "utf8");
  const out = new Set<string>();
  for (const line of raw.split(/\r?\n/)) {
    const s = line.trim();
    if (!s || s.startsWith("#")) continue;
    out.add(s);
  }
  return out;
}

function listLaraFiles(): string[] {
  return readdirSync(LIB_DIR)
    .filter((f) => f.startsWith("lara-") && f.endsWith(".ts"))
    .map((f) => `src/lib/${f}`)
    .sort();
}

function classify(rel: string): { calls: boolean; branched: boolean } {
  const text = readFileSync(join(REPO_ROOT, rel), "utf8");
  return { calls: CALL_PATTERN.test(text), branched: text.includes(BRANCH_LITERAL) };
}

describe("preview-branch parity (Plan 17 Step 13, mirrors Step 12 linter)", () => {
  const waivers = loadWaivers();
  const files = listLaraFiles().filter((rel) => !INFRA_SKIP.has(rel));

  it("no un-branched `requestLaraApi(` outside the baseline waiver", () => {
    const offenders: string[] = [];
    for (const rel of files) {
      const { calls, branched } = classify(rel);
      if (!calls || branched) continue;
      if (!waivers.has(rel)) offenders.push(rel);
    }
    expect(
      offenders,
      `Add a getRuntimeMode().Mode === "preview" branch (or route through apiClient.call) in these files, or extend linter-scripts/check-preview-branch-parity.waivers.txt with justification: ${JSON.stringify(offenders)}`,
    ).toEqual([]);
  });

  it("no stale waivers (branched or callless files must be removed)", () => {
    const stale: string[] = [];
    for (const rel of waivers) {
      if (INFRA_SKIP.has(rel)) {
        stale.push(rel);
        continue;
      }
      const abs = join(REPO_ROOT, rel);
      if (!existsSync(abs)) {
        stale.push(rel);
        continue;
      }
      const { calls, branched } = classify(rel);
      if (!calls || branched) stale.push(rel);
    }
    expect(
      stale,
      `Remove these from linter-scripts/check-preview-branch-parity.waivers.txt (file is branched, has no requestLaraApi(, or does not exist): ${JSON.stringify(stale)}`,
    ).toEqual([]);
  });

  it("baseline waiver count matches Plan 17 Step 12 snapshot", () => {
    // Locks the initial 10-file snapshot; each Plan 17 bridge step
    // MUST decrement this by removing exactly one waiver entry.
    expect(waivers.size).toBeLessThanOrEqual(10);
  });
});
