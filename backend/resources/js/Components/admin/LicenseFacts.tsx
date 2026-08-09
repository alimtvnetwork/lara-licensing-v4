import { findClosedSetOption } from "@/lib/closed-sets";

export interface License {
  LicenseId: number;
  LicenseKey: string;
  LicenseCategoryId: number;
  LicenseTierId: number;
  EnvironmentId: number;
  ProductVersion: string;
  IssuedAt: string;
  ExpiresAt: string | null;
  IsActive: boolean;
  IsSingleUse: boolean;
  Status: string;
  Version: number;
}

function labelFor<T extends number>(
  name: "LicenseCategory" | "LicenseTier" | "Environment",
  value: T,
): string {
  const option = findClosedSetOption(name, value as never);
  return option?.label ?? "#${value}";
}

export function LicenseFacts({ license }: { license: License }) {
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
      <Fact label="Status" value={license.Status} />
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
