import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";

import { PageHeader } from "../../components/shell/PageHeader";
import { StatCard } from "../../components/ui/stat-card";

import { meQueryOptions } from "../../lib/lara-me";
import {
  quotaRequestListQueryOptions,
  resellerQuotasQueryOptions,
  QuotaRequestStatusType,
} from "../../lib/lara-quota";
import { formatLaraApiError } from "../../lib/lara-api-error";

/**
 * Plan 09 Step 46: reseller dashboard hub at `/reseller/$resellerId`.
 * Before this route existed the bare reseller root 404'd, forcing
 * resellers to bookmark `/quota-requests` directly. This page renders
 * three KPI tiles (Allowances, Consumed, Pending Requests) sourced from
 * the same row-scoped endpoints the quota-requests page uses, plus quick
 * links to sub-surfaces. Identity gate mirrors the quota-requests route:
 * server-side row-scope is authoritative, this UI short-circuit prevents
 * mutation entry points from mounting for a cross-reseller URL.
 */
export const Route = createFileRoute("/_authenticated/reseller/$resellerId/")({
  ssr: false,
  parseParams: ({ resellerId }) => ({ resellerId: parseResellerId(resellerId) }),
  head: () => ({
    meta: [
      { title: "Reseller overview | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: ResellerHomePage,
});

function parseResellerId(raw: string): number {
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new Error(`Invalid resellerId: ${raw}`);
  }

  return parsed;
}

function ResellerHomePage() {
  const { resellerId } = Route.useParams();
  const { data } = useSuspenseQuery(meQueryOptions());
  const [me] = data;
  const isFailed = !me;
  if (isFailed) {
    throw new Error("Users.Me returned an empty envelope; AC-API-USR-001");
  }
  const isMismatch = me.RoleName === "Reseller" && me.ResellerId !== resellerId;

  return (
    <>
      <PageHeader
        title="Reseller overview"
        breadcrumbs={[
          { label: "Reseller", to: "/" },
          { label: `Reseller ${resellerId}`, identifier: true },
        ]}
        description={
          isMismatch
            ? undefined
            : "Snapshot of your allowances, pending quota requests, and quick actions."
        }
      />
      {isMismatch ? (
        <ForbiddenGate callerResellerId={me.ResellerId ?? null} urlResellerId={resellerId} />
      ) : (
        <ResellerHomeContent resellerId={resellerId} />
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
        targets reseller {props.urlResellerId}. Server-side row-scope blocks this request; this UI
        gate hides actions.
      </p>
    </section>
  );
}

function ResellerHomeContent({ resellerId }: { resellerId: number }) {
  return (
    <div className="grid gap-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <AllowancesTile resellerId={resellerId} />
        <ConsumedTile resellerId={resellerId} />
        <PendingRequestsTile resellerId={resellerId} />
      </div>
      <QuickActionsPanel resellerId={resellerId} />
    </div>
  );
}

function AllowancesTile({ resellerId }: { resellerId: number }) {
  const query = useSuspenseQuery(resellerQuotasQueryOptions(resellerId));
  if (query.isError) {
    return (
      <StatCard
        label="Total allowances"
        value="--"
        state="error"
        errorMessage={formatLaraApiError(query.error)}
      />
    );
  }
  const rows = query.data ?? [];
  const total = rows.reduce((sum, r) => sum + r.LicensesGranted, 0);

  return (
    <StatCard
      label="Total allowances"
      value={total.toLocaleString()}
      hint={`${rows.length} tier${rows.length === 1 ? "" : "s"} tracked`}
    />
  );
}

function ConsumedTile({ resellerId }: { resellerId: number }) {
  const query = useSuspenseQuery(resellerQuotasQueryOptions(resellerId));
  if (query.isError) {
    return (
      <StatCard
        label="Licenses consumed"
        value="--"
        state="error"
        errorMessage={formatLaraApiError(query.error)}
      />
    );
  }
  const rows = query.data ?? [];
  const consumed = rows.reduce((sum, r) => sum + r.LicensesConsumed, 0);
  const remaining = rows.reduce((sum, r) => sum + r.LicensesRemaining, 0);

  return (
    <StatCard
      label="Licenses consumed"
      value={consumed.toLocaleString()}
      hint={`${remaining.toLocaleString()} remaining across tiers`}
    />
  );
}

function PendingRequestsTile({ resellerId }: { resellerId: number }) {
  const query = useSuspenseQuery(
    quotaRequestListQueryOptions(resellerId, QuotaRequestStatusType.Pending),
  );
  if (query.isError) {
    return (
      <StatCard
        label="Pending quota requests"
        value="--"
        state="error"
        errorMessage={formatLaraApiError(query.error)}
      />
    );
  }
  const rows = query.data ?? [];

  return (
    <StatCard
      label="Pending quota requests"
      value={rows.length.toLocaleString()}
      hint={rows.length === 0 ? "Nothing awaiting approval" : "Awaiting admin decision"}
    />
  );
}

function QuickActionsPanel({ resellerId }: { resellerId: number }) {
  return (
    <section className="rounded-md border border-border bg-card p-5">
      <h2 className="text-lg font-semibold">Quick actions</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Jump into the most common reseller tasks.
      </p>
      <ul className="mt-4 grid gap-3 sm:grid-cols-2">
        <li>
          <Link
            to="/reseller/$resellerId/quota-requests"
            params={{ resellerId: resellerId }}
            className="block rounded-md border border-border bg-background p-4 hover:bg-accent"
          >
            <div className="text-sm font-semibold text-foreground">Submit quota request</div>
            <div className="mt-1 text-xs text-muted-foreground">
              Ask for additional license capacity in a specific tier.
            </div>
          </Link>
        </li>
        <li>
          <Link
            to="/reseller/$resellerId/quota-requests"
            params={{ resellerId: resellerId }}
            className="block rounded-md border border-border bg-background p-4 hover:bg-accent"
          >
            <div className="text-sm font-semibold text-foreground">Review pending requests</div>
            <div className="mt-1 text-xs text-muted-foreground">
              Track approvals, cancel outstanding requests, and see denial reasons.
            </div>
          </Link>
        </li>
      </ul>
    </section>
  );
}
