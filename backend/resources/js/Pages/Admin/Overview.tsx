import { PageHeader } from "@/Components/shell/PageHeader";
import { StatCard } from "@/Components/ui/StatCard";
import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { Head } from "@inertiajs/react";

interface OverviewProps {
  metrics: {
    ResellersActive: number;
    SessionsActive: number;
    LicensesTotal: number;
    QuotaRequestsPending: number;
    GeneratedAt: string;
  };
  warnings: Array<{ ResellerSlug: string; Error: string }>;
}

export default function Overview({ metrics, warnings }: OverviewProps) {
  const timeStr = metrics.GeneratedAt 
    ? new Date(metrics.GeneratedAt).toLocaleTimeString() 
    : "";

  return (
    <ConsoleLayout>
      <Head title="Overview | Admin Console" />
      
      <section className="dot-pattern surface-elevated relative overflow-hidden p-6 rounded-2xl mb-6">
        <PageHeader 
          title="Overview" 
          description="Licensing Portal administrative operations." 
        />
        {timeStr && (
          <p className="mt-1 text-xs text-muted-foreground font-mono">
            Updated {timeStr}
          </p>
        )}
      </section>

      {warnings.length > 0 && (
        <div className="bg-amber-50 border border-amber-200 p-4 rounded-lg mb-6 flex gap-3 text-sm text-amber-900">
          <div className="flex-1">
            <p className="font-semibold">{warnings.length} shard(s) did not respond; totals may be incomplete.</p>
            <ul className="mt-1 font-mono text-xs">
              {warnings.map(w => <li key={w.ResellerSlug}>{w.ResellerSlug}: {w.Error}</li>)}
            </ul>
          </div>
        </div>
      )}

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard 
          label="Active resellers" 
          value={metrics.ResellersActive} 
          hint="Root scope." 
        />
        <StatCard 
          label="Active sessions" 
          value={metrics.SessionsActive} 
          hint="Unexpired auth sessions." 
        />
        <StatCard 
          label="Licenses issued" 
          value={metrics.LicensesTotal} 
          hint="Sum across shards." 
        />
        <StatCard 
          label="Quota requests pending" 
          value={metrics.QuotaRequestsPending} 
          hint="Awaiting decision." 
        />
      </section>
      
      <div className="mt-6 p-6 border rounded-xl bg-card">
        <h2 className="text-lg font-semibold mb-2">Recent activity</h2>
        <p className="text-sm text-muted-foreground italic">Activity feed port pending Step 67.</p>
      </div>
    </ConsoleLayout>
  );
}
