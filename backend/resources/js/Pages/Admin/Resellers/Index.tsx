// Plan 06 step 66. Admin resellers list Inertia page.

import { Head } from "@inertiajs/react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { ResellerTable, type ResellerRow } from "@/Components/admin/ResellerTable";

interface Props {
  resellers?: ResellerRow[];
}

export default function ResellerIndex({ resellers = [] }: Props) {
  return (
    <ConsoleLayout>
      <Head title="Resellers | Licensing Portal">
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title="Resellers"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Resellers" }]}
        description={`Manage reseller organizations and review account status. ${resellers.length} loaded.`}
      />
      <div className="mt-8">
        <ResellerTable resellers={resellers} />
      </div>
    </ConsoleLayout>
  );
}
