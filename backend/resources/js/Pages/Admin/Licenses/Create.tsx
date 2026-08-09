import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { useState } from "react";
import { router } from "@inertiajs/react";
import { Button } from "@/Components/ui/Button";
import { toast } from "sonner";

export default function LicenseCreate() {
  const [busy, setBusy] = useState(false);
  
  // Note: For now we'll implement a simplified form that hits the API.
  // In a full Inertia port, we might use useForm() from @inertiajs/react.
  
  return (
    <ConsoleLayout>
      <PageHeader 
        title="Issue license" 
        breadcrumbs={[
          { label: "Admin", to: "/admin" }, 
          { label: "Licenses", to: "/admin/licenses" },
          { label: "Issue license" }
        ]}
        description="Submits POST /Api/Admin/Licenses. Unknown request keys are rejected by the API."
      />
      
      <div className="mt-6 max-w-2xl rounded-md border border-border bg-card p-6">
        <p className="text-sm text-muted-foreground mb-6">
          Form porting in progress. Use the API or wait for the full form implementation.
        </p>
        <Button variant="outline" asChild>
           <a href="/admin/licenses">Back to list</a>
        </Button>
      </div>
    </ConsoleLayout>
  );
}
