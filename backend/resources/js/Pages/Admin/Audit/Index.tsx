// Plan 06 step 67. Admin audit log Inertia page. Rows come from
// AuditController::index (Root AuditLogs, newest first); filtering is
// server-side through the Action / TargetType / ActorId query params.

import { Head } from "@inertiajs/react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { AuditTable, type AuditFilters, type AuditRow } from "@/Components/admin/AuditTable";

interface Props {
  rows?: AuditRow[];
  filters?: AuditFilters;
  limit?: number;
}

export default function AuditIndex({ rows = [], filters = {}, limit = 100 }: Props) {
  return (
    <ConsoleLayout>
      <Head title="Audit log | Licensing Portal">
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title="Audit log"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Audit" }]}
        description={`Persistent mutation trail from Root AuditLogs. ${rows.length} of max ${limit} rows loaded (newest first).`}
      />
      <div className="mt-8">
        <AuditTable rows={rows} filters={filters} filterUrl="/admin/audit" />
      </div>
    </ConsoleLayout>
  );
}
