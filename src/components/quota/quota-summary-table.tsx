import { useQuery } from "@tanstack/react-query";

import { formatLaraApiErrorOptional } from "../../lib/lara-api-error";
import { resellerQuotasQueryOptions, type ResellerQuota } from "../../lib/lara-quota";

/**
 * Read-only quota grid per spec/21-app/41-reseller-quotas.md.
 * Shared by Admin (Step 46) and Reseller (Step 47) UIs.
 */
export function QuotaSummaryTable({ resellerId }: { resellerId: number }) {
  const query = useQuery(resellerQuotasQueryOptions(resellerId));
  const err = formatLaraApiErrorOptional(query.error);
  if (query.isPending) return <p className="text-sm text-muted-foreground">Loading quotas...</p>;
  if (err)
    return (
      <p role="alert" className="text-sm text-destructive">
        {err}
      </p>
    );
  const rows = query.data ?? [];
  if (rows.length === 0)
    return <p className="text-sm text-muted-foreground">No quotas granted yet.</p>;

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-border text-xs uppercase text-muted-foreground">
          <tr>
            <th className="py-2 pr-4">CategoryId</th>
            <th className="py-2 pr-4">TierId</th>
            <th className="py-2 pr-4">Granted</th>
            <th className="py-2 pr-4">Consumed</th>
            <th className="py-2 pr-4">Remaining</th>
          </tr>
        </thead>
        <tbody>{rows.map(renderRow)}</tbody>
      </table>
    </div>
  );
}

function renderRow(row: ResellerQuota) {
  const key = `${row.LicenseCategoryId}-${row.LicenseTierId}`;

  return (
    <tr key={key} className="border-b border-border/60">
      <td className="py-2 pr-4">{row.LicenseCategoryId}</td>
      <td className="py-2 pr-4">{row.LicenseTierId}</td>
      <td className="py-2 pr-4">{row.LicensesGranted}</td>
      <td className="py-2 pr-4">{row.LicensesConsumed}</td>
      <td className="py-2 pr-4 font-medium">{row.LicensesRemaining}</td>
    </tr>
  );
}
