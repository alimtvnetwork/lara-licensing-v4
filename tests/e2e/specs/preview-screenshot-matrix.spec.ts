import { mkdirSync, writeFileSync, existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

/**
 * Plan 16 Step 86: preview route x scenario x seed screenshot matrix.
 *
 * Root cause this file addresses (one sentence): the v2 preview-scenarios
 * spec pins a route x scenario x seed matrix but nothing rendered those
 * cells, so Step 87's coverage linter had no artifact to score and
 * degraded-UX regressions shipped invisibly.
 *
 * Contract source of truth: spec/28-runtime-modes/10-screenshot-matrix.md.
 * Scenario semantics: spec/28-runtime-modes/08-preview-scenarios.md v2.
 *
 * Scope: public routes only. Authed admin/portal rows land in Step 93.
 */

const VIEWPORT = { width: 1440, height: 900 } as const;
const SLOW_MID_FLIGHT_WAIT_MS = 900; // half of PREVIEW_SLOW_LATENCY_MS

type Scenario = "null" | "offline" | "slow" | "rate-limited";
type Seed = "default" | "empty" | "error";

const PUBLIC_MATRIX_ROUTES: readonly string[] = [
  "/",
  "/admin/login",
  "/register",
  "/forgot-password",
  "/e2e/error-harness",
];

const SCENARIOS: readonly Scenario[] = ["null", "offline", "slow", "rate-limited"];
const SEEDS: readonly Seed[] = ["default", "empty", "error"];

const ARTIFACT_ROOT = resolve(process.cwd(), "tests/e2e/screenshots/preview-matrix");
const MANIFEST_PATH = resolve(ARTIFACT_ROOT, "index.json");

type Cell = { route: string; scenario: Scenario; seed: Seed; file: string; ok: boolean; reason?: string };
type Manifest = { generatedAt: string; runtimeMode: "preview"; cells: Cell[] };

function slugForRoute(route: string): string {
  if (route === "/") return "root";
  return route.replace(/^\//, "").replace(/\//g, "-");
}

function fileNameFor(scenario: Scenario, seed: Seed): string {
  return `${scenario}.${seed}.png`;
}

function loadManifest(): Manifest {
  if (!existsSync(MANIFEST_PATH)) {
    return { generatedAt: new Date().toISOString(), runtimeMode: "preview", cells: [] };
  }
  return JSON.parse(readFileSync(MANIFEST_PATH, "utf8")) as Manifest;
}

function appendCell(cell: Cell): void {
  mkdirSync(dirname(MANIFEST_PATH), { recursive: true });
  const manifest = loadManifest();
  const key = (c: Cell) => `${c.route}|${c.scenario}|${c.seed}`;
  const filtered = manifest.cells.filter((c) => key(c) !== key(cell));
  filtered.push(cell);
  const next: Manifest = {
    generatedAt: new Date().toISOString(),
    runtimeMode: "preview",
    cells: filtered,
  };
  writeFileSync(MANIFEST_PATH, JSON.stringify(next, null, 2));
}

async function waitForBridge(page: Page): Promise<void> {
  await page.waitForFunction(
    () => Boolean((window as unknown as { __LARA_PREVIEW__?: unknown }).__LARA_PREVIEW__),
    undefined,
    { timeout: 10_000 },
  );
}

async function writeSeed(page: Page, seed: Seed): Promise<void> {
  await page.evaluate((nextSeed) => {
    const KEY = "lara.runtime.override.v1";
    const raw = window.localStorage.getItem(KEY);
    const cfg = raw ? JSON.parse(raw) : { Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default", Version: "0", WrittenAt: new Date().toISOString() };
    cfg.PreviewSeed = nextSeed;
    cfg.WrittenAt = new Date().toISOString();
    window.localStorage.setItem(KEY, JSON.stringify(cfg));
  }, seed);
}

async function applyScenario(page: Page, scenario: Scenario): Promise<void> {
  await page.evaluate((s) => {
    const value = s === "null" ? null : s;
    const bridge = (window as unknown as { __LARA_PREVIEW__?: { setScenario: (v: string | null) => void } }).__LARA_PREVIEW__;
    if (!bridge) throw new Error("preview bridge missing");
    bridge.setScenario(value);
  }, scenario);
}

async function captureCell(page: Page, route: string, scenario: Scenario, seed: Seed): Promise<Cell> {
  const dir = resolve(ARTIFACT_ROOT, slugForRoute(route));
  const filePath = resolve(dir, fileNameFor(scenario, seed));
  const relFile = `${slugForRoute(route)}/${fileNameFor(scenario, seed)}`;
  try {
    mkdirSync(dir, { recursive: true });
    await writeSeed(page, seed);
    await page.goto(route, { waitUntil: "domcontentloaded" });
    await waitForBridge(page);
    await applyScenario(page, scenario);
    if (scenario === "slow") await page.waitForTimeout(SLOW_MID_FLIGHT_WAIT_MS);
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: filePath, animations: "disabled", clip: { x: 0, y: 0, ...VIEWPORT } });
    return { route, scenario, seed, file: relFile, ok: true };
  } catch (err) {
    const reason = err instanceof Error ? err.message : String(err);
    return { route, scenario, seed, file: relFile, ok: false, reason };
  }
}

test.describe("preview screenshot matrix (public routes)", () => {
  test.use({ viewport: VIEWPORT });

  for (const route of PUBLIC_MATRIX_ROUTES) {
    for (const scenario of SCENARIOS) {
      for (const seed of SEEDS) {
        test(`${route} @ ${scenario} / ${seed}`, async ({ page }) => {
          const cell = await captureCell(page, route, scenario, seed);
          appendCell(cell);
          expect(cell.ok, cell.reason ?? "cell failed").toBe(true);
        });
      }
    }
  }
});
