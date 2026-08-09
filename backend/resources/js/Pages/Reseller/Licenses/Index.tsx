// Plan 06 step 68. Reseller license roster at /reseller/{resellerId}/licenses.
// Rows come from Reseller\LicenseController::index (shard-bound), so the
// list is already row-scoped to the caller's tenant by ShardBindingMiddleware.

import { Head } from "@inertiajs/react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { ResellerLicenseTable, type ResellerLicenseRow } from "@/Components/reseller/ResellerPanels";

interface Props {
  resellerId: number;
  licenses?: ResellerLicenseRow[];
}

export default function ResellerLicenseIndex({ resellerId, licenses = [] }: Props) {
  return (
    <ConsoleLayout>
      <Head title="Licenses | Licensing Portal">
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title="Licenses"
        breadcrumbs={[
          { label: "Reseller", to: `/reseller/${resellerId}` },
          { label: "Licenses" },
        ]}
        description={`Licenses issued against your allowances. ${licenses.length} loaded.`}
      />
      <div className="mt-8">
        <ResellerLicenseTable licenses={licenses} resellerId={resellerId} />
      </div>
    </ConsoleLayout>
  );
}
