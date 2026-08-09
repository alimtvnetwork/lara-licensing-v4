import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * Plan 16 steps 64-65. Architecture test: pins the boundary between the
 * generated typed transport (`apiClient.call` / `useApi` /
 * `useApiMutation`) and the real-BE Zod transport
 * (`requestLaraApi` in src/lib/lara-*.ts). See
 * `spec/25-app-audit/05-api-contract-duality.md` for the drift audit.
 *
 * Step 65 correction: `admin.quotas.list/update` model an aspirational
 * `{Allocated, Used, Restored}` shape that does NOT match the real BE
 * (`resellerQuotaSchema` returns `{LicensesGranted, LicensesConsumed,
 * LicensesRemaining, LicenseCategoryId, LicenseTierId}`). The route
 * therefore only works in preview mode. It is quarantined below under
 * PREVIEW_ONLY_SHAPE_ROUTES and MUST carry an inline
 * `preview-only-shape:` marker so the divergence is visible in source.
 * Only `admin.runtime.tsx` is truly real-BE-aligned today.
 */

const ROUTES_DIR = "src/routes";

/** Routes whose typed operations are verified against the real Laravel BE. */
const REAL_BE_ROUTES = new Set<string>([
  "src/routes/_authenticated/admin.runtime.tsx",
]);

/**
 * Routes that use the typed transport but whose operation shape is preview-only
 * (schema.d.ts diverges from the real BE Zod contract). Each entry MUST include
 * the `preview-only-shape:` marker in its source to make the drift auditable.
 */
const PREVIEW_ONLY_SHAPE_ROUTES = new Set<string>([
  "src/routes/_authenticated/admin.quotas.tsx",
  "src/routes/_authenticated/admin.backup.export.tsx",
  "src/routes/_authenticated/admin.backup.import.tsx",
  "src/routes/_authenticated/admin.roles.tsx",
  "src/routes/_authenticated/admin.snapshots.$snapshotId.tsx",
  "src/routes/_authenticated/admin.snapshots.index.tsx",
]);

const BANNED_IMPORT_PATTERN =
  /from\s+["'][^"']*(?:\/api-client|\/hooks\/use-api)["']|from\s+["']@\/lib\/api-client["']|from\s+["']@\/hooks\/use-api["']/;

const PREVIEW_ONLY_MARKER = /preview-only-shape:/;

function walk(dir: string, acc: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const info = statSync(full);
    if (info.isDirectory()) {
      walk(full, acc);
    } else if (entry.endsWith(".tsx") || entry.endsWith(".ts")) {
      acc.push(full.replace(/\\/g, "/"));
    }
  }
  return acc;
}

const ALLOWED = new Set<string>([...REAL_BE_ROUTES, ...PREVIEW_ONLY_SHAPE_ROUTES]);

describe("api client / useApi boundary", () => {
  it("only allowlisted route files import api-client or use-api", () => {
    const offenders: string[] = [];
    for (const file of walk(ROUTES_DIR)) {
      if (ALLOWED.has(file)) continue;
      const src = readFileSync(file, "utf8");
      if (BANNED_IMPORT_PATTERN.test(src)) offenders.push(file);
    }
    expect(offenders, `Unexpected api-client / use-api imports: ${offenders.join(", ")}`).toEqual([]);
  });

  it("allowlisted routes actually import the typed layer (guards against dead allowlist rot)", () => {
    for (const file of ALLOWED) {
      const src = readFileSync(file, "utf8");
      expect(BANNED_IMPORT_PATTERN.test(src), `${file} no longer uses the typed layer`).toBe(true);
    }
  });

  it("preview-only-shape routes carry the inline drift marker", () => {
    for (const file of PREVIEW_ONLY_SHAPE_ROUTES) {
      const src = readFileSync(file, "utf8");
      expect(
        PREVIEW_ONLY_MARKER.test(src),
        `${file} is quarantined as preview-only-shape but is missing the 'preview-only-shape:' marker comment`,
      ).toBe(true);
    }
  });

  it("real-BE routes do NOT carry the preview-only-shape marker (guards against silent demotion)", () => {
    for (const file of REAL_BE_ROUTES) {
      const src = readFileSync(file, "utf8");
      expect(
        PREVIEW_ONLY_MARKER.test(src),
        `${file} is listed as real-BE-aligned but source contains 'preview-only-shape:' marker`,
      ).toBe(false);
    }
  });
});
