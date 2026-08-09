// Plan 06 step 69. Admin quota-request inbox at /admin/quota-requests.
//
// Two server-truth sources behind one page (see routes/web.php):
//  - no ResellerSlug filter: Admin\QuotaRequestController::indexAll fanout over
//    every active shard; each row already carries its own ResellerSlug, which
//    Approve/Deny need for shard binding. Per-shard failures arrive in
//    `warnings` and are shown, never swallowed.
//  - ResellerSlug present: Admin\QuotaRequestController::index single shard;
//    the slug is threaded into the table as the row-level fallback.

import { Head, router } from "@inertiajs/react";
import * as React from "react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { Button } from "@/Components/ui/Button";
import { QuotaRequestTable, type QuotaRequestRow } from "@/Components/quota/QuotaRequestTable";
import { QUOTA_REQUEST_STATUS_LABELS } from "@/lib/closed-sets";

const ADMIN_STATUS_FILTERS = [1, 2, 3] as const;

interface Props {
  requests?: QuotaRequestRow[];
  filters?: { ResellerSlug: string; Status: string };
  warnings?: Array<{ ResellerSlug: string; Error: string }>;
  shardCount?: number;
}

export default function AdminQuotaRequestIndex({
  requests = [],
  filters = { ResellerSlug: "", Status: "" },
  warnings = [],
  shardCount = 0,
}: Props) {
  const [slug, setSlug] = React.useState(filters.ResellerSlug);
  const [status, setStatus] = React.useState(filters.Status);

  const apply = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    router.get("/admin/quota-requests", { ResellerSlug: slug.trim(), Status: status }, {
      preserveState: true,
      replace: true,
    });
  };

  return (
    <ConsoleLayout>
      <Head title="Quota requests | Licensing Console">
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title="Quota requests"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Quota requests" }]}
        description={
          filters.ResellerSlug === ""
            ? `Review and decide reseller allowance requests across ${shardCount} shard(s). ${requests.length} loaded.`
            : `Requests for ${filters.ResellerSlug}. ${requests.length} loaded.`
        }
      />

      <form onSubmit={apply} className="mt-6 flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1 text-sm">
          <span className="font-medium">Reseller slug</span>
          <input
            value={slug}
            onChange={(event) => setSlug(event.target.value)}
            placeholder="All shards"
            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
          />
        </label>
        <label className="flex flex-col gap-1 text-sm">
          <span className="font-medium">Status</span>
          <select
            value={status}
            onChange={(event) => setStatus(event.target.value)}
            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
          >
            <option value="">Any</option>
{/* Admin\QuotaRequestController::parseStatusFilter accepts only
                1/2/3, so Cancelled is deliberately absent rather than
                offered and silently ignored. */}
            {ADMIN_STATUS_FILTERS.map((ordinal) => (
              <option key={ordinal} value={ordinal}>
                {QUOTA_REQUEST_STATUS_LABELS[ordinal]}
              </option>
            ))}
          </select>
        </label>
        <Button type="submit" variant="outline" size="sm">
          Apply filters
        </Button>
      </form>

      {warnings.length > 0 && (
        <ul role="alert" className="mt-4 space-y-1 text-sm text-amber-600">
          {warnings.map((warning) => (
            <li key={warning.ResellerSlug}>
              {warning.ResellerSlug}: {warning.Error} (rows omitted from this list)
            </li>
          ))}
        </ul>
      )}

      <div className="mt-8">
        <QuotaRequestTable
          rows={requests}
          mode="admin"
          resellerSlug={filters.ResellerSlug === "" ? null : filters.ResellerSlug}
        />
      </div>
    </ConsoleLayout>
  );
}
