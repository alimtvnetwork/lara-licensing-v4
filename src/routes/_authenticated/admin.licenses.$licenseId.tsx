import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";

import { LicenseDetailActions } from "../../components/admin/license-detail-actions";
import { LicenseLedger } from "../../components/admin/license-ledger";
import { PageHeader } from "../../components/shell/PageHeader";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { findClosedSetOption } from "../../lib/closed-sets";
import { licenseQueryOptions } from "../../lib/lara-license";
// Plan 16 step 69: type-only consumer of the real-BE barrel. Runtime call
// still goes through `requestLaraApi` inside `licenseQueryOptions`; only
// the `License` type is routed through `@/generated/api/real-be-schema`
// to pin the barrel's `License` re-export as load-bearing.
import type { License } from "@/generated/api/real-be-schema";

// Plan 09 step 40. Root cause of the pre-refit surface: `LicenseFacts`
// stringified the raw FK ids (`LicenseCategoryId=1`, `LicenseTierId=3`,
// `EnvironmentId=2`), so support staff read "Category 1" instead of
// "Daily" and had to cross-reference spec/21-app/05 by hand. Fix: resolve
// each FK to its closed-set label via `findClosedSetOption` (canonical
// per src/lib/closed-sets.ts) before render, and fall back to the raw
// id ONLY when the value is out of set (a wire drift we still want visible
// rather than silently masked).

export const Route = createFileRoute("/_authenticated/admin/licenses/$licenseId")({
  ssr: false,
  parseParams: ({ licenseId }) => ({ licenseId: parseLicenseId(licenseId) }),
  loader: ({ context, params }) =>
    context.queryClient.ensureQueryData(licenseQueryOptions(params.licenseId)),
  head: () => ({
    meta: [
      { title: "License detail | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: () => (
    <RoutePending title="License" description="Loading license detail." rows={4} />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="License" error={error} reset={reset} />
  ),
  notFoundComponent: DetailNotFound,
  component: LicenseDetailPage,
});

function parseLicenseId(raw: string): number {
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new Error(`Invalid licenseId: ${raw}`);
  }

  return parsed;
}

function crumbsFor(licenseId: number) {
  return [
    { label: "Admin", to: "/admin" },
    { label: `License ${licenseId}`, identifier: true },
  ];
}

function LicenseDetailPage() {
  const { licenseId } = Route.useParams();
  const { data } = useSuspenseQuery(licenseQueryOptions(licenseId));
  const { license, etag } = data;
  if (license === undefined) return <DetailNotFound />;

  return (
    <>
      <PageHeader
        title={`License ${license.LicenseId}`}
        breadcrumbs={crumbsFor(license.LicenseId)}
      />
      <LicenseFacts license={license} />
      <LicenseDetailActions license={license} etag={etag} />
      <LicenseLedger licenseId={license.LicenseId} />
    </>
  );
}

function labelFor<T extends number>(
  name: Parameters<typeof findClosedSetOption>[0],
  value: T,
): string {
  const option = findClosedSetOption(name, value as never);

  return option?.label ?? `#${value}`;
}

function LicenseFacts({ license }: { license: License }) {
  const category = labelFor("LicenseCategory", license.LicenseCategoryId);
  const tier = labelFor("LicenseTier", license.LicenseTierId);
  const environment = labelFor("Environment", license.EnvironmentId);

  return (
    <dl className="mt-6 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
      <Fact label="Category" value={category} />
      <Fact label="Tier" value={tier} />
      <Fact label="Environment" value={environment} />
      <Fact label="Product version" value={license.ProductVersion} />
      <Fact label="Issued at" value={license.IssuedAt} />
      <Fact label="Expires at" value={license.ExpiresAt ?? "Never"} />
      <Fact label="Active" value={license.IsActive ? "Yes" : "No"} />
      <Fact label="Single use" value={license.IsSingleUse ? "Yes" : "No"} />
    </dl>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="font-medium text-foreground">{value}</dd>
    </div>
  );
}

function DetailNotFound() {
  return (
    <>
      <PageHeader title="License not found" />
      <Link to="/admin" className="inline-block text-sm underline">
        Back to console
      </Link>
    </>
  );
}
