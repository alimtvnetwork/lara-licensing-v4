// Plan 06 step 68. Reseller portal surfaces: dashboard tiles + license roster.
//
// Ported from src/routes/_authenticated/reseller.$resellerId.index.tsx and
// reseller.$resellerId.licenses.index.tsx. Every number is server truth
// resolved in routes/web.php from the shard-bound Quotas / QuotaRequests /
// Licenses reads; nothing is fetched or recomputed client-side, so there is
// no optimistic state to drift from the shard.

import * as React from "react";
import { Link } from "@inertiajs/react";
import { KeyRound, KeySquare, PackageCheck } from "lucide-react";

import { StatCard } from "@/Components/ui/StatCard";
import { EmptyState } from "@/Components/ui/EmptyState";

export interface ResellerQuotaRow {
  LicenseCategoryId: number;
  LicenseTierId: number;
  LicensesGranted: number;
  LicensesConsumed: number;
  LicensesRemaining: number;
  PeriodStart: string | null;
  PeriodEnd: string | null;
}

export interface ResellerLicenseRow {
  LicenseId: number;
  LicenseKey: string;
  LicenseCategoryId?: number | null;
  LicenseTierId?: number | null;
  StatusId?: number | null;
  Status?: string | null;
  ExpiresAt?: string | null;
  CreatedAt?: string | null;
}

const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: "medium" });

function formatDate(value: string | null | undefined): string {
  if (!value) return "unknown";
  const parsed = Date.parse(value);
  return Number.isNaN(parsed) ? "unknown" : dateFormatter.format(new Date(parsed));
}

export function ResellerQuotaTiles({
  quotas,
  pendingRequests,
}: {
  quotas: ResellerQuotaRow[];
  pendingRequests: number;
}) {
  const granted = quotas.reduce((sum, row) => sum + row.LicensesGranted, 0);
  const consumed = quotas.reduce((sum, row) => sum + row.LicensesConsumed, 0);
  const remaining = quotas.reduce((sum, row) => sum + row.LicensesRemaining, 0);
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <StatCard
        label="Total allowances"
        value={granted.toLocaleString()}
        icon={<PackageCheck className="size-5" />}
        description={`${quotas.length} tier${quotas.length === 1 ? "" : "s"} tracked`}
      />
      <StatCard
        label="Licenses consumed"
        value={consumed.toLocaleString()}
        icon={<KeyRound className="size-5" />}
        description={`${remaining.toLocaleString()} remaining across tiers`}
      />
      <StatCard
        label="Pending quota requests"
        value={pendingRequests.toLocaleString()}
        icon={<KeySquare className="size-5" />}
        description={pendingRequests === 0 ? "Nothing awaiting approval" : "Awaiting admin decision"}
      />
    </div>
  );
}

export function ResellerQuotaTable({ quotas }: { quotas: ResellerQuotaRow[] }) {
  if (quotas.length === 0) {
    return (
      <EmptyState
        preset="box"
        headline="No quota allowances yet"
        body="An administrator grants allowances per category and tier; approved quota requests appear here."
      />
    );
  }
  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <table className="min-w-full text-sm">
        <thead className="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
          <tr>
            <th scope="col" className="px-3 py-2 font-medium">Category</th>
            <th scope="col" className="px-3 py-2 font-medium">Tier</th>
            <th scope="col" className="px-3 py-2 font-medium">Granted</th>
            <th scope="col" className="px-3 py-2 font-medium">Consumed</th>
            <th scope="col" className="px-3 py-2 font-medium">Remaining</th>
            <th scope="col" className="px-3 py-2 font-medium">Period</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {quotas.map((row) => (
            <tr key={`${row.LicenseCategoryId}-${row.LicenseTierId}-${row.PeriodStart ?? "none"}`}>
              <td className="px-3 py-2 font-mono text-xs">#{row.LicenseCategoryId}</td>
              <td className="px-3 py-2 font-mono text-xs">#{row.LicenseTierId}</td>
              <td className="px-3 py-2">{row.LicensesGranted.toLocaleString()}</td>
              <td className="px-3 py-2">{row.LicensesConsumed.toLocaleString()}</td>
              <td className="px-3 py-2 font-medium">{row.LicensesRemaining.toLocaleString()}</td>
              <td className="px-3 py-2 text-xs text-muted-foreground">
                {formatDate(row.PeriodStart)} to {formatDate(row.PeriodEnd)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function ResellerLicenseTable({
  licenses,
  resellerId,
}: {
  licenses: ResellerLicenseRow[];
  resellerId: number;
}) {
  const [search, setSearch] = React.useState("");
  const term = search.trim().toLowerCase();
  const rows = term === "" ? licenses : licenses.filter((l) => l.LicenseKey.toLowerCase().includes(term));

  return (
    <div>
      <label htmlFor="reseller-license-search" className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
        Search license key
        <input
          id="reseller-license-search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="LIC-..."
          className="h-9 w-72 rounded-md border border-input bg-background px-3 text-sm text-foreground"
        />
      </label>

      {rows.length === 0 ? (
        <EmptyState
          className="mt-6"
          preset="box"
          headline={term === "" ? "No licenses issued yet" : "No licenses match this search"}
          body={
            term === ""
              ? "Licenses minted against your allowances appear here."
              : "Clear the search box to see the full roster."
          }
        />
      ) : (
        <div className="mt-6 overflow-x-auto rounded-lg border border-border">
          <table className="min-w-full text-sm">
            <thead className="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th scope="col" className="px-3 py-2 font-medium">License key</th>
                <th scope="col" className="px-3 py-2 font-medium">Category / tier</th>
                <th scope="col" className="px-3 py-2 font-medium">Status</th>
                <th scope="col" className="px-3 py-2 font-medium">Expires</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {rows.map((license) => (
                <tr key={license.LicenseId}>
                  <td className="px-3 py-2 font-mono text-xs">
                    <Link
                      href={`/reseller/${resellerId}/licenses/${encodeURIComponent(license.LicenseKey)}`}
                      className="underline underline-offset-2"
                    >
                      {license.LicenseKey}
                    </Link>
                  </td>
                  <td className="px-3 py-2 font-mono text-xs">
                    #{license.LicenseCategoryId ?? "?"} / #{license.LicenseTierId ?? "?"}
                  </td>
                  <td className="px-3 py-2 text-xs">{license.Status ?? (license.StatusId === null || license.StatusId === undefined ? "unknown" : `#${license.StatusId}`)}</td>
                  <td className="px-3 py-2 text-xs text-muted-foreground">{formatDate(license.ExpiresAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
