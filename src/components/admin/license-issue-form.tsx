import { useState, type FormEvent } from "react";
import { useNavigate } from "@tanstack/react-router";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { SerialIssueForm } from "./serial-issue-form";
import { RetryAfterBanner } from "../retry-after-banner";
import { useSubmitLock } from "../../lib/use-submit-lock";

import { formatLaraApiError } from "../../lib/lara-api-error";

import {
  createLicense,
  licenseCreateSchema,
  type License,
  type LicenseCreateInput,
} from "../../lib/lara-license";
import {
  preflightLicenseQuota,
  resellerQuotasQueryOptions,
  type ResellerQuota,
} from "../../lib/lara-quota";

interface FormState {
  LicenseCategoryId: string;
  LicenseTierId: string;
  EnvironmentId: string;
  LicensePackageId: string;
  ResellerId: string;
  ProductVersion: string;
  UserCount: string;
  MachineCount: string;
  IsSingleUse: boolean;
}

const INITIAL: FormState = {
  LicenseCategoryId: "1",
  LicenseTierId: "1",
  EnvironmentId: "1",
  LicensePackageId: "",
  ResellerId: "",
  ProductVersion: "",
  UserCount: "",
  MachineCount: "",
  IsSingleUse: false,
};

/**
 * Category dropdown bound to the closed ordinal set defined in
 * spec/21-app/05-license-categories.md §Canonical set (AC-CAT-005).
 * Labels and ordinals here MUST match that table verbatim.
 */
const CATEGORY_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
  { value: "1", label: "Daily" },
  { value: "2", label: "Weekly" },
  { value: "3", label: "Monthly" },
  { value: "4", label: "Yearly" },
  { value: "5", label: "Lifetime" },
  { value: "6", label: "Dev" },
  { value: "7", label: "Key" },
];

function toInput(state: FormState): LicenseCreateInput {
  const optionalInt = (value: string): number | undefined => {
    const trimmed = value.trim();
    if (trimmed === "") return undefined;
    const parsed = Number(trimmed);

    return Number.isFinite(parsed) ? parsed : Number.NaN;
  };

  return licenseCreateSchema.parse({
    LicenseCategoryId: Number(state.LicenseCategoryId),
    LicenseTierId: Number(state.LicenseTierId),
    EnvironmentId: Number(state.EnvironmentId),
    LicensePackageId: optionalInt(state.LicensePackageId),
    ResellerId: optionalInt(state.ResellerId),
    ProductVersion: state.ProductVersion.trim(),
    UserCount: optionalInt(state.UserCount),
    MachineCount: optionalInt(state.MachineCount),
    IsSingleUse: state.IsSingleUse,
  });
}

const describeError = formatLaraApiError;

type QueryClientLike = ReturnType<typeof useQueryClient>;

/**
 * Reseller-scoped preflight. When the caller specified a `ResellerId`, we
 * prime the ResellerQuotas cache and throw the exact LaraApiError shape the
 * server would have returned (spec/21-app/11-api-contracts/02-license-contracts.md
 * §Reseller quota decrement steps 3-4, AC-API-LIC-006). Admin-issued licenses
 * (no ResellerId) skip preflight per §Admin-issued (AC-QUOTA-003). A stale
 * cache is a no-op so the server envelope is always authoritative.
 */
async function runResellerPreflight(
  input: LicenseCreateInput,
  queryClient: QueryClientLike,
): Promise<void> {
  if (input.ResellerId === undefined) return;
  const options = resellerQuotasQueryOptions(input.ResellerId);
  const quotas = (await queryClient.ensureQueryData(options as never)) as ResellerQuota[];
  preflightLicenseQuota(quotas, input.LicenseCategoryId, input.LicenseTierId);
}

