import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";

import { PrefixManager } from "../../components/admin/prefix-manager";
import { ResellerActivity } from "../../components/admin/reseller-activity";
import { ResellerEditForm } from "../../components/admin/reseller-edit-form";
import { AdminQuotaSection } from "../../components/quota/admin-quota-section";
import { PageHeader } from "../../components/shell/PageHeader";
import { RouteErrorState } from "../../components/shell/RouteFallbacks";
import { resellerQueryOptions } from "../../lib/lara-reseller";

export const Route = createFileRoute("/_authenticated/admin/resellers/$resellerId")({
  ssr: false,
  parseParams: ({ resellerId }) => ({ resellerId: parseResellerId(resellerId) }),
  loader: ({ context, params }) =>
    context.queryClient.ensureQueryData(resellerQueryOptions(params.resellerId)),
  head: () => ({
    meta: [
      { title: "Reseller detail | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: DetailPending,
  errorComponent: DetailError,
  notFoundComponent: DetailNotFound,
  component: ResellerDetailPage,
});

function parseResellerId(raw: string): number {
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new Error(`Invalid resellerId: ${raw}`);
  }

  return parsed;
}

function crumbsFor(name: string) {
  return [
    { label: "Admin", to: "/admin" },
    { label: "Resellers", to: "/admin/resellers" },
    { label: name },
  ];
}

function ResellerDetailPage() {
  const { resellerId } = Route.useParams();
  const { data } = useSuspenseQuery(resellerQueryOptions(resellerId));
  const [reseller] = data;
  if (reseller === undefined) return <DetailNotFound />;

  return (
    <>
      <PageHeader
        title={reseller.ResellerName}
        breadcrumbs={crumbsFor(reseller.ResellerName)}
        description={`Reseller ID ${reseller.ResellerId}. Update details or remove the account.`}
      />
      <ResellerEditForm reseller={reseller} />
      <PrefixManager resellerId={reseller.ResellerId} />
      <AdminQuotaSection resellerId={reseller.ResellerId} resellerSlug={reseller.ResellerSlug} />
      <ResellerActivity resellerId={reseller.ResellerId} />
    </>
  );
}

function DetailPending() {
  return (
    <>
      <PageHeader title="Reseller" />
      <div
        className="h-64 animate-pulse rounded-md border border-border bg-muted"
        aria-label="Loading reseller"
      />
    </>
  );
}
function DetailError({ error, reset }: { error: Error; reset: () => void }) {
  return (
    <RouteErrorState title="Reseller" headline="Reseller unavailable" error={error} reset={reset} />
  );
}

function DetailNotFound() {
  return (
    <>
      <PageHeader title="Reseller not found" />
      <Link to="/admin/resellers" className="inline-block text-sm underline">
        Back to resellers
      </Link>
    </>
  );
}
