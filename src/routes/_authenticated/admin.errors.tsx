import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";

import { PageHeader } from "../../components/shell/PageHeader";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { EmptyState } from "../../components/ui/empty-state";
import { adminErrorsQueryOptions } from "../../lib/lara-errors";
import type { AdminErrorRow } from "@/generated/api/schema";

/**
 * Plan 18 Step 106. Admin Errors viewer.
 */
export const Route = createFileRoute("/_authenticated/admin/errors")({
  ssr: false,
  loader: ({ context }) => context.queryClient.ensureQueryData(adminErrorsQueryOptions()),
  head: () => ({
    meta: [{ title: "Errors | Licensing Portal" }, { name: "robots", content: "noindex,nofollow" }],
  }),
  pendingComponent: () => (
    <RoutePending title="System Errors" description="Viewing the most recent system errors." />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="System Errors" error={error} reset={reset} />
  ),
  component: ErrorsPage,
});

function ErrorsPage() {
  const query = useSuspenseQuery(adminErrorsQueryOptions());
  const rows = query.data;

  return (
    <>
      <PageHeader
        title="System Errors"
        description={`Displaying recent backend exceptions from lara-audit-errors log. ${rows.length} records found.`}
      />
      {rows.length === 0 ? (
        <EmptyState
          preset="box"
          headline="No system errors recorded"
          body="No recent backend errors were found in the audit log."
        />
      ) : (
        <ErrorsTable rows={rows} />
      )}
    </>
  );
}

function ErrorsTable({ rows }: { rows: AdminErrorRow[] }) {
  return (
    <div className="mt-6 overflow-x-auto rounded-md border border-border">
      <table className="min-w-full text-sm">
        <thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground">
          <tr>
            <th className="px-3 py-2 font-medium">When</th>
            <th className="px-3 py-2 font-medium">Status</th>
            <th className="px-3 py-2 font-medium">Category</th>
            <th className="px-3 py-2 font-medium">Code</th>
            <th className="px-3 py-2 font-medium">RequestId</th>
            <th className="px-3 py-2 font-medium">ErrorId</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {rows.map((row, i) => (
            <tr key={`${row.ErrorId ?? i}`} className="align-top">
              <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-muted-foreground">
                {row.RequestedAt}
              </td>
              <td className="whitespace-nowrap px-3 py-2 font-mono text-xs">{row.HttpStatus}</td>
              <td className="whitespace-nowrap px-3 py-2 font-medium">{row.Category}</td>
              <td className="whitespace-nowrap px-3 py-2 text-xs font-mono">{row.ErrorCode}</td>
              <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-muted-foreground">
                {row.RequestId}
              </td>
              <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-muted-foreground">
                {row.ErrorId}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
