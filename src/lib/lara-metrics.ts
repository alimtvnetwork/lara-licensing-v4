import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { requestLaraApi } from "./lara-api-client";

/**
 * Plan 09 steps 30 and 29b. Transport for `GET /Api/Admin/Metrics`.
 *
 * The controller emits a single-object `Results` array with dashboard KPIs
 * and per-shard fanout errors under `Attributes.Warnings[]`. We capture
 * Warnings via `onAttributes` so the dashboard can surface them next to
 * the KPI grid; hiding them would let a poisoned shard render as a silent
 * zero, exactly the regression Spec 24 §26 forbids.
 */
export const adminMetricsSchema = z.object({
  ResellersActive: z.number().int().nonnegative(),
  SessionsActive: z.number().int().nonnegative(),
  LicensesTotal: z.number().int().nonnegative(),
  QuotaRequestsPending: z.number().int().nonnegative(),
  GeneratedAt: z.string().datetime(),
});

export type AdminMetrics = z.infer<typeof adminMetricsSchema>;

export const adminMetricsWarningSchema = z.object({
  ResellerSlug: z.string(),
  Error: z.string(),
});

export type AdminMetricsWarning = z.infer<typeof adminMetricsWarningSchema>;

export interface AdminMetricsSnapshot {
  metrics: AdminMetrics;
  warnings: AdminMetricsWarning[];
}

const METRICS_STALE_MS = 30_000;

export const adminMetricsQueryOptions = queryOptions({
  queryKey: ["LaraApi", "Admin", "Metrics"],
  queryFn: async ({ signal }): Promise<AdminMetricsSnapshot> => {
    let warnings: AdminMetricsWarning[] = [];
    const [metrics] = await requestLaraApi("/Metrics", adminMetricsSchema, {
      signal,
      onAttributes: (attributes) => {
        const raw = attributes.Warnings;
        if (Array.isArray(raw) === false) return;
        const parsed = z.array(adminMetricsWarningSchema).safeParse(raw);
        if (parsed.success) {
          warnings = parsed.data;

          return;
        }
        console.warn("Admin metrics Warnings envelope mismatch", {
          issues: parsed.error.issues,
        });
      },
    });

    return { metrics, warnings };
  },
  staleTime: METRICS_STALE_MS,
  retry: false,
});
