// Plan 06 step 68. Reseller dashboard at /reseller/{resellerId}. Server
// truth only: quotas, pending-request count, and license count all come
// from the shard-bound reads in routes/web.php.

import { Head, Link } from "@inertiajs/react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import {
  ResellerQuotaTiles,
  ResellerQuotaTable,
  type ResellerQuotaRow,
} from "@/Components/reseller/ResellerPanels";

interface Props {
  resellerId: number;
  resellerName?: string | null;
  quotas?: ResellerQuotaRow[];
  pendingRequests?: number;
  licenseCount?: number;
}

export default function ResellerDashboard({
  resellerId,
  resellerName = null,
  quotas = [],
  pendingRequests = 0,
  licenseCount = 0,
}: Props) {
  return (
    <ConsoleLayout>
      <Head title="Reseller overview | Licensing Portal">
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title="Reseller overview"
        breadcrumbs={[{ label: "Reseller", to: `/reseller/${resellerId}` }, { label: resellerName ?? `Reseller ${resellerId}` }]}
        description="Snapshot of your allowances, pending quota requests, and quick actions."
      />

      <div className="mt-8">
        <ResellerQuotaTiles quotas={quotas} pendingRequests={pendingRequests} />
      </div>

      <section aria-labelledby="quota-heading" className="mt-8">
        <h2 id="quota-heading" className="mb-3 text-sm font-medium">
          Allowances by category and tier
        </h2>
        <ResellerQuotaTable quotas={quotas} />
      </section>

      <section aria-labelledby="actions-heading" className="mt-8 rounded-lg border border-border bg-card p-5">
        <h2 id="actions-heading" className="text-sm font-medium">
          Quick actions
        </h2>
        <ul className="mt-4 grid gap-3 sm:grid-cols-2">
          <li>
            <Link
              href={`/reseller/${resellerId}/licenses`}
              className="block rounded-md border border-border bg-background p-4 hover:bg-accent"
            >
              <span className="block text-sm font-semibold">View licenses</span>
              <span className="mt-1 block text-xs text-muted-foreground">
                {licenseCount.toLocaleString()} license{licenseCount === 1 ? "" : "s"} issued against your allowances.
              </span>
            </Link>
          </li>
          <li>
            <Link
              href={`/reseller/${resellerId}/quota-requests`}
              className="block rounded-md border border-border bg-background p-4 hover:bg-accent"
            >
              <span className="block text-sm font-semibold">Quota requests</span>
              <span className="mt-1 block text-xs text-muted-foreground">
                Ask for additional license capacity in a specific tier.
              </span>
            </Link>
          </li>
        </ul>
      </section>
    </ConsoleLayout>
  );
}
