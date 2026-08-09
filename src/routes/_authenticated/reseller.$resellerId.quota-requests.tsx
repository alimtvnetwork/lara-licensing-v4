import { useSuspenseQuery, useQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";

import { QuotaRequestSubmitForm } from "../../components/quota/quota-request-submit-form";
import { QuotaSummaryTable } from "../../components/quota/quota-summary-table";
import { ResellerQuotaRequestsDataTable } from "../../components/quota/reseller-quota-requests-data-table";
import { PageHeader } from "../../components/shell/PageHeader";
import { SkeletonList } from "../../components/ui/skeleton";
import { formatLaraApiErrorOptional } from "../../lib/lara-api-error";
import { meQueryOptions } from "../../lib/lara-me";
import { quotaRequestListQueryOptions } from "../../lib/lara-quota";

/**
 * Reseller portal (Step 47 + Plan 05 identity wiring):
 * quota status + self-service QuotaRequests submission. Row-scope per
 * spec/21-app/40-permissions.md filters server-side; this route ALSO reads
 * spec/21-app/11-api-contracts/06-user-contracts.md v1.0.0 GET /Users/Me
 * and short-circuits with a 403 gate when a Reseller's ResellerId does not
 * match the URL segment (AC-API-USR-004). Server-side rejection remains the
 * authoritative barrier, this is a UX guardrail so mutation forms never mount.
 */
export const Route = createFileRoute("/_authenticated/reseller/$resellerId/quota-requests")({
  ssr: false,
  parseParams: ({ resellerId }) => ({ resellerId: parseResellerId(resellerId) }),
  head: () => ({
    meta: [
      { title: "Quota requests | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: ResellerQuotaRequestsPage,
});

function parseResellerId(raw: string): number {
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new Error(`Invalid resellerId: ${raw}`);
  }

  return parsed;
}

function crumbsFor(resellerId: number) {
  return [
    { label: "Reseller", to: "/" },
    { label: `Reseller ${resellerId}`, identifier: true },
    { label: "Quota requests" },
  ];
}

function ResellerQuotaRequestsPage() {
  const { resellerId } = Route.useParams();
  const { data } = useSuspenseQuery(meQueryOptions());
  const [me] = data;
  const isFailed = !me;
  if (isFailed) {
    throw new Error(
      "Users.Me returned an empty envelope; server invariant break per AC-API-USR-001",
    );
  }
  const isResellerMismatch = me.RoleName === "Reseller" && me.ResellerId !== resellerId;

  return (
    <>
      <PageHeader
        title="Quota requests"
        breadcrumbs={crumbsFor(resellerId)}
        description={
          isResellerMismatch
            ? undefined
            : "View current allowances, submit new quota requests, and cancel pending ones."
        }
      />
      {isResellerMismatch ? (
        <ForbiddenGate callerResellerId={me.ResellerId ?? null} urlResellerId={resellerId} />
      ) : (
        <AllowedContent resellerId={resellerId} />
      )}
    </>
  );
}

function ForbiddenGate(props: { callerResellerId: number | null; urlResellerId: number }) {
  return (
    <section
      role="alert"
      className="rounded-md border border-destructive/50 bg-destructive/5 p-5 text-sm"
    >
      <h2 className="text-base font-semibold text-destructive">Access denied</h2>
      <p className="mt-2 text-muted-foreground">
        Your account is scoped to reseller {props.callerResellerId ?? "(none)"}, but this page
        targets reseller {props.urlResellerId}. Row-scope enforcement per
        spec/21-app/40-permissions.md blocks this request server-side; this UI gate prevents the
        mutation form from mounting.
      </p>
    </section>
  );
}

function AllowedContent(props: { resellerId: number }) {
  return (
    <>
      <section className="rounded-md border border-border bg-card p-5">
        <h2 className="text-lg font-semibold">Current allowances</h2>
        <div className="mt-3">
          <QuotaSummaryTable resellerId={props.resellerId} />
        </div>
      </section>
      <section className="rounded-md border border-border bg-card p-5">
        <h2 className="text-lg font-semibold">Submit request</h2>
        <QuotaRequestSubmitForm resellerId={props.resellerId} />
      </section>
      <section className="rounded-md border border-border bg-card p-5">
        <h2 className="text-lg font-semibold">Your requests</h2>
        <div className="mt-3">
          <ResellerRequestsSection resellerId={props.resellerId} />
        </div>
      </section>
    </>
  );
}

function ResellerRequestsSection({ resellerId }: { resellerId: number }) {
  const query = useQuery(quotaRequestListQueryOptions(resellerId));
  if (query.isPending) {
    return <SkeletonList rows={4} />;
  }
  const err = formatLaraApiErrorOptional(query.error);
  if (err !== undefined) {
    return (
      <div
        role="alert"
        className="rounded-md border border-destructive/50 bg-destructive/5 p-4 text-sm"
      >
        <p className="font-medium text-destructive">Could not load quota requests</p>
        <p className="mt-1 text-muted-foreground">{err}</p>
        <button
          type="button"
          onClick={() => void query.refetch()}
          className="focus-ring mt-3 inline-flex h-8 items-center rounded-md border border-input px-3 text-xs font-medium surface-hover"
        >
          Retry
        </button>
      </div>
    );
  }

  return <ResellerQuotaRequestsDataTable rows={query.data ?? []} />;
}
