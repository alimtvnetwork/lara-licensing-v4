import { useMemo, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "@tanstack/react-router";

import { Stepper, type StepperStep } from "../ui/stepper";
import { formatLaraApiErrorOptional } from "../../lib/lara-api-error";
import { useLaraErrorToast } from "../../lib/use-lara-error-toast";
import {
  createResellerPrefix,
  prefixCreateSchema,
  resellerPrefixesQueryOptions,
} from "../../lib/lara-prefix";
import {
  createReseller,
  resellerCreateSchema,
  resellersQueryOptions,
  type ResellerCreateInput,
} from "../../lib/lara-reseller";

/**
 * Plan 09 Step 35: reseller creation as a three-step wizard
 * (Identity -> Prefixes -> Confirm) on top of `src/components/ui/stepper.tsx`.
 *
 * Ordering is forced by the API: prefixes hang off `/Resellers/{id}/Prefixes`,
 * so the reseller POST must land before any prefix POST. The wizard therefore
 * collects prefixes up front and replays them after the create resolves, each
 * with its own Idempotency-Key per the idempotency envelope contract.
 */

const STEPS: readonly StepperStep[] = [
  { id: "identity", label: "Identity", description: "Name and contact" },
  { id: "prefixes", label: "Prefixes", description: "Optional, add later too" },
  { id: "confirm", label: "Confirm", description: "Review and create" },
];

interface IdentityState {
  ResellerName: string;
  ContactEmail: string;
  IsActive: boolean;
}

const INITIAL_IDENTITY: IdentityState = {
  ResellerName: "",
  ContactEmail: "",
  IsActive: true,
};

export function ResellerCreateWizard() {
  const [stepIndex, setStepIndex] = useState(0);
  const [identity, setIdentity] = useState<IdentityState>(INITIAL_IDENTITY);
  const [prefixes, setPrefixes] = useState<readonly string[]>([]);
  const [stepError, setStepError] = useState<string | undefined>();
  const router = useRouter();
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: async (input: ResellerCreateInput) => {
      const created = await createReseller(input, crypto.randomUUID());
      for (const value of prefixes) {
        await createResellerPrefix(created.ResellerId, { PrefixValue: value }, crypto.randomUUID());
      }

      return created;
    },
    onSuccess: (created) => {
      void queryClient.invalidateQueries({ queryKey: resellersQueryOptions.queryKey });
      void queryClient.invalidateQueries({
        queryKey: resellerPrefixesQueryOptions(created.ResellerId).queryKey,
      });
      void router.navigate({ to: "/admin/resellers" });
    },
  });
  useLaraErrorToast(mutation.error, "Could not create reseller");

  const parsedIdentity = useMemo(() => resellerCreateSchema.safeParse(identity), [identity]);

  const goNext = () => {
    setStepError(undefined);
    if (stepIndex === 0 && !parsedIdentity.success) {
      setStepError(parsedIdentity.error.issues[0]?.message ?? "Invalid input");

      return;
    }
    setStepIndex((prev) => Math.min(prev + 1, STEPS.length - 1));
  };

  const goBack = (target?: number) => {
    setStepError(undefined);
    setStepIndex((prev) => Math.max(0, target ?? prev - 1));
  };

  const submit = () => {
    setStepError(undefined);
    const isFailed = !parsedIdentity.success;
    if (isFailed) {
      setStepIndex(0);
      setStepError(parsedIdentity.error.issues[0]?.message ?? "Invalid input");

      return;
    }
    mutation.mutate(parsedIdentity.data);
  };

  return (
    <div className="mt-6 max-w-2xl space-y-8">
      <Stepper
        steps={STEPS}
        activeIndex={stepIndex}
        onStepSelect={(index) => goBack(index)}
        label="Reseller creation progress"
      />

      {stepIndex === 0 ? <IdentityStep state={identity} onChange={setIdentity} /> : null}
      {stepIndex === 1 ? (
        <PrefixStep values={prefixes} onChange={setPrefixes} onError={setStepError} />
      ) : null}
      {stepIndex === 2 ? <ConfirmStep identity={identity} prefixes={prefixes} /> : null}

      <FormError message={stepError ?? formatLaraApiErrorOptional(mutation.error)} />

      <WizardActions
        stepIndex={stepIndex}
        lastIndex={STEPS.length - 1}
        pending={mutation.isPending}
        onBack={() => goBack()}
        onNext={goNext}
        onSubmit={submit}
      />
    </div>
  );
}

