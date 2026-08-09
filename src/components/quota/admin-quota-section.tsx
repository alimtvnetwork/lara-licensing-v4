import { QuotaAdjustForm } from "./quota-adjust-form";
import { QuotaRequestList } from "./quota-request-list";
import { QuotaSummaryTable } from "./quota-summary-table";
import { QuotaRequestStatusType } from "../../lib/lara-quota";

/**
 * Admin quota panel (Step 46). Composes summary, adjust form, pending inbox.
 * See spec/21-app/41-reseller-quotas.md and spec/21-app/42-quota-requests.md.
 */
export function AdminQuotaSection({
  resellerId,
  resellerSlug,
}: {
  resellerId: number;
  resellerSlug: string;
}) {
  return (
    <section className="mt-10 rounded-md border border-border bg-card p-5">
      <header>
        <h2 className="text-lg font-semibold">Quotas</h2>
        <p className="text-sm text-muted-foreground">
          Reseller allowances, ledger-backed adjustments, and pending requests.
        </p>
      </header>
      <div className="mt-4">
        <QuotaSummaryTable resellerId={resellerId} />
      </div>
      <QuotaAdjustForm resellerId={resellerId} />
      <div className="mt-6">
        <h3 className="text-sm font-semibold">Pending requests</h3>
        <QuotaRequestList
          resellerId={resellerId}
          resellerSlug={resellerSlug}
          mode="admin"
          status={QuotaRequestStatusType.Pending}
        />
      </div>
    </section>
  );
}
