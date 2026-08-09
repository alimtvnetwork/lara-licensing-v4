import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";

import { PageHeader } from "../../components/shell/PageHeader";
import { AdminQuotaRequestsDataTable } from "../../components/admin/admin-quota-requests-data-table";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { adminQuotaRequestsQueryOptions } from "../../lib/lara-quota";

/**
 * Plan 09 step 43. Admin cross-shard quota-request inbox route.
 *
 * Root cause this closes: `Admin\QuotaRequestController::indexAll`
 * shipped in Plan 06 with a shard fanout, but the frontend had no
 * read surface. The only inbox operators could reach was the
 * per-reseller `/reseller/:id/quota-requests` route, which forced
 * admins to already know which shard a Pending request lived on.
 * Every list convergence step since v0.311.0 was blocked from this
 * route because the transport didn't exist.
 */
export const Route = createFileRoute("/_authenticated/admin/quota-requests")({
  ssr: false,
  loader: ({ context }) => context.queryClient.ensureQueryData(adminQuotaRequestsQueryOptions()),
  head: () => ({
    meta: [
      { title: "Quota requests | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: () => (
    <RoutePending
      title="Quota requests"
      description="Cross-shard inbox for reseller allowance changes."
    />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="Quota requests" error={error} reset={reset} />
  ),
  component: QuotaRequestsPage,
});

function QuotaRequestsPage() {
  const query = useSuspenseQuery(adminQuotaRequestsQueryOptions());
  const rows = query.data;

  return (
    <>
      <PageHeader
        title="Quota requests"
        description="Cross-shard inbox for reseller allowance changes. Newest first."
      />
      <AdminQuotaRequestsDataTable rows={rows} />
    </>
  );
}
