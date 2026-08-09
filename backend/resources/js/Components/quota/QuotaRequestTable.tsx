// Plan 06 step 69. QuotaRequests list for the Inertia console, both modes.
//
// Ported from src/components/quota/quota-request-list.tsx. Differences forced
// by the Inertia environment:
//  - Rows arrive as Inertia props (server truth from
//    Admin\QuotaRequestController::index/indexAll or
//    Reseller\QuotaRequestController::index); there is no react-query cache,
//    so a successful mutation calls router.reload() and re-reads the shard
//    instead of mutating local state.
//  - mode=admin renders Approve/Deny, which require `?ResellerSlug=` for shard
//    binding (Admin\QuotaRequestController::requireResellerSlug); a row with no
//    slug says so explicitly rather than firing a request that would 400.
//  - mode=reseller renders Cancel on Pending rows only; the backend also
//    refuses non-Pending with IdempotencyConflict, so this is UX, not the gate.

import * as React from "react";
import { router } from "@inertiajs/react";

import { Button } from "@/Components/ui/Button";
import { EmptyState } from "@/Components/ui/EmptyState";
import { laraRequest, LaraApiError } from "@/lib/lara-api";
import { categoryLabel, quotaStatusLabel, tierLabel, QUOTA_REQUEST_STATUS } from "@/lib/closed-sets";

export interface QuotaRequestRow {
  QuotaRequestId: number;
  ResellerId: number;
  LicenseCategoryId: number;
  LicenseTierId: number;
  RequestedDelta: number;
  ApprovedDelta: number | null;
  Status: number;
  StatusName?: string | null;
  Justification: string;
  DenialReason?: string | null;
  SubmittedByUserId: number;
  DecidedByUserId?: number | null;
  SubmittedAt: string;
  DecidedAt?: string | null;
  RequestId?: string | null;
  /** Present only on the admin fanout inbox (indexAll). */
  ResellerSlug?: string | null;
}

export type QuotaRequestMode = "admin" | "reseller";

const dateFormatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: "medium",
  timeStyle: "short",
});

function formatMoment(value: string | null | undefined): string {
  if (!value) return "unknown";
  const parsed = Date.parse(value);
  return Number.isNaN(parsed) ? "unknown" : dateFormatter.format(new Date(parsed));
}

function statusTone(status: number): string {
  if (status === QUOTA_REQUEST_STATUS.Pending) return "bg-primary/10 text-primary";
  if (status === QUOTA_REQUEST_STATUS.Approved) return "bg-emerald-500/10 text-emerald-600";
  if (status === QUOTA_REQUEST_STATUS.Denied) return "bg-destructive/10 text-destructive";
  return "bg-muted text-muted-foreground";
}

