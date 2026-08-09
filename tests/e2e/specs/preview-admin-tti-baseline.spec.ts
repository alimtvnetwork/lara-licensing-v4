import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { test, expect } from "../fixtures/lara-auth";
import { measureRouteTti, type TtiSample } from "../helpers/tti";

/**
 * v0.675.0. TTI baseline for preview admin flows.
 *
 * Records cold (first visit after seed load) and warm (second visit in
 * the same session) time-to-ready per admin route, then compares
 * against `tests/e2e/baselines/preview-admin-tti.json` so performance
 * regressions in the preview runtime surface automatically. Ready
 * signal is defined in `helpers/tti.ts` and matches what a user
 * perceives as "the page is usable".
 *
 * Update the baseline by running with `PREVIEW_TTI_BASELINE_UPDATE=1`
 * and committing the regenerated file. Per-run samples are always
 * written to `test-results/preview-admin-tti.json` so CI can trend
 * cold-vs-warm over time without depending on the baseline file.
 *
 * Contract:
 * - cold <= baseline.coldMs * regressionFactor
 * - warm <= baseline.warmMs * regressionFactor
 * - warm <= cold (warm cannot be slower than cold on the same route)
 */

const ADMIN_ROUTES = [
  "/admin",
  "/admin/resellers",
  "/admin/users",
  "/admin/audit",
  "/admin/quotas",
  "/admin/quota-requests",
  "/admin/app-updates",
  "/admin/serials",
] as const;

const BASELINE_PATH = path.join(
  process.cwd(),
  "tests/e2e/baselines/preview-admin-tti.json",
);
const RESULTS_PATH = path.join(
  process.cwd(),
  "test-results/preview-admin-tti.json",
);

type Baseline = {
  regressionFactor: number;
  routes: Record<string, { coldMs: number; warmMs: number }>;
};

function readBaseline(): Baseline {
  const raw = JSON.parse(readFileSync(BASELINE_PATH, "utf8"));
  return { regressionFactor: raw.regressionFactor, routes: raw.routes };
}

function writeResults(samples: TtiSample[]): void {
  mkdirSync(path.dirname(RESULTS_PATH), { recursive: true });
  writeFileSync(
    RESULTS_PATH,
    JSON.stringify({ recordedAt: new Date().toISOString(), samples }, null, 2),
  );
}

function updateBaseline(samples: TtiSample[], factor: number): void {
  const routes: Baseline["routes"] = {};
  for (const s of samples) {
    routes[s.route] ??= { coldMs: 0, warmMs: 0 };
    if (s.kind === "cold") routes[s.route].coldMs = s.ms;
    else routes[s.route].warmMs = s.ms;
  }
  writeFileSync(
    BASELINE_PATH,
    `${JSON.stringify(
      {
        $schema: "./preview-admin-tti.schema.json",
        note:
          "Regenerated via PREVIEW_TTI_BASELINE_UPDATE=1. Regression factor applied per route/kind.",
        regressionFactor: factor,
        routes,
      },
      null,
      2,
    )}\n`,
  );
}

async function loadDefaultSeed(page: import("@playwright/test").Page): Promise<void> {
  await page.evaluate(async () => {
    const store = await import("/src/lib/preview-store.ts");
    await store.resetAll();
    const mod = await import("/src/lib/preview-seeds/default.ts");
    await mod.loadDefaultSeed();
  });
}

test.describe("preview admin TTI baseline (cold vs warm)", () => {
  test("cold and warm loads stay within baseline for every admin route", async ({
    page,
    signInAsAdmin,
  }, testInfo) => {
    testInfo.setTimeout(120_000);
    await signInAsAdmin();
    await loadDefaultSeed(page);

    const samples: TtiSample[] = [];
    for (const route of ADMIN_ROUTES) {
      // Cold: navigate away to a neutral route first so the target
      // component tree unmounts, then measure the first visit.
      await page.goto("/");
      samples.push(await measureRouteTti(page, route, "cold"));
      // Warm: immediate second visit (component tree may cache, queries
      // hit React Query's fresh window, IndexedDB reads are warm).
      await page.goto("/");
      samples.push(await measureRouteTti(page, route, "warm"));
    }

    writeResults(samples);

    if (process.env.PREVIEW_TTI_BASELINE_UPDATE === "1") {
      updateBaseline(samples, 1.5);
      testInfo.annotations.push({
        type: "baseline-update",
        description: `Wrote ${samples.length} samples to ${BASELINE_PATH}`,
      });
      return;
    }

    const baseline = readBaseline();
    const factor = baseline.regressionFactor;
    const failures: string[] = [];
    const byRoute = new Map<string, { cold?: number; warm?: number }>();
    for (const s of samples) {
      const entry = byRoute.get(s.route) ?? {};
      entry[s.kind] = s.ms;
      byRoute.set(s.route, entry);
      const b = baseline.routes[s.route];
      if (!b) {
        failures.push(`missing baseline for ${s.route}`);
        continue;
      }
      const ceiling = (s.kind === "cold" ? b.coldMs : b.warmMs) * factor;
      if (s.ms > ceiling) {
        failures.push(
          `${s.route} ${s.kind} ${s.ms}ms exceeds ceiling ${ceiling.toFixed(0)}ms (baseline ${
            s.kind === "cold" ? b.coldMs : b.warmMs
          }ms x ${factor})`,
        );
      }
    }
    for (const [route, { cold, warm }] of byRoute) {
      if (cold !== undefined && warm !== undefined && warm > cold) {
        failures.push(`${route} warm ${warm}ms slower than cold ${cold}ms`);
      }
    }

    expect(failures, `TTI regressions:\n${failures.join("\n")}`).toEqual([]);
  });
});
