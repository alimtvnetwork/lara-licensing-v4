import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { EmptyState } from "@/Components/ui/EmptyState";
import { Button } from "@/Components/ui/Button";
import { Link } from "@inertiajs/react";
import { Plus } from "lucide-react";

export default function LicenseIndex() {
  return (
    <ConsoleLayout>
      <PageHeader 
        title="Licenses" 
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Licenses" }]}
        description="View and manage all licenses across all shards."
      />
      
      <div className="mt-6">
        <EmptyState
          preset="box"
          headline="No license index yet"
          body="The backend index for all licenses is being implemented. You can still issue new licenses or view specific ones if you have the key."
          action={
            <Button asChild>
              <Link href="/admin/licenses/new">
                <Plus className="mr-2 size-4" />
                Issue license
              </Link>
            </Button>
          }
        />
      </div>
    </ConsoleLayout>
  );
}
