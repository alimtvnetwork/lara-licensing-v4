import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";

import { formatLaraApiErrorOptional } from "../../lib/lara-api-error";
import { useLaraErrorToast } from "../../lib/use-lara-error-toast";
import {
  approveQuotaRequest,
  cancelQuotaRequest,
  denyQuotaRequest,
  quotaRequestListQueryOptions,
  QuotaRequestStatusType,
  type QuotaRequest,
  type QuotaRequestStatusValue,
} from "../../lib/lara-quota";

type Mode = "admin" | "reseller";

/**
 * QuotaRequests list per spec/21-app/42-quota-requests.md.
 * mode=admin renders Approve/Deny; mode=reseller renders Cancel (Pending only).
 */
export function QuotaRequestList({
  resellerId,
  resellerSlug,
  mode,
  status,
}: {
  resellerId: number;
  /**
   * Required when `mode === "admin"`: the backend Admin Approve/Deny endpoints
   * require `?ResellerSlug=` for shard binding (see `Admin\QuotaRequestController::requireResellerSlug`).
   * Optional for reseller mode since Cancel is shard-bound via middleware.
   */
  resellerSlug?: string;
  mode: Mode;
  status?: QuotaRequestStatusValue;
}) {
  const query = useQuery(quotaRequestListQueryOptions(resellerId, status));
  const err = formatLaraApiErrorOptional(query.error);
  if (query.isPending) return <p className="text-sm text-muted-foreground">Loading requests...</p>;
  if (err)
    return (
      <p role="alert" className="text-sm text-destructive">
        {err}
      </p>
    );
  const rows = query.data ?? [];
  if (rows.length === 0) return <p className="text-sm text-muted-foreground">No requests.</p>;

  return (
    <ul className="mt-2 divide-y divide-border">
      {rows.map((row) => (
        <RequestRow
          key={row.QuotaRequestId}
          row={row}
          mode={mode}
          resellerId={resellerId}
          resellerSlug={resellerSlug}
        />
      ))}
    </ul>
  );
}

function RequestRow({
  row,
  mode,
  resellerId,
  resellerSlug,
}: {
  row: QuotaRequest;
  mode: Mode;
  resellerId: number;
  resellerSlug?: string;
}) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
      <div>
        <p className="font-medium">
          #{row.QuotaRequestId} · Cat {row.LicenseCategoryId} · Tier {row.LicenseTierId} · +
          {row.RequestedDelta}
        </p>
        <p className="text-xs text-muted-foreground">
          Status: {row.Status} · Submitted {row.SubmittedAt}
        </p>
      </div>
      <RequestActions row={row} mode={mode} resellerId={resellerId} resellerSlug={resellerSlug} />
    </li>
  );
}

function RequestActions({
  row,
  mode,
  resellerId,
  resellerSlug,
}: {
  row: QuotaRequest;
  mode: Mode;
  resellerId: number;
  resellerSlug?: string;
}) {
  const client = useQueryClient();
  const isPending = row.Status === QuotaRequestStatusType.Pending;
  const invalidate = () => {
    void client.invalidateQueries({ queryKey: quotaRequestListQueryOptions(resellerId).queryKey });
  };
  const slug = (resellerSlug ?? "").trim();
  const approve = useMutation({
    mutationFn: () => approveQuotaRequest(row.QuotaRequestId, slug, {}, crypto.randomUUID()),
    onSuccess: invalidate,
  });
  const deny = useMutation({
    mutationFn: () =>
      denyQuotaRequest(
        row.QuotaRequestId,
        slug,
        { Reason: "Denied by admin" },
        crypto.randomUUID(),
      ),
    onSuccess: invalidate,
  });
  const cancel = useMutation({
    mutationFn: () => cancelQuotaRequest(row.QuotaRequestId, crypto.randomUUID()),
    onSuccess: invalidate,
  });
  useLaraErrorToast(approve.error || deny.error || cancel.error, "Quota request action failed");
  const isFailed = !isPending;
  if (isFailed) return null;
  if (mode === "admin") {
    if (slug.length === 0) {
      return <span className="text-xs text-muted-foreground">Missing reseller slug</span>;
    }

    return (
      <AdminButtons
        onApprove={() => approve.mutate()}
        onDeny={() => deny.mutate()}
        disabled={approve.isPending || deny.isPending}
      />
    );
  }

  return <CancelButton onCancel={() => cancel.mutate()} disabled={cancel.isPending} />;
}

function AdminButtons({
  onApprove,
  onDeny,
  disabled,
}: {
  onApprove: () => void;
  onDeny: () => void;
  disabled: boolean;
}) {
  return (
    <div className="flex gap-2">
      <button
        type="button"
        onClick={onApprove}
        disabled={disabled}
        className="inline-flex h-8 items-center rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
      >
        Approve
      </button>
      <button
        type="button"
        onClick={onDeny}
        disabled={disabled}
        className="inline-flex h-8 items-center rounded-md border border-destructive px-3 text-xs font-medium text-destructive hover:bg-destructive/10 disabled:opacity-60"
      >
        Deny
      </button>
    </div>
  );
}

function CancelButton({ onCancel, disabled }: { onCancel: () => void; disabled: boolean }) {
  return (
    <button
      type="button"
      onClick={onCancel}
      disabled={disabled}
      className="inline-flex h-8 items-center rounded-md border border-input px-3 text-xs font-medium hover:bg-accent disabled:opacity-60"
    >
      Cancel
    </button>
  );
}
