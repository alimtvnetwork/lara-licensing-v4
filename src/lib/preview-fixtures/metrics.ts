/**
 * Preview fixtures: admin metrics domain (Plan 16 Step 48).
 *
 * Registers one operation:
 *   - admin.metrics.kpis (GET /api/admin/metrics/kpis)
 *
 * Behaviour:
 *   * Reads the seeded singleton at `metrics::kpis`.
 *   * honours `Since` / `Until` parameters by calculating synthetic deltas
 *     and trend shifts based on a deterministic hash of the seed and range.
 *     (Plan 18 Step 64: Range Parity).
 *   * `empty` seed returns zeroed tiles.
 *   * `error` seed rejects with 422.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { read } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminMetricsKpisRequest,
  AdminMetricsKpisResponse,
  KpiTile,
} from "@/generated/api/schema";

const HTTP_UNPROCESSABLE = 422;
const KPIS_KEY = "kpis";

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.metrics,
    "Preview error seed active: metrics.kpis is denied (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

function emptyPayload(): AdminMetricsKpisResponse {
  return {
    Tiles: [
      {
        Key: "licenses.active",
        Label: "Active Licenses",
        Value: 0,
        Unit: "count",
        Delta: 0,
        Trend: "flat",
      },
      {
        Key: "licenses.expiring",
        Label: "Expiring 30d",
        Value: 0,
        Unit: "count",
        Delta: 0,
        Trend: "flat",
      },
      {
        Key: "quota.utilization",
        Label: "Quota Utilization",
        Value: 0,
        Unit: "percent",
        Delta: 0,
        Trend: "flat",
      },
      {
        Key: "updates.adoption",
        Label: "Update Adoption",
        Value: 0,
        Unit: "percent",
        Delta: 0,
        Trend: "flat",
      },
    ],
    GeneratedAt: new Date().toISOString(),
  };
}

/**
 * Synthetic range-based variation (Plan 18 Step 64).
 * Adjusts Value/Delta based on the range duration to simulate real trends.
 */
function applyRangeVariation(tiles: KpiTile[], params: Record<string, any>): KpiTile[] {
  const since = params.Since ? new Date(params.Since).getTime() : 0;
  const until = params.Until ? new Date(params.Until).getTime() : Date.now();
  const durationDays = Math.max(1, Math.floor((until - since) / (1000 * 60 * 60 * 24)));

  return tiles.map((t) => {
    // Deterministic shift based on duration
    const factor = durationDays > 7 ? 1.2 : 0.8;
    const value = Math.round(t.Value * factor);
    const delta = t.Delta !== null ? Math.round(t.Delta * (durationDays / 7)) : null;
    const trend = delta && delta > 0 ? "up" : delta && delta < 0 ? "down" : "flat";

    return { ...t, Value: value, Delta: delta, Trend: trend as any };
  });
}

const mod: PreviewFixtureModule = {
  name: "metrics",
  operations: ["admin.metrics.kpis"],
  register(): void {
    registerPreviewHandler("admin.metrics.kpis", async (ctx): Promise<AdminMetricsKpisResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const params = ctx.Params as AdminMetricsKpisRequest;
      const stored = await read<AdminMetricsKpisResponse>("metrics", KPIS_KEY);
      const payload = stored || emptyPayload();

      // Plan 18 Step 64: Apply synthetic variation if range params are present
      const tiles = applyRangeVariation(payload.Tiles, params);

      console.info("preview-fixtures:admin.metrics.kpis", {
        RequestId: ctx.RequestId,
        TileCount: tiles.length,
        Range: { Since: params.Since, Until: params.Until },
      });

      return previewSuccess<"admin.metrics.kpis">({
        ...payload,
        Tiles: tiles,
        GeneratedAt: new Date().toISOString(),
      });
    });
  },
};

export default mod;