export function LicenseIssueForm() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [state, setState] = useState<FormState>(INITIAL);
  const [issued, setIssued] = useState<License | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errorRaw, setErrorRaw] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState(false);
  const submitLock = useSubmitLock(errorRaw);

  const update = <K extends keyof FormState>(key: K, value: FormState[K]) =>
    setState((prev) => ({ ...prev, [key]: value }));

  async function submitCore() {
    setError(null);
    setErrorRaw(null);
    setSubmitting(true);
    try {
      const input = toInput(state);
      await runResellerPreflight(input, queryClient);
      const license = await createLicense(input, crypto.randomUUID());
      setIssued(license);
    } catch (submitError) {
      const description = describeError(submitError);
      setError(description);
      setErrorRaw(submitError);
      toast.error("Could not issue license", { description });
    } finally {
      setSubmitting(false);
    }
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await submitCore();
  }

  if (issued) {
    return (
      <div className="rounded-md border border-border bg-card p-6">
        <p className="text-xs font-medium text-muted-foreground">LICENSE ISSUED</p>
        <h2 className="mt-2 text-lg font-semibold">License #{issued.LicenseId}</h2>
        <dl className="mt-4 grid grid-cols-2 gap-2 text-sm">
          <dt className="text-muted-foreground">Category</dt>
          <dd>{issued.LicenseCategoryId}</dd>
          <dt className="text-muted-foreground">Version</dt>
          <dd>{issued.ProductVersion}</dd>
          <dt className="text-muted-foreground">Single use</dt>
          <dd>{issued.IsSingleUse ? "Yes" : "No"}</dd>
          <dt className="text-muted-foreground">Active</dt>
          <dd>{issued.IsActive ? "Yes" : "No"}</dd>
          <dt className="text-muted-foreground">Issued at</dt>
          <dd>{issued.IssuedAt}</dd>
        </dl>
        <div className="mt-6">
          <SerialIssueForm licenseId={issued.LicenseId} />
        </div>
        <div className="mt-6 flex gap-2">
          <button
            type="button"
            onClick={() => {
              setIssued(null);
              setState(INITIAL);
            }}
            className="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent"
          >
            Issue another
          </button>
          <button
            type="button"
            onClick={() =>
              void navigate({
                to: "/admin/licenses/$licenseId",
                params: { licenseId: issued.LicenseId },
              })
            }
            className="inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90"
          >
            Open license
          </button>
        </div>
      </div>
    );
  }

  return (
    <form
      onSubmit={onSubmit}
      className="space-y-4 rounded-md border border-border bg-card p-6"
      noValidate
    >
      <FormRow label="LicenseCategoryId" required>
        <select
          value={state.LicenseCategoryId}
          onChange={(e) => update("LicenseCategoryId", e.target.value)}
          required
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        >
          {CATEGORY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </FormRow>
      <FormRow label="LicenseTierId" required>
        <select
          value={state.LicenseTierId}
          onChange={(e) => update("LicenseTierId", e.target.value)}
          required
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        >
          <option value="1">Tier1</option>
          <option value="2">Tier2</option>
          <option value="3">Tier3</option>
          <option value="4">Unlimited</option>
        </select>
      </FormRow>
      <FormRow label="EnvironmentId" required>
        <select
          value={state.EnvironmentId}
          onChange={(e) => update("EnvironmentId", e.target.value)}
          required
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        >
          <option value="1">Production</option>
          <option value="2">Staging</option>
          <option value="3">Development</option>
        </select>
      </FormRow>
      <FormRow label="ProductVersion" required>
        <input
          value={state.ProductVersion}
          onChange={(e) => update("ProductVersion", e.target.value)}
          required
          maxLength={64}
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </FormRow>
      <FormRow label="LicensePackageId (optional)">
        <input
          value={state.LicensePackageId}
          onChange={(e) => update("LicensePackageId", e.target.value)}
          inputMode="numeric"
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </FormRow>
      <FormRow label="ResellerId (optional)">
        <input
          value={state.ResellerId}
          onChange={(e) => update("ResellerId", e.target.value)}
          inputMode="numeric"
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </FormRow>
      <FormRow label="UserCount (optional)">
        <input
          value={state.UserCount}
          onChange={(e) => update("UserCount", e.target.value)}
          inputMode="numeric"
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </FormRow>
      <FormRow label="MachineCount (optional)">
        <input
          value={state.MachineCount}
          onChange={(e) => update("MachineCount", e.target.value)}
          inputMode="numeric"
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </FormRow>
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={state.IsSingleUse}
          onChange={(e) => update("IsSingleUse", e.target.checked)}
        />{" "}
        Single use
      </label>
      <RetryAfterBanner
        error={errorRaw}
        onRetry={() => {
          void submitCore();
        }}
      />
      {error ? (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
      <button
        type="submit"
        disabled={submitting || submitLock.locked}
        aria-disabled={submitting || submitLock.locked}
        data-submit-locked={submitLock.locked ? "true" : "false"}
        className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
      >
        {submitLock.locked
          ? `Retry in ${submitLock.remainingSeconds}s`
          : submitting
            ? "Issuing..."
            : "Issue license"}
      </button>
    </form>
  );
}

function FormRow({
  label,
  required,
  children,
}: {
  label: string;
  required?: boolean;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium">
        {label}
        {required ? " *" : ""}
      </span>
      {children}
    </label>
  );
}
