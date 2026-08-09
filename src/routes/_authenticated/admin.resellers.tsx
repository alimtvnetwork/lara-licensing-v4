import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";

import { ResellerDataTable } from "../../components/admin/reseller-data-table";
import { PageActions } from "../../components/shell/AppShell";
import { PageHeader } from "../../components/shell/PageHeader";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { resellersQueryOptions } from "../../lib/lara-reseller";

export const Route = createFileRoute("/_authenticated/admin/resellers")({
  ssr: false,
  loader: ({ context }) => context.queryClient.ensureQueryData(resellersQueryOptions),
  head: () => ({
    meta: [
      { title: "Resellers | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: () => (
    <RoutePending
      title="Resellers"
      description="Manage reseller organizations and review account status."
    />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="Resellers" error={error} reset={reset} />
  ),
  notFoundComponent: () => <PageHeader title="Resellers not found" />,
  component: ResellersPage,
});

const NEW_RESELLER_LINK =
  "focus-ring inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90";

function ResellersPage() {
  const { data } = useSuspenseQuery(resellersQueryOptions);

  return (
    <>
      <PageHeader
        title="Resellers"
        description={`Manage reseller organizations and review account status. ${data.length} loaded.`}
      />
      <PageActions>
        <Link to="/admin/resellers/new" className={NEW_RESELLER_LINK}>
          New reseller
        </Link>
      </PageActions>
      <ResellerDataTable resellers={data} />
    </>
  );
}
