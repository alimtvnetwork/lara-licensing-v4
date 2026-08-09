import { useQueryClient } from "@tanstack/react-query";
import { createFileRoute, useRouter } from "@tanstack/react-router";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { useMemo, useState } from "react";

import { PageHeader } from "../../components/shell/PageHeader";
import { apiQueryKey, useApi, useApiMutation } from "../../hooks/use-api";
import { appToast } from "../../hooks/use-app-toast";
import { ApiErrorCodeType, LaraApiError, formatLaraApiError } from "../../lib/lara-api-error";
import type { Quota } from "../../generated/api/schema";

// preview-only-shape: admin.quotas.list/update use the aspirational Ulid+{Allocated,Used,Restored}
// shape from src/generated/api/schema.d.ts, which does NOT match the real Laravel response
// documented by resellerQuotaSchema in src/lib/lara-quota.ts ({LicensesGranted, LicensesConsumed,
// LicensesRemaining, LicenseCategoryId, LicenseTierId}). This route therefore runs correctly ONLY
// against the preview transport; production wiring is blocked until Plan 16 step 66 regenerates
// the schema or rewires the route to requestLaraApi + resellerQuotaSchema. Enforced by
// tests/api-client-boundary.test.ts (PREVIEW_ONLY_SHAPE_ROUTES).

/**
 * Plan 16 Step 63. Admin > Quotas surface.
 *
 * Reads via `admin.quotas.list` and writes via `admin.quotas.update` with
 * `If-Match: <Version>` (INV-BR-quota, spec/26-backup-restore + preview
 * fixtures/quotas.ts). Distinct states:
 *   - 412 conflict  -> inline banner + list refetch (no toast for 412).
 *   - 422 floor     -> validation banner ("Allocated below net-consumed").
 *   - success       -> toast + cache invalidation.
 *
 * Function bodies obey the 15-line cap per coding guidelines.
 */

export const Route = createFileRoute("/_authenticated/admin/quotas")({
  ssr: false,
  head: () => ({
    meta: [{ title: "Quotas | Licensing Portal" }, { name: "robots", content: "noindex,nofollow" }],
  }),
  component: AdminQuotasPage,
});

const QUOTAS_QUERY_KEY = apiQueryKey("admin.quotas.list", {});

function isConflictError(err: unknown): boolean {
  if (!(err instanceof LaraApiError)) return false;

  return err.errorCode === ApiErrorCodeType.PreconditionFailed;
}

function isFloorError(err: unknown): boolean {
  if (!(err instanceof LaraApiError)) return false;

  return err.errorCode === ApiErrorCodeType.ValidationFailed;
}

function AdminQuotasPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const query = useApi("admin.quotas.list", {}, { staleTime: 15_000 });
  const onRefetch = () => {
    void qc.invalidateQueries({ queryKey: QUOTAS_QUERY_KEY });
    void router.invalidate();
  };

  return (
    <>
      <PageHeader
        title="Quotas"
        description="Per-reseller feature allocations. Edits are If-Match guarded and refuse to drop below net-consumed quota."
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Quotas" }]}
      />
      <QuotasBody state={query} onRefetch={onRefetch} />
    </>
  );
}

interface BodyProps {
  state: ReturnType<typeof useApi<"admin.quotas.list">>;
  onRefetch: () => void;
}

function QuotasBody({ state, onRefetch }: BodyProps) {
  if (state.isPending) return <LoadingPanel />;
  if (state.isError || !state.data) return <ErrorPanel error={state.error} onRetry={onRefetch} />;

  return <QuotasTable items={state.data.Items} onRefetch={onRefetch} />;
}

function LoadingPanel() {
  return (
    <div
      className="mt-6 h-56 animate-pulse rounded-md border border-border bg-muted"
      aria-label="Loading quotas"
    />
  );
}

