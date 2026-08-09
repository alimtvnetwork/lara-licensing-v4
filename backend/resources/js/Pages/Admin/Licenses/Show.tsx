import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { LicenseFacts } from "@/Components/admin/LicenseFacts";
import { Timeline, type TimelineEntry, type TimelineTone } from "@/Components/ui/Timeline";
import { EmptyState } from "@/Components/ui/EmptyState";
import { SerialIssueForm } from "@/Components/admin/SerialIssueForm";
import { LicenseDetailActions } from "@/Components/admin/LicenseDetailActions";
import { LicenseFeaturePanel } from "@/Components/admin/LicenseFeaturePanel";
import type { FeatureLayers } from "@/lib/featureMap";

interface Props {
  license: any;
  ledger: any[];
  etag: string | null;
  featureLayers: FeatureLayers | null;
  resellerSlug: string;
}


function toneFor(action: string): TimelineTone {
  const lower = action.toLowerCase();
  if (lower.includes("revoke") || lower.includes("delete") || lower.includes("destroy")) return "danger";
  if (lower.includes("restore") || lower.includes("issue") || lower.includes("create")) return "success";
  if (lower.includes("update") || lower.includes("edit")) return "primary";
  if (lower.includes("warn") || lower.includes("expire")) return "warning";
  return "neutral";
}

function toEntries(rows: any[]): TimelineEntry[] {
  return rows.map((row) => ({
    id: row.AuditLogId,
    title: row.Action,
    description: `${row.ActorType} #${row.ActorId || 'system'} - Request ${row.RequestId}`,
    timestamp: row.CreatedAt,
    tone: toneFor(row.Action),
  }));
}

export default function LicenseShow({ license, ledger, etag, featureLayers = null, resellerSlug }: Props) {
  if (!license) {
    return (
      <ConsoleLayout>
        <PageHeader title="License not found" />
        <div className="mt-6">
          <EmptyState headline="License not found" body="The requested license key does not exist or you do not have permission to view it." />
        </div>
      </ConsoleLayout>
    );
  }

  return (
    <ConsoleLayout>
      <PageHeader 
        title={`License ${license.LicenseId}`}
        breadcrumbs={[
          { label: "Admin", to: "/admin" },
          { label: "Licenses", to: "/admin/licenses" },
          { label: `License ${license.LicenseId}`, identifier: true },
        ]}
      />
      
      <div className="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-8">
          <section>
            <h2 className="text-lg font-semibold mb-4">Current Facts</h2>
            <div className="rounded-md border border-border bg-card p-6">
              <LicenseFacts license={license} />
            </div>
          </section>

          <section>
            <div className="flex items-baseline justify-between gap-4 mb-4">
              <h2 className="text-lg font-semibold">Ledger</h2>
              <p className="text-xs text-muted-foreground">
                Persistent audit trail for this license (newest first).
              </p>
            </div>
            <div className="rounded-md border border-border bg-card p-6">
              <Timeline 
                entries={toEntries(ledger)} 
                emptyState={<EmptyState headline="No ledger entries" body="This license has no audit history." preset="plain" />}
              />
            </div>
          </section>
        </div>

        <div className="space-y-6">
          <section>
            <h2 className="text-lg font-semibold mb-4">Entitlements</h2>
            <div className="rounded-md border border-border bg-card p-6">
              <LicenseFeaturePanel layers={featureLayers} />
            </div>
          </section>

          <section>
            <h2 className="text-lg font-semibold mb-4">Actions</h2>
            <div className="space-y-6 rounded-md border border-border bg-card p-6">
              <LicenseDetailActions
                license={license}
                resellerSlug={resellerSlug}
                etag={etag}
              />
              <div className="border-t border-border pt-6">
                <SerialIssueForm licenseId={license.LicenseId} />
                <p className="mt-4 text-xs text-muted-foreground">
                  Issuing a serial creates a unique activation key for this license.
                </p>
              </div>
            </div>
          </section>

        </div>
      </div>
    </ConsoleLayout>
  );
}
