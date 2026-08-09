import { useMemo, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "@tanstack/react-router";
import { toast } from "sonner";

import { Stepper, type StepperStep } from "../ui/stepper";
import { formatLaraApiErrorOptional } from "../../lib/lara-api-error";
import { useLaraErrorToast } from "../../lib/use-lara-error-toast";
import {
  createLicense,
  licenseCreateSchema,
  LicenseCategoryIdType,
  LicenseTierIdType,
  type LicenseCreateInput,
} from "../../lib/lara-license";
import {
  preflightLicenseQuota,
  resellerQuotasQueryOptions,
  type ResellerQuota,
} from "../../lib/lara-quota";

// ---- Constants (no magic literals) ----------------------------------------

const WIZARD_STEPS: readonly StepperStep[] = [
  { id: "reseller", label: "Reseller", description: "Reseller and package" },
  { id: "tier", label: "Tier", description: "Category and tier" },
  { id: "features", label: "Features", description: "Version and limits" },
  { id: "environment", label: "Environment", description: "Deployment target" },
  { id: "confirm", label: "Confirm", description: "Review and issue" },
];

const LAST_STEP_INDEX = WIZARD_STEPS.length - 1;

const CATEGORY_OPTIONS = [
  { value: LicenseCategoryIdType.Daily, label: "Daily" },
  { value: LicenseCategoryIdType.Weekly, label: "Weekly" },
  { value: LicenseCategoryIdType.Monthly, label: "Monthly" },
  { value: LicenseCategoryIdType.Yearly, label: "Yearly" },
  { value: LicenseCategoryIdType.Lifetime, label: "Lifetime" },
  { value: LicenseCategoryIdType.Dev, label: "Dev" },
  { value: LicenseCategoryIdType.Key, label: "Key" },
] as const;

const TIER_OPTIONS = [
  { value: LicenseTierIdType.Tier1, label: "Tier 1" },
  { value: LicenseTierIdType.Tier2, label: "Tier 2" },
  { value: LicenseTierIdType.Tier3, label: "Tier 3" },
  { value: LicenseTierIdType.Unlimited, label: "Unlimited" },
] as const;

const ENVIRONMENT_OPTIONS = [
  { value: 1, label: "Production" },
  { value: 2, label: "Staging" },
  { value: 3, label: "Development" },
] as const;

// ---- State shape ------------------------------------------------------------

interface WizardState {
  ResellerId: string;
  LicensePackageId: string;
  LicenseCategoryId: number;
  LicenseTierId: number;
  ProductVersion: string;
  UserCount: string;
  MachineCount: string;
  IsSingleUse: boolean;
  EnvironmentId: number;
}

const INITIAL_STATE: WizardState = {
  ResellerId: "",
  LicensePackageId: "",
  LicenseCategoryId: LicenseCategoryIdType.Key,
  LicenseTierId: LicenseTierIdType.Tier1,
  ProductVersion: "",
  UserCount: "",
  MachineCount: "",
  IsSingleUse: false,
  EnvironmentId: 1,
};

// ---- Helpers ----------------------------------------------------------------

function optionalInt(value: string): number | undefined {
  const trimmed = value.trim();
  if (trimmed === "") return undefined;
  const parsed = Number(trimmed);

  return Number.isFinite(parsed) ? parsed : undefined;
}

function buildInput(state: WizardState): LicenseCreateInput {
  return licenseCreateSchema.parse({
    LicenseCategoryId: state.LicenseCategoryId,
    LicenseTierId: state.LicenseTierId,
    EnvironmentId: state.EnvironmentId,
    LicensePackageId: optionalInt(state.LicensePackageId),
    ResellerId: optionalInt(state.ResellerId),
    ProductVersion: state.ProductVersion.trim(),
    UserCount: optionalInt(state.UserCount),
    MachineCount: optionalInt(state.MachineCount),
    IsSingleUse: state.IsSingleUse,
  });
}

type QC = ReturnType<typeof useQueryClient>;

async function runQuotaPreflight(input: LicenseCreateInput, queryClient: QC): Promise<void> {
  if (input.ResellerId === undefined) return;
  const options = resellerQuotasQueryOptions(input.ResellerId);
  const quotas = (await queryClient.ensureQueryData(options as never)) as ResellerQuota[];
  preflightLicenseQuota(quotas, input.LicenseCategoryId, input.LicenseTierId);
}

// ---- Main component ---------------------------------------------------------

export function LicenseCreateWizard() {
  const [stepIndex, setStepIndex] = useState(0);
  const [state, setState] = useState<WizardState>(INITIAL_STATE);
  const [stepError, setStepError] = useState<string | undefined>();
  const router = useRouter();
  const queryClient = useQueryClient();

  const parsedInput = useMemo(
    () =>
      licenseCreateSchema.safeParse({
        LicenseCategoryId: state.LicenseCategoryId,
        LicenseTierId: state.LicenseTierId,
        EnvironmentId: state.EnvironmentId,
        LicensePackageId: optionalInt(state.LicensePackageId),
        ResellerId: optionalInt(state.ResellerId),
        ProductVersion: state.ProductVersion.trim(),
        UserCount: optionalInt(state.UserCount),
        MachineCount: optionalInt(state.MachineCount),
        IsSingleUse: state.IsSingleUse,
      }),
    [state],
  );

  const mutation = useMutation({
    mutationFn: async (input: LicenseCreateInput) => {
      await runQuotaPreflight(input, queryClient);

      return createLicense(input, crypto.randomUUID());
    },
    onSuccess: (license) => {
      toast.success(`License #${license.LicenseId} issued`);
      void router.navigate({
        to: "/admin/licenses/$licenseId",
        params: { licenseId: license.LicenseId },
      });
    },
  });

  useLaraErrorToast(mutation.error, "Could not issue license");

  const update = <K extends keyof WizardState>(key: K, value: WizardState[K]) => {
    setState((prev) => ({ ...prev, [key]: value }));
    setStepError(undefined);
  };

  const goNext = () => {
    setStepError(undefined);
    if (stepIndex === LAST_STEP_INDEX) {
      const isFailed = !parsedInput.success;
      if (isFailed) {
        setStepError(parsedInput.error.issues[0]?.message ?? "Invalid input");

        return;
      }
      mutation.mutate(buildInput(state));

      return;
    }
    setStepIndex((prev) => prev + 1);
  };

  const goBack = (target?: number) => {
    setStepError(undefined);
    setStepIndex((prev) => Math.max(0, target ?? prev - 1));
  };

  return (
    <div className="mt-6 max-w-2xl space-y-8">
      <Stepper
        steps={WIZARD_STEPS}
        activeIndex={stepIndex}
        onStepSelect={(index) => goBack(index)}
        label="License creation progress"
      />
      <WizardStepContent stepIndex={stepIndex} state={state} onUpdate={update} />
      <WizardError message={stepError ?? formatLaraApiErrorOptional(mutation.error)} />
      <WizardActions
        stepIndex={stepIndex}
        lastIndex={LAST_STEP_INDEX}
        pending={mutation.isPending}
        onBack={() => goBack()}
        onNext={goNext}
      />
    </div>
  );
}

// ---- Step content dispatcher ------------------------------------------------

function WizardStepContent({
  stepIndex,
  state,
  onUpdate,
}: {
  stepIndex: number;
  state: WizardState;
  onUpdate: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
}) {
  if (stepIndex === 0) return <ResellerStep state={state} onUpdate={onUpdate} />;
  if (stepIndex === 1) return <TierStep state={state} onUpdate={onUpdate} />;
  if (stepIndex === 2) return <FeaturesStep state={state} onUpdate={onUpdate} />;
  if (stepIndex === 3) return <EnvironmentStep state={state} onUpdate={onUpdate} />;

  return <ConfirmStep state={state} />;
}

// ---- Step 1: Reseller -------------------------------------------------------

function ResellerStep({
  state,
  onUpdate,
}: {
  state: WizardState;
  onUpdate: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
}) {
  return (
    <section className="space-y-4" aria-label="Reseller">
      <p className="text-sm text-muted-foreground">
        Leave Reseller ID empty to issue an admin-scoped license (quota exempt).
      </p>
      <FormField
        id="ResellerId"
        label="Reseller ID (optional)"
        inputMode="numeric"
        value={state.ResellerId}
        onChange={(v) => onUpdate("ResellerId", v)}
      />
      <FormField
        id="LicensePackageId"
        label="Package ID (optional)"
        inputMode="numeric"
        value={state.LicensePackageId}
        onChange={(v) => onUpdate("LicensePackageId", v)}
      />
    </section>
  );
}

// ---- Step 2: Tier -----------------------------------------------------------

function TierStep({
  state,
  onUpdate,
}: {
  state: WizardState;
  onUpdate: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
}) {
  return (
    <section className="space-y-4" aria-label="Tier">
      <SelectField
        id="LicenseCategoryId"
        label="License category"
        value={state.LicenseCategoryId}
        onChange={(v) => onUpdate("LicenseCategoryId", Number(v))}
        options={CATEGORY_OPTIONS.map((o) => ({ value: String(o.value), label: o.label }))}
      />
      <SelectField
        id="LicenseTierId"
        label="License tier"
        value={state.LicenseTierId}
        onChange={(v) => onUpdate("LicenseTierId", Number(v))}
        options={TIER_OPTIONS.map((o) => ({ value: String(o.value), label: o.label }))}
      />
    </section>
  );
}

// ---- Step 3: Features -------------------------------------------------------

function FeaturesStep({
  state,
  onUpdate,
}: {
  state: WizardState;
  onUpdate: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
}) {
  return (
    <section className="space-y-4" aria-label="Features">
      <FormField
        id="ProductVersion"
        label="Product version"
        required
        value={state.ProductVersion}
        onChange={(v) => onUpdate("ProductVersion", v)}
        maxLength={64}
      />
      <FormField
        id="UserCount"
        label="User count (optional)"
        inputMode="numeric"
        value={state.UserCount}
        onChange={(v) => onUpdate("UserCount", v)}
      />
      <FormField
        id="MachineCount"
        label="Machine count (optional)"
        inputMode="numeric"
        value={state.MachineCount}
        onChange={(v) => onUpdate("MachineCount", v)}
      />
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          className="size-4 rounded border-input"
          checked={state.IsSingleUse}
          onChange={(e) => onUpdate("IsSingleUse", e.target.checked)}
        />
        Single use
      </label>
    </section>
  );
}

// ---- Step 4: Environment ----------------------------------------------------

function EnvironmentStep({
  state,
  onUpdate,
}: {
  state: WizardState;
  onUpdate: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
}) {
  return (
    <section className="space-y-4" aria-label="Environment">
      <SelectField
        id="EnvironmentId"
        label="Deployment environment"
        value={state.EnvironmentId}
        onChange={(v) => onUpdate("EnvironmentId", Number(v))}
        options={ENVIRONMENT_OPTIONS.map((o) => ({ value: String(o.value), label: o.label }))}
      />
    </section>
  );
}

// ---- Step 5: Confirm --------------------------------------------------------

function ConfirmStep({ state }: { state: WizardState }) {
  const categoryLabel =
    CATEGORY_OPTIONS.find((o) => o.value === state.LicenseCategoryId)?.label ??
    String(state.LicenseCategoryId);
  const tierLabel =
    TIER_OPTIONS.find((o) => o.value === state.LicenseTierId)?.label ?? String(state.LicenseTierId);
  const envLabel =
    ENVIRONMENT_OPTIONS.find((o) => o.value === state.EnvironmentId)?.label ??
    String(state.EnvironmentId);

  return (
    <section className="space-y-3" aria-label="Confirm">
      <p className="text-sm text-muted-foreground">
        Quota preflight runs on submit. Review before issuing.
      </p>
      <dl className="grid grid-cols-[12rem_1fr] gap-y-2 text-sm">
        <dt className="text-muted-foreground">Reseller ID</dt>
        <dd>{state.ResellerId || "Admin-issued (no reseller)"}</dd>
        <dt className="text-muted-foreground">Package ID</dt>
        <dd>{state.LicensePackageId || "None"}</dd>
        <dt className="text-muted-foreground">Category</dt>
        <dd>{categoryLabel}</dd>
        <dt className="text-muted-foreground">Tier</dt>
        <dd>{tierLabel}</dd>
        <dt className="text-muted-foreground">Product version</dt>
        <dd>{state.ProductVersion}</dd>
        <dt className="text-muted-foreground">User count</dt>
        <dd>{state.UserCount || "Unlimited"}</dd>
        <dt className="text-muted-foreground">Machine count</dt>
        <dd>{state.MachineCount || "Unlimited"}</dd>
        <dt className="text-muted-foreground">Single use</dt>
        <dd>{state.IsSingleUse ? "Yes" : "No"}</dd>
        <dt className="text-muted-foreground">Environment</dt>
        <dd>{envLabel}</dd>
      </dl>
    </section>
  );
}

// ---- Shared primitives ------------------------------------------------------

function WizardActions({
  stepIndex,
  lastIndex,
  pending,
  onBack,
  onNext,
}: {
  stepIndex: number;
  lastIndex: number;
  pending: boolean;
  onBack: () => void;
  onNext: () => void;
}) {
  return (
    <div className="flex items-center gap-3">
      <button
        type="button"
        onClick={onBack}
        disabled={stepIndex === 0 || pending}
        className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent disabled:opacity-60"
      >
        Back
      </button>
      <button
        type="button"
        onClick={onNext}
        disabled={pending}
        className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
      >
        {pending ? "Issuing..." : stepIndex === lastIndex ? "Issue license" : "Next"}
      </button>
    </div>
  );
}

function WizardError({ message }: { message: string | null | undefined }) {
  const isFailed = !message;
  if (isFailed) return null;

  return (
    <p role="alert" className="text-sm text-destructive">
      {message}
    </p>
  );
}

function FormField({
  id,
  label,
  value,
  onChange,
  required,
  inputMode,
  maxLength,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
  inputMode?: React.HTMLAttributes<HTMLInputElement>["inputMode"];
  maxLength?: number;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
        {required ? " *" : ""}
      </label>
      <input
        id={id}
        name={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        inputMode={inputMode}
        maxLength={maxLength}
        className="h-10 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
    </div>
  );
}

function SelectField({
  id,
  label,
  value,
  onChange,
  options,
}: {
  id: string;
  label: string;
  value: number;
  onChange: (value: string) => void;
  options: ReadonlyArray<{ value: string; label: string }>;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
      </label>
      <select
        id={id}
        name={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-10 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  );
}