function IdentityStep({
  state,
  onChange,
}: {
  state: IdentityState;
  onChange: (next: IdentityState) => void;
}) {
  return (
    <section className="space-y-4" aria-label="Identity">
      <FormField
        id="ResellerName"
        label="Reseller name"
        value={state.ResellerName}
        onChange={(value) => onChange({ ...state, ResellerName: value })}
      />
      <FormField
        id="ContactEmail"
        label="Contact email"
        type="email"
        value={state.ContactEmail}
        onChange={(value) => onChange({ ...state, ContactEmail: value })}
      />
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={state.IsActive}
          onChange={(event) => onChange({ ...state, IsActive: event.target.checked })}
          className="size-4 rounded border-input"
        />
        Active on creation
      </label>
    </section>
  );
}

function PrefixStep({
  values,
  onChange,
  onError,
}: {
  values: readonly string[];
  onChange: (next: readonly string[]) => void;
  onError: (message: string | undefined) => void;
}) {
  const [draft, setDraft] = useState("");

  const add = () => {
    const parsed = prefixCreateSchema.safeParse({ PrefixValue: draft });
    const isFailed = !parsed.success;
    if (isFailed) {
      onError(parsed.error.issues[0]?.message ?? "Invalid prefix");

      return;
    }
    if (values.includes(parsed.data.PrefixValue)) {
      onError("Prefix already queued");

      return;
    }
    onError(undefined);
    onChange([...values, parsed.data.PrefixValue]);
    setDraft("");
  };

  return (
    <section className="space-y-4" aria-label="Prefixes">
      <p className="text-sm text-muted-foreground">
        Prefixes are created immediately after the reseller record. Skip this step to add them later
        from the reseller detail page.
      </p>
      <div className="flex items-end gap-2">
        <div className="flex flex-1 flex-col gap-1.5">
          <label htmlFor="PrefixValue" className="text-sm font-medium">
            Prefix value
          </label>
          <input
            id="PrefixValue"
            name="PrefixValue"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder="3 to 12 uppercase letters or digits"
            className="h-10 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        <button
          type="button"
          onClick={add}
          className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent"
        >
          Add
        </button>
      </div>
      <PrefixList
        values={values}
        onRemove={(value) => onChange(values.filter((v) => v !== value))}
      />
    </section>
  );
}

function PrefixList({
  values,
  onRemove,
}: {
  values: readonly string[];
  onRemove: (value: string) => void;
}) {
  if (values.length === 0) {
    return <p className="text-sm text-muted-foreground">No prefixes queued.</p>;
  }

  return (
    <ul className="flex flex-wrap gap-2">
      {values.map((value) => (
        <li
          key={value}
          className="flex items-center gap-2 rounded-md border border-border px-2 py-1 text-sm"
        >
          <span>{value}</span>
          <button
            type="button"
            onClick={() => onRemove(value)}
            className="text-muted-foreground hover:text-foreground"
          >
            <span className="sr-only">{`Remove prefix ${value}`}</span>
            <span aria-hidden="true">x</span>
          </button>
        </li>
      ))}
    </ul>
  );
}

function ConfirmStep({
  identity,
  prefixes,
}: {
  identity: IdentityState;
  prefixes: readonly string[];
}) {
  return (
    <section className="space-y-3" aria-label="Confirm">
      <dl className="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
        <dt className="text-muted-foreground">Reseller name</dt>
        <dd>{identity.ResellerName}</dd>
        <dt className="text-muted-foreground">Contact email</dt>
        <dd>{identity.ContactEmail}</dd>
        <dt className="text-muted-foreground">Active on creation</dt>
        <dd>{identity.IsActive ? "Yes" : "No"}</dd>
        <dt className="text-muted-foreground">Prefixes</dt>
        <dd>{prefixes.length === 0 ? "None" : prefixes.join(", ")}</dd>
      </dl>
    </section>
  );
}

function WizardActions({
  stepIndex,
  lastIndex,
  pending,
  onBack,
  onNext,
  onSubmit,
}: {
  stepIndex: number;
  lastIndex: number;
  pending: boolean;
  onBack: () => void;
  onNext: () => void;
  onSubmit: () => void;
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
      {stepIndex < lastIndex ? (
        <button
          type="button"
          onClick={onNext}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          Continue
        </button>
      ) : (
        <button
          type="button"
          onClick={onSubmit}
          disabled={pending}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
        >
          {pending ? "Creating..." : "Create reseller"}
        </button>
      )}
    </div>
  );
}

function FormField({
  id,
  label,
  type = "text",
  value,
  onChange,
}: {
  id: string;
  label: string;
  type?: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
      </label>
      <input
        id={id}
        name={id}
        type={type}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="h-10 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
    </div>
  );
}

function FormError({ message }: { message: string | undefined }) {
  if (message === undefined) return null;

  return (
    <p role="alert" className="text-sm text-destructive">
      {message}
    </p>
  );
}
