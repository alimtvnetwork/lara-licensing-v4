import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { apiClient } from "./api-client";
import { requestLaraApi } from "./lara-api-client";
import { getRuntimeMode } from "./runtime-mode";
import type { AuditEntry } from "@/generated/api/schema";

/**
 * Plan 09 step 23 (live) + Plan 17 step 2 (preview bridge).
 *
 * Root cause fixed here: legacy consumers (`admin.audit.tsx`,
 * `license-ledger`, `user-activity`, `reseller-activity`) call
 * `auditLogsQueryOptions()`, which used to always hit
 * `requestLaraApi("/AuditLogs")`. In `Mode=preview` that path is
 * blocked by `assertRequestNotPreview` in `lara-api-client.ts:232`
 * so the audit page crashed with "requestLaraApi invoked in preview
 * mode". A typed preview handler for `admin.audit.list` already
 * exists in `src/lib/preview-fixtures/audit.ts`; this module now
 * bridges the two by adapting `AuditEntry` (preview schema) into
 * the legacy `AuditLog` shape that route consumers dereference.
 * Live/production mode is unchanged.
 */
export const auditLogSchema = z.object({
  AuditLogId: z.number().int().positive(),
  ActorType: z.enum(["User", "AppBuilder", "System"]),
  ActorId: z.number().int().positive().nullable(),
  Action: z.string().min(1),
  TargetType: z.string().min(1),
  TargetId: z.string().nullable(),
  RequestId: z.string(),
  Payload: z.record(z.string(), z.unknown()).nullable(),
  CreatedAt: z.string().min(1),
});

export type AuditLog = z.infer<typeof auditLogSchema>;

export interface AuditLogFilters {
  Action?: string;
  TargetType?: string;
  ActorId?: number;
  Limit?: number;
}

const AUDIT_LIMIT_DEFAULT = 100;

function buildQuery(filters: AuditLogFilters): string {
  const params = new URLSearchParams();
  params.set("Limit", String(filters.Limit ?? AUDIT_LIMIT_DEFAULT));
  if (filters.Action !== undefined && filters.Action !== "") params.set("Action", filters.Action);
  if (filters.TargetType !== undefined && filters.TargetType !== "")
    params.set("TargetType", filters.TargetType);
  if (filters.ActorId !== undefined) params.set("ActorId", String(filters.ActorId));

  return params.toString();
}

function adaptEntry(entry: AuditEntry, index: number): AuditLog {
  const hasActor = entry.ActorUserId !== null;

  return {
    AuditLogId: index + 1,
    ActorType: hasActor ? "User" : "System",
    ActorId: hasActor ? 1 : null,
    Action: entry.EventType,
    TargetType: entry.TargetType,
    TargetId: entry.TargetId,
    RequestId: entry.RequestId,
    Payload: entry.Payload ?? null,
    CreatedAt: entry.OccurredAt,
  };
}

function applyLegacyFilters(rows: AuditLog[], filters: AuditLogFilters): AuditLog[] {
  return rows.filter((row) => {
    if (
      filters.TargetType !== undefined &&
      filters.TargetType !== "" &&
      row.TargetType !== filters.TargetType
    )
      return false;
    if (filters.ActorId !== undefined && row.ActorId !== filters.ActorId) return false;

    return true;
  });
}

async function fetchPreview(filters: AuditLogFilters, signal?: AbortSignal): Promise<AuditLog[]> {
  const limit = filters.Limit ?? AUDIT_LIMIT_DEFAULT;
  const res = await apiClient.call(
    "admin.audit.list",
    filters.Action !== undefined && filters.Action !== "" ? { EventType: filters.Action } : {},
    { signal },
  );
  const adapted = res.Items.map(adaptEntry);
  const filtered = applyLegacyFilters(adapted, filters);
  console.info("lara-audit:preview-bridge", { Total: filtered.length, Limit: limit });

  return filtered.slice(0, limit);
}

export function auditLogsQueryOptions(filters: AuditLogFilters = {}) {
  const qs = buildQuery(filters);

  return queryOptions({
    queryKey: ["LaraApi", "Admin", "AuditLogs", filters],
    queryFn: ({ signal }) => {
      if (getRuntimeMode().Mode === "preview") return fetchPreview(filters, signal);

      return requestLaraApi(`/AuditLogs?${qs}`, auditLogSchema, { signal });
    },
    retry: false,
    staleTime: 15_000,
  });
}