function ErrorPanel({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  return (
    <div role="alert" className="mt-6 border-y border-destructive py-6">
      <p className="font-medium">Quotas could not be loaded</p>
      <p className="mt-1 text-sm text-muted-foreground">{formatLaraApiError(error)}</p>
      <button
        type="button"
        onClick={onRetry}
        className="focus-ring mt-4 inline-flex h-9 items-center gap-2 rounded-md border border-input px-3 text-sm font-medium surface-hover"
      >
        <RefreshCw aria-hidden="true" className="size-4" /> Retry
      </button>
    </div>
  );
}

function QuotasTable({ items, onRefetch }: { items: Quota[]; onRefetch: () => void }) {
  if (items.length === 0) {
    return <p className="mt-6 text-sm text-muted-foreground">No quota rows.</p>;
  }

  return (
    <section aria-labelledby="quotas-table" className="mt-6 space-y-3">
      <h2 id="quotas-table" className="sr-only">
        Quota rows
      </h2>
      {items.map((q) => (
        <QuotaRow key={q.Id} quota={q} onRefetch={onRefetch} />
      ))}
    </section>
  );
}

interface RowProps {
  quota: Quota;
  onRefetch: () => void;
}

function QuotaRow({ quota, onRefetch }: RowProps) {
  const [allocated, setAllocated] = useState<number>(quota.Allocated);
  const [conflict, setConflict] = useState(false);
  const [floorErr, setFloorErr] = useState<string | null>(null);
  const floor = useMemo(() => Math.max(0, quota.Used - quota.Restored), [quota]);
  const dirty = allocated !== quota.Allocated;
  const mutation = useApiMutation("admin.quotas.update", {
    onSuccess: (next) => onUpdateSuccess(next, setAllocated, setConflict, setFloorErr, onRefetch),
    onError: (err) => onUpdateError(err, setConflict, setFloorErr),
  });

  return (
    <RowLayout
      quota={quota}
      floor={floor}
      allocated={allocated}
      dirty={dirty}
      conflict={conflict}
      floorErr={floorErr}
      pending={mutation.isPending}
      onAllocatedChange={setAllocated}
      onSubmit={() =>
        mutation.mutate({
          params: { Id: quota.Id, IfMatch: String(quota.Version), Allocated: allocated },
        })
      }
      onRefresh={onRefetch}
    />
  );
}

function onUpdateSuccess(
  next: Quota,
  setAllocated: (v: number) => void,
  setConflict: (v: boolean) => void,
  setFloorErr: (v: string | null) => void,
  onRefetch: () => void,
) {
  console.info("admin.quotas: updated", {
    QuotaId: next.Id,
    ToAllocated: next.Allocated,
    ToVersion: next.Version,
  });
  appToast.success("Quota updated", { description: `Allocated is now ${next.Allocated}.` });
  setAllocated(next.Allocated);
  setConflict(false);
  setFloorErr(null);
  onRefetch();
}

function onUpdateError(
  err: unknown,
  setConflict: (v: boolean) => void,
  setFloorErr: (v: string | null) => void,
) {
  console.warn("admin.quotas: update failed", { error: err });
  if (isConflictError(err)) {
    setConflict(true);

    return;
  }
  if (isFloorError(err)) {
    setFloorErr(
      err instanceof LaraApiError ? err.message : "Allocated is below net-consumed quota.",
    );

    return;
  }
  appToast.fromApiError(err, "Quota update failed");
}

interface RowLayoutProps {
  quota: Quota;
  floor: number;
  allocated: number;
  dirty: boolean;
  conflict: boolean;
  floorErr: string | null;
  pending: boolean;
  onAllocatedChange: (v: number) => void;
  onSubmit: () => void;
  onRefresh: () => void;
}

function RowLayout(p: RowLayoutProps) {
  return (
    <div className="surface-elevated rounded-md p-4">
      <RowHeader quota={p.quota} floor={p.floor} />
      {p.conflict ? <ConflictNotice onRefresh={p.onRefresh} /> : null}
      {p.floorErr ? <FloorNotice message={p.floorErr} /> : null}
      <RowEditor
        allocated={p.allocated}
        floor={p.floor}
        dirty={p.dirty}
        pending={p.pending}
        onChange={p.onAllocatedChange}
        onSubmit={p.onSubmit}
      />
    </div>
  );
}

function RowHeader({ quota, floor }: { quota: Quota; floor: number }) {
  return (
    <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2 text-sm">
      <div>
        <span className="font-medium">{quota.ResellerName}</span>
        <span className="ml-2 text-muted-foreground" style={{ fontFamily: "var(--font-mono)" }}>
          {quota.FeatureCode}
        </span>
      </div>
      <div className="text-muted-foreground">
        Used {quota.Used} - Restored {quota.Restored} = floor {floor} - Version {quota.Version}
      </div>
    </div>
  );
}

interface EditorProps {
  allocated: number;
  floor: number;
  dirty: boolean;
  pending: boolean;
  onChange: (v: number) => void;
  onSubmit: () => void;
}

function RowEditor(p: EditorProps) {
  const invalid = p.allocated < p.floor;
  const disabled = p.pending || !p.dirty || invalid;

  return (
    <div className="flex flex-wrap items-center gap-3 text-sm">
      <label className="flex items-center gap-2">
        <span className="text-muted-foreground">Allocated</span>
        <input
          type="number"
          min={p.floor}
          value={p.allocated}
          onChange={(e) => p.onChange(Number.parseInt(e.target.value, 10) || 0)}
          className="focus-ring h-9 w-28 rounded-md border border-input bg-background px-2"
        />
      </label>
      <button
        type="button"
        onClick={p.onSubmit}
        disabled={disabled}
        className="focus-ring inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 font-medium text-primary-foreground disabled:opacity-50"
      >
        {p.pending ? "Applying..." : "Save"}
      </button>
      {invalid ? <span className="text-destructive">Below floor ({p.floor})</span> : null}
    </div>
  );
}

function ConflictNotice({ onRefresh }: { onRefresh: () => void }) {
  return (
    <div
      role="alert"
      className="mb-3 flex items-start gap-2 rounded-md border border-destructive bg-destructive/5 p-3 text-sm"
    >
      <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 text-destructive" />
      <div className="flex-1">
        <p className="font-medium">Quota changed since you loaded this page</p>
        <p className="text-muted-foreground">
          Refresh to load the latest If-Match token, then reapply.
        </p>
      </div>
      <button
        type="button"
        onClick={onRefresh}
        className="focus-ring inline-flex h-8 items-center gap-2 rounded-md border border-input px-2 surface-hover"
      >
        <RefreshCw aria-hidden="true" className="size-4" /> Refresh
      </button>
    </div>
  );
}

function FloorNotice({ message }: { message: string }) {
  return (
    <div
      role="alert"
      className="mb-3 rounded-md border border-destructive bg-destructive/5 p-3 text-sm"
    >
      <p className="font-medium">Allocated below net-consumed quota</p>
      <p className="text-muted-foreground">{message}</p>
    </div>
  );
}