export function QuotaRequestTable({
  rows,
  mode,
  resellerSlug,
}: {
  rows: QuotaRequestRow[];
  mode: QuotaRequestMode;
  /** Fallback slug used when a row carries none (single-shard admin listing). */
  resellerSlug?: string | null;
}) {
  const [error, setError] = React.useState<string | null>(null);

  if (rows.length === 0) {
    return (
      <EmptyState
        preset="box"
        headline="No quota requests"
        body={
          mode === "admin"
            ? "No reseller has submitted a quota request matching this filter."
            : "You have not submitted a quota request yet."
        }
      />
    );
  }

  return (
    <div className="space-y-3">
      {error !== null && (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      )}
      <div className="border-border overflow-x-auto rounded-lg border">
        <table className="w-full text-sm">
          <caption className="sr-only">Quota requests</caption>
          <thead className="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
            <tr>
              <th scope="col" className="px-4 py-3 text-left">Request</th>
              {mode === "admin" && <th scope="col" className="px-4 py-3 text-left">Reseller</th>}
              <th scope="col" className="px-4 py-3 text-left">Category / Tier</th>
              <th scope="col" className="px-4 py-3 text-right">Requested</th>
              <th scope="col" className="px-4 py-3 text-right">Approved</th>
              <th scope="col" className="px-4 py-3 text-left">Status</th>
              <th scope="col" className="px-4 py-3 text-left">Submitted</th>
              <th scope="col" className="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-border divide-y">
            {rows.map((row) => (
              <tr key={row.QuotaRequestId} className="hover:bg-muted/30">
                <td className="px-4 py-3">
                  <span className="font-medium">#{row.QuotaRequestId}</span>
                  <p className="text-muted-foreground max-w-[36ch] truncate text-xs">
                    {row.Justification === "" ? "unknown" : row.Justification}
                  </p>
                </td>
                {mode === "admin" && (
                  <td className="px-4 py-3 font-mono text-xs">
                    {row.ResellerSlug ?? resellerSlug ?? "unknown"}
                  </td>
                )}
                <td className="px-4 py-3">
                  {categoryLabel(row.LicenseCategoryId)} / {tierLabel(row.LicenseTierId)}
                </td>
                <td className="px-4 py-3 text-right font-mono">+{row.RequestedDelta}</td>
                <td className="px-4 py-3 text-right font-mono">
                  {row.ApprovedDelta === null || row.ApprovedDelta === undefined
                    ? "unknown"
                    : `+${row.ApprovedDelta}`}
                </td>
                <td className="px-4 py-3">
                  <span
                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusTone(row.Status)}`}
                  >
                    {row.StatusName ?? quotaStatusLabel(row.Status)}
                  </span>
                  {row.Status === QUOTA_REQUEST_STATUS.Denied && (
                    <p className="text-muted-foreground max-w-[30ch] truncate text-xs">
                      {row.DenialReason === "" || row.DenialReason === null || row.DenialReason === undefined
                        ? "unknown"
                        : row.DenialReason}
                    </p>
                  )}
                </td>
                <td className="px-4 py-3 text-xs">{formatMoment(row.SubmittedAt)}</td>
                <td className="px-4 py-3 text-right">
                  <RowActions
                    row={row}
                    mode={mode}
                    resellerSlug={row.ResellerSlug ?? resellerSlug ?? ""}
                    onError={setError}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function RowActions({
  row,
  mode,
  resellerSlug,
  onError,
}: {
  row: QuotaRequestRow;
  mode: QuotaRequestMode;
  resellerSlug: string;
  onError: (message: string | null) => void;
}) {
  const [busy, setBusy] = React.useState(false);

  if (row.Status !== QUOTA_REQUEST_STATUS.Pending) {
    return <span className="text-muted-foreground text-xs">Decided {formatMoment(row.DecidedAt)}</span>;
  }

  const run = async (task: () => Promise<unknown>) => {
    setBusy(true);
    onError(null);
    try {
      await task();
      router.reload();
    } catch (cause) {
      const message =
        cause instanceof LaraApiError
          ? `${cause.code}: ${cause.message} (request ${cause.requestId})`
          : "Quota request action failed.";
      onError(message);
    } finally {
      setBusy(false);
    }
  };

  if (mode === "reseller") {
    return (
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={busy}
        onClick={() =>
          void run(() =>
            laraRequest(`/Api/Reseller/QuotaRequests/${row.QuotaRequestId}/Cancel`, { method: "POST" }),
          )
        }
      >
        Cancel
      </Button>
    );
  }

  const slug = resellerSlug.trim();
  if (slug === "") {
    return <span className="text-muted-foreground text-xs">Missing reseller slug</span>;
  }
  const query = `?ResellerSlug=${encodeURIComponent(slug)}`;

  return (
    <div className="flex justify-end gap-2">
      <Button
        type="button"
        size="sm"
        disabled={busy}
        onClick={() =>
          void run(() =>
            laraRequest(`/Api/Admin/QuotaRequests/${row.QuotaRequestId}/Approve${query}`, {
              method: "POST",
              body: { ApprovedDelta: row.RequestedDelta },
            }),
          )
        }
      >
        Approve
      </Button>
      <Button
        type="button"
        variant="destructive"
        size="sm"
        disabled={busy}
        onClick={() => {
          const reason = window.prompt("Denial reason (min 8 characters)")?.trim() ?? "";
          if (reason.length < 8) {
            onError("Denial reason must be at least 8 characters.");
            return;
          }
          void run(() =>
            laraRequest(`/Api/Admin/QuotaRequests/${row.QuotaRequestId}/Deny${query}`, {
              method: "POST",
              body: { DenialReason: reason },
            }),
          );
        }}
      >
        Deny
      </Button>
    </div>
  );
}
