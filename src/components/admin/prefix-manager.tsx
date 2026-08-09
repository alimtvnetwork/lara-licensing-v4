import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, Trash2, XCircle } from "lucide-react";

import { LineageBadge } from "./lineage-badge";

import { formatLaraApiErrorOptional } from "../../lib/lara-api-error";
import { useLaraErrorToast } from "../../lib/use-lara-error-toast";
import {
  createResellerPrefix,
  deletePrefix,
  prefixCreateSchema,
  resellerPrefixesQueryOptions,
  type Prefix,
  type PrefixCreateInput,
} from "../../lib/lara-prefix";

export function PrefixManager({ resellerId }: { resellerId: number }) {
  const query = useQuery(resellerPrefixesQueryOptions(resellerId));

  return (
    <section className="mt-10 rounded-md border border-border bg-card p-5">
      <header className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold">Prefixes</h2>
          <p className="text-sm text-muted-foreground">
            Serial prefixes scoped to this reseller. 3 to 12 uppercase letters or digits.
          </p>
        </div>
      </header>
      <PrefixCreate resellerId={resellerId} />
      <PrefixListBody
        isPending={query.isPending}
        error={query.error}
        prefixes={query.data}
        resellerId={resellerId}
      />
    </section>
  );
}

function PrefixCreate({ resellerId }: { resellerId: number }) {
  const [value, setValue] = useState("");
  const [validationError, setValidationError] = useState<string | undefined>();
  const queryClient = useQueryClient();
  const mutation = useMutation({
    mutationFn: (input: PrefixCreateInput) =>
      createResellerPrefix(resellerId, input, crypto.randomUUID()),
    onSuccess: () => {
      setValue("");
      void queryClient.invalidateQueries({
        queryKey: resellerPrefixesQueryOptions(resellerId).queryKey,
      });
    },
  });
  useLaraErrorToast(mutation.error, "Could not create prefix");

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setValidationError(undefined);
    const parsed = prefixCreateSchema.safeParse({ PrefixValue: value });
    const isFailed = !parsed.success;
    if (isFailed) {
      setValidationError(parsed.error.issues[0]?.message ?? "Invalid prefix");

      return;
    }
    mutation.mutate(parsed.data);
  };

  return (
    <form onSubmit={onSubmit} className="mt-4 flex flex-wrap items-end gap-3" noValidate>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="PrefixValue" className="text-sm font-medium">
          Prefix value
        </label>
        <input
          id="PrefixValue"
          name="PrefixValue"
          value={value}
          onChange={(event) => setValue(event.target.value)}
          className="h-10 w-48 rounded-md border border-input bg-background px-3 font-mono text-sm uppercase tracking-wider outline-none focus-visible:ring-2 focus-visible:ring-ring"
          placeholder="ACME01"
          autoComplete="off"
          spellCheck={false}
        />
      </div>
      <button
        type="submit"
        disabled={mutation.isPending}
        className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
      >
        {mutation.isPending ? "Adding..." : "Add prefix"}
      </button>
      <ErrorLine message={validationError ?? getError(mutation.error)} />
    </form>
  );
}

interface PrefixListBodyProps {
  isPending: boolean;
  error: Error | null;
  prefixes: Prefix[] | undefined;
  resellerId: number;
}

function PrefixListBody({ isPending, error, prefixes, resellerId }: PrefixListBodyProps) {
  if (isPending) {
    return (
      <div
        className="mt-6 h-24 animate-pulse rounded-md border border-border bg-muted"
        aria-label="Loading prefixes"
      />
    );
  }
  if (error !== null) {
    return (
      <p role="alert" className="mt-6 text-sm text-destructive">
        {getError(error)}
      </p>
    );
  }
  if (prefixes === undefined || prefixes.length === 0) {
    return <p className="mt-6 text-sm text-muted-foreground">No prefixes registered yet.</p>;
  }

  return (
    <ul className="mt-6 divide-y divide-border rounded-md border border-border">
      {prefixes.map((prefix) => (
        <PrefixRow key={prefix.PrefixId} prefix={prefix} resellerId={resellerId} />
      ))}
    </ul>
  );
}

function PrefixRow({ prefix, resellerId }: { prefix: Prefix; resellerId: number }) {
  const queryClient = useQueryClient();
  const [confirming, setConfirming] = useState(false);
  const mutation = useMutation({
    mutationFn: () => deletePrefix(prefix.PrefixId, crypto.randomUUID()),
    onSuccess: () => {
      setConfirming(false);

      return queryClient.invalidateQueries({
        queryKey: resellerPrefixesQueryOptions(resellerId).queryKey,
      });
    },
  });
  useLaraErrorToast(mutation.error, "Could not delete prefix");
  const Icon = prefix.IsActive ? CheckCircle2 : XCircle;

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
      <span className="font-mono font-medium tracking-wider">{prefix.PrefixValue}</span>
      <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
        <Icon aria-hidden="true" className="size-4" />
        {prefix.IsActive ? "Active" : "Inactive"}
      </span>
      {confirming ? (
        <div
          role="group"
          aria-label={`Confirm delete prefix ${prefix.PrefixValue}`}
          data-ui="prefix-delete-confirm"
          className="flex basis-full flex-col gap-2 rounded-md border border-destructive/60 p-3"
        >
          <LineageBadge />
          <p className="text-xs text-muted-foreground">
            Delete prefix &quot;{prefix.PrefixValue}&quot;? Licenses referencing it must already be
            revoked or the request returns PrefixInUse.
          </p>
          <div className="flex justify-end gap-2">
            <button
              type="button"
              disabled={mutation.isPending}
              onClick={() => setConfirming(false)}
              className="inline-flex h-8 items-center rounded-md border border-input px-3 text-xs font-medium disabled:opacity-60"
            >
              Cancel
            </button>
            <button
              type="button"
              disabled={mutation.isPending}
              onClick={() => mutation.mutate()}
              className="inline-flex h-8 items-center gap-1 rounded-md border border-destructive bg-destructive/10 px-3 text-xs font-medium text-destructive hover:bg-destructive/20 disabled:opacity-60"
            >
              <Trash2 aria-hidden="true" className="size-3.5" />
              {mutation.isPending ? "Deleting..." : "Confirm delete"}
            </button>
          </div>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => setConfirming(true)}
          disabled={mutation.isPending}
          aria-label={`Delete prefix ${prefix.PrefixValue}`}
          className="inline-flex h-8 items-center gap-1 rounded-md border border-input px-2 text-xs font-medium text-destructive hover:bg-destructive/10 disabled:opacity-60"
        >
          <Trash2 aria-hidden="true" className="size-3.5" />
          Delete
        </button>
      )}
    </li>
  );
}

function ErrorLine({ message }: { message: string | undefined }) {
  if (message === undefined) return null;

  return (
    <p role="alert" className="basis-full text-sm text-destructive">
      {message}
    </p>
  );
}

const getError = formatLaraApiErrorOptional;
