// Plan 06 step 69. Reseller quota requests at /reseller/{resellerId}/quota-requests.
// Rows come from Reseller\QuotaRequestController::index, shard-bound by
// ShardBindingMiddleware, so the list is row-scoped to the caller's tenant.

import { Head } from "@inertiajs/react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { QuotaRequestTable, type QuotaRequestRow } from "@/Components/quota/QuotaRequestTable";
import { QuotaRequestSubmitForm } from "@/Components/quota/QuotaRequestSubmitForm";
import type { QuotaPreflightRow } from "@/lib/quotaPreflight";

interface Props {
  resellerId: number;
  requests?: QuotaRequestRow[];
  quotas?: QuotaPreflightRow[];
}

export default function ResellerQuotaRequestIndex({ resellerId, requests = [], quotas = [] }: Props) {
  const pending = requests.filter((row) => row.Status === 1).length;
  return (
    <ConsoleLayout>
      <Head title="Quota requests | Licensing Portal">
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title="Quota requests"
        breadcrumbs={[
          { label: "Reseller", to: `/reseller/${resellerId}` },
          { label: "Quota requests" },
        ]}
        description={`Ask for more license allowance and track review outcomes. ${pending} pending of ${requests.length} total.`}
      />
      <div className="mt-8 space-y-8">
        <QuotaRequestSubmitForm quotas={quotas} />
        <QuotaRequestTable rows={requests} mode="reseller" />
      </div>
    </ConsoleLayout>
  );
}
