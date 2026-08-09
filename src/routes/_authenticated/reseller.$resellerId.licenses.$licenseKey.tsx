import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";

import { PageHeader } from "../../components/shell/PageHeader";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { meQueryOptions } from "../../lib/lara-me";
import {
  resellerLicenseDetailQueryOptions,
  type ResellerLicense,
} from "../../lib/lara-reseller-license";

/**
 * Plan 09 Step 48: reseller license detail at
 * `/reseller/$resellerId/licenses/$licenseKey`. Renders the same denormalized
 * fields the list route (Step 47) surfaces plus the RevokeReason + Version.
 * Backend contract: `GET /Api/Reseller/Licenses/{LicenseKey}` in
 * `backend/app/Http/Controllers/Reseller/LicenseController.php::show`.
 * Existence-leak protection: cross-tenant probes get `LicenseNotFound (404)`
 * (see spec/21-app/12-error-taxonomy.md §Existence leak), so the client
 * treats the loader error the same as a genuinely missing row.
 */
export const Route = createFileRoute("/_authenticated/reseller/$resellerId/licenses/$licenseKey")({
  ssr: false,
  parseParams: ({ resellerId, licenseKey }) => ({
    resellerId: parseResellerId(resellerId),
    licenseKey: parseLicenseKey(licenseKey),
  }),
  head: () => ({
    meta: [
      { title: "License detail | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: () => (
    <RoutePending title="License" description="Loading license detail." rows={3} />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="License" error={error} reset={reset} />
  ),
  component: ResellerLicenseDetailPage,
});

function parseResellerId(raw: string): number {
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new Error(`Invalid resellerId: ${raw}`);
  }

  return parsed;
}

function parseLicenseKey(raw: string): string {
  if (!/^[A-Z0-9-]{4,80}$/.test(raw)) {
    throw new Error(`Invalid licenseKey: ${raw}`);
  }

  return raw;
}

function crumbsFor(resellerId: number, licenseKey: string) {
  return [
    { label: "Reseller", to: `/reseller/${resellerId}` },
    { label: "Licenses", to: `/reseller/${resellerId}/licenses` },
    { label: licenseKey, identifier: true },
  ];
}

function ResellerLicenseDetailPage() {
  const { resellerId, licenseKey } = Route.useParams();
  const { data: meRows } = useSuspenseQuery(meQueryOptions());
  const [me] = meRows;
  const isFailed = !me;
  if (isFailed) {
    throw new Error(
      "Users.Me returned an empty envelope; server invariant break per AC-API-USR-001",
    );
  }
  const isMismatch = me.RoleName === "Reseller" && me.ResellerId !== resellerId;
  const { data: rows } = useSuspenseQuery(
    resellerLicenseDetailQueryOptions(resellerId, licenseKey),
  );
  const [license] = rows;

  return (
    <>
      <PageHeader title={`License ${licenseKey}`} breadcrumbs={crumbsFor(resellerId, licenseKey)} />
      {isMismatch ? (
        <ForbiddenGate callerResellerId={me.ResellerId ?? null} urlResellerId={resellerId} />
      ) : license ? (
        <LicenseFacts license={license} resellerId={resellerId} />
      ) : (
        <p className="text-sm text-muted-foreground">License not found.</p>
      )}
    </>
  );
}

function ForbiddenGate(props: { callerResellerId: number | null; urlResellerId: number }) {
  return (
    <section
      role="alert"
      className="rounded-md border border-destructive/50 bg-destructive/5 p-5 text-sm"
    >
      <h2 className="text-base font-semibold text-destructive">Access denied</h2>
      <p className="mt-2 text-muted-foreground">
        Your account is scoped to reseller {props.callerResellerId ?? "(none)"}, but this page
        targets reseller {props.urlResellerId}.
      </p>
    </section>
  );
}

function LicenseFacts({ license, resellerId }: { license: ResellerLicense; resellerId: number }) {
  return (
    <section className="rounded-md border border-border bg-card p-5">
      <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <Fact label="License key" value={<span className="font-mono">{license.LicenseKey}</span>} />
        <Fact label="Status" value={license.Status} />
        <Fact label="Tier" value={license.TierName} />
        <Fact label="Environment" value={license.EnvironmentName} />
        <Fact label="Product" value={license.ProductVersion} />
        <Fact label="Prefix" value={license.PrefixValue} />
        <Fact label="Issued" value={formatDate(license.IssuedAt)} />
        <Fact
          label="Expires"
          value={license.ExpiresAt === "" ? "Never" : formatDate(license.ExpiresAt)}
        />
        <Fact
          label="Issued by"
          value={`User ${license.IssuedByUserId} (${license.IssuerActorType || "Unknown"})`}
        />
        <Fact label="Version" value={String(license.Version)} />
        {license.RevokedAt !== "" ? (
          <>
            <Fact label="Revoked at" value={formatDate(license.RevokedAt)} />
            <Fact label="Revoke reason" value={license.RevokeReason || "(none)"} />
          </>
        ) : null}
      </dl>
      <div className="mt-5 flex items-center gap-3 text-sm">
        <Link
          to="/reseller/$resellerId/licenses"
          params={{ resellerId: resellerId }}
          className="text-primary underline-offset-4 hover:underline"
        >
          Back to licenses
        </Link>
      </div>
    </section>
  );
}

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="mt-1">{value}</dd>
    </div>
  );
}

function formatDate(iso: string): string {
  if (iso === "") return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;

  return d.toISOString().slice(0, 16).replace("T", " ") + " UTC";
}
