import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { useState } from "react";

import { PageHeader } from "../../components/shell/PageHeader";
import { RecentActivity } from "../../components/admin/recent-activity";
import { StatCard, type StatCardState } from "../../components/ui/stat-card";
import {
  adminMetricsQueryOptions,
  type AdminMetrics,
  type AdminMetricsWarning,
} from "../../lib/lara-metrics";
import { fetchShardStatus, type ShardStatusSnapshot } from "../../lib/lara-shard-status";
import { formatLaraApiError } from "../../lib/lara-api-error";

export const Route = createFileRoute("/_authenticated/admin/")({
  head: () => ({ meta: [{ title: "Overview | Licensing Portal" }] }),
  component: AdminOverviewPage,
});

/**
 * Plan 09 Step 29 + 29b. Wires the Admin dashboard to
 * `GET /Api/Admin/Metrics` via `adminMetricsQueryOptions` (30s stale)
 * and renders four StatCards plus a Warnings banner for per-shard
 * fanout failures. Loading and error states are surfaced explicitly
 * per Spec 24 §26 (no "silent zero" KPIs, no swallowed fetches).
 * Warnings arrive in `Attributes.Warnings[]`; surfacing them prevents
 * a poisoned shard from silently deflating the KPI totals.
 */
function AdminOverviewPage() {
  const query = useQuery(adminMetricsQueryOptions);
  const state: StatCardState = query.isPending ? "loading" : query.isError ? "error" : "ready";
  const errorMessage =
    query.error !== null && query.error !== undefined ? formatLaraApiError(query.error) : undefined;
  const metrics = query.data?.metrics;
  const warnings = query.data?.warnings ?? [];

  return (
    <>
      <section
        aria-label="Overview headline"
        data-shell-region="admin-headline"
        className="dot-pattern surface-elevated relative overflow-hidden p-6"
      >
        <div className="relative z-10">
          <PageHeader title="Overview" description="Licensing Portal administrative operations." />
          {typeof metrics?.GeneratedAt === "string" ? (
            <p
              className="mt-1 text-xs text-muted-foreground"
              style={{ fontFamily: "var(--font-mono)" }}
            >
              Updated {new Date(metrics.GeneratedAt).toLocaleTimeString()}
            </p>
          ) : null}
        </div>
      </section>
      {warnings.length > 0 ? (
        <WarningsBanner warnings={warnings} isRefetching={query.isFetching} />
      ) : null}
      <section
        aria-label="Key metrics"
        className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        data-shell-region="admin-kpis"
      >
        {tileDefinitions(metrics).map((tile) => (
          <StatCard
            key={tile.label}
            label={tile.label}
            value={tile.value}
            hint={tile.hint}
            state={state}
            errorMessage={errorMessage}
          />
        ))}
      </section>
      <RecentActivity />
    </>
  );
}

interface Tile {
  label: string;
  value: string;
  hint: string;
}

function tileDefinitions(metrics: AdminMetrics | undefined): Tile[] {
  const fmt = (n: number | undefined): string =>
    typeof n === "number" ? n.toLocaleString() : "--";

  return [
    { label: "Active resellers", value: fmt(metrics?.ResellersActive), hint: "Root scope." },
    {
      label: "Active sessions",
      value: fmt(metrics?.SessionsActive),
      hint: "Unexpired auth sessions.",
    },
    { label: "Licenses issued", value: fmt(metrics?.LicensesTotal), hint: "Sum across shards." },
    {
      label: "Quota requests pending",
      value: fmt(metrics?.QuotaRequestsPending),
      hint: "Awaiting decision.",
    },
  ];
}

function WarningsBanner({
  warnings,
  isRefetching,
}: {
  warnings: AdminMetricsWarning[];
  isRefetching: boolean;
}) {
  const queryClient = useQueryClient();
  const [lastSnapshot, setLastSnapshot] = useState<ShardStatusSnapshot | null>(null);
  const recheck = useMutation({
    mutationFn: () => fetchShardStatus(),
    onSuccess: (snapshot) => {
      setLastSnapshot(snapshot);
      void queryClient.invalidateQueries({ queryKey: adminMetricsQueryOptions.queryKey });
    },
    onError: (error: unknown) => {
      console.warn("admin.metrics.shard_status.recheck_error", {
        message: error instanceof Error ? error.message : String(error),
      });
    },
  });
  const busy = recheck.isPending || isRefetching;
  const checkedLabel =
    lastSnapshot !== null && lastSnapshot.checkedAt.length > 0
      ? `Rechecked ${new Date(lastSnapshot.checkedAt).toLocaleTimeString()} - ${lastSnapshot.unreachableCount} unreachable`
      : null;

  return (
    <div
      role="status"
      data-ui="admin-metrics-warnings"
      className="flex items-start gap-3 rounded-md border p-3 text-sm"
      style={{
        borderColor:
          "color-mix(in oklab, var(--color-warning, var(--color-accent)) 60%, transparent)",
        backgroundColor:
          "color-mix(in oklab, var(--color-warning, var(--color-accent)) 12%, var(--color-background))",
        color: "var(--color-foreground)",
        fontFamily: "var(--font-sans)",
      }}
    >
      <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 flex-1">
        <p className="font-semibold">
          {warnings.length === 1
            ? "1 shard did not respond; totals may be incomplete."
            : `${warnings.length} shards did not respond; totals may be incomplete.`}
        </p>
        <ul className="mt-1 space-y-0.5 text-xs" style={{ fontFamily: "var(--font-mono)" }}>
          {warnings.map((w) => (
            <li key={w.ResellerSlug}>
              {w.ResellerSlug}: {w.Error}
            </li>
          ))}
        </ul>
        {checkedLabel !== null ? (
          <p
            className="mt-1 text-xs text-muted-foreground"
            style={{ fontFamily: "var(--font-mono)" }}
          >
            {checkedLabel}
          </p>
        ) : null}
      </div>
      <button
        type="button"
        onClick={() => recheck.mutate()}
        disabled={busy}
        data-ui="admin-metrics-recheck"
        className="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium hover:bg-accent/10 disabled:opacity-60"
      >
        <RefreshCw aria-hidden="true" className={`size-3.5 ${busy ? "animate-spin" : ""}`} />
        {busy ? "Rechecking..." : "Recheck now"}
      </button>
    </div>
  );
}
