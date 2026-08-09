import { z } from "zod";

import { requestLaraApi } from "./lara-api-client";

/**
 * Plan 09 step 31 (frontend half). Transport for
 * `GET /Api/Admin/Metrics/ShardStatus`.
 *
 * Root cause this addresses: the dashboard "Recheck now" control
 * (v0.288.0) currently invalidates the entire metrics fanout even
 * when the operator only needs to confirm shard reachability. That
 * both wastes a full cross-tenant fanout and hides the per-shard
 * probe result behind the aggregate KPI refetch. This transport
 * hits the lightweight probe endpoint so the banner can display
 * the fresh per-shard verdict alongside the aggregate refresh.
 *
 * Envelope shape (contract with `MetricsController::shardStatus`):
 *   Results[] : { ResellerSlug, Reachable, Error|null }
 *   Attributes: { CheckedAt, UnreachableCount, ... }
 */
export const shardStatusRowSchema = z.object({
  ResellerSlug: z.string().min(1),
  Reachable: z.boolean(),
  Error: z.string().nullable(),
});

export type ShardStatusRow = z.infer<typeof shardStatusRowSchema>;

export interface ShardStatusSnapshot {
  rows: ShardStatusRow[];
  checkedAt: string;
  unreachableCount: number;
}

export async function fetchShardStatus(signal?: AbortSignal): Promise<ShardStatusSnapshot> {
  let checkedAt = "";
  let unreachableCount = 0;
  const rows = await requestLaraApi("/Metrics/ShardStatus", shardStatusRowSchema, {
    signal,
    onAttributes: (attributes) => {
      const rawCheckedAt = attributes.CheckedAt;
      if (typeof rawCheckedAt === "string") checkedAt = rawCheckedAt;
      const rawUnreachable = attributes.UnreachableCount;
      if (typeof rawUnreachable === "number" && Number.isFinite(rawUnreachable)) {
        unreachableCount = rawUnreachable;
      }
    },
  });

  return { rows, checkedAt, unreachableCount };
}
