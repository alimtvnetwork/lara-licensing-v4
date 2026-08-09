import { useState, type FormEvent } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "@tanstack/react-router";
import { Trash2 } from "lucide-react";

import { formatLaraApiErrorOptional } from "../../lib/lara-api-error";
import { useLaraErrorToast } from "../../lib/use-lara-error-toast";
import {
  deleteReseller,
  resellerQueryOptions,
  resellerUpdateSchema,
  resellersQueryOptions,
  updateReseller,
  type Reseller,
  type ResellerUpdateInput,
} from "../../lib/lara-reseller";
import { LineageBadge } from "./lineage-badge";

interface FormState {
  ResellerName: string;
  ContactEmail: string;
  IsActive: boolean;
}

/**
 * Plan 09 Step 22 fanout: the delete control now uses an inline confirm
 * block that mounts <LineageBadge /> so the operator sees the acting
 * principal at the moment they authorize a destructive tenant mutation.
 * Root cause of the previous gap: window.confirm() cannot render React
 * children, so no Spec 24 §7.5 lineage signal was possible on this
 * surface, and an impersonated Admin could hard-affect a reseller with
 * no on-screen audit signal when the sticky banner scrolled off screen.
 */
export function ResellerEditForm({ reseller }: { reseller: Reseller }) {
  const [state, setState] = useState<FormState>({
    ResellerName: reseller.ResellerName,
    ContactEmail: reseller.ContactEmail,
    IsActive: reseller.IsActive,
  });
  const [validationError, setValidationError] = useState<string | undefined>();
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const router = useRouter();
  const queryClient = useQueryClient();

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: resellersQueryOptions.queryKey });
    void queryClient.invalidateQueries({
      queryKey: resellerQueryOptions(reseller.ResellerId).queryKey,
    });
  };

  const updateMutation = useMutation({
    mutationFn: (input: ResellerUpdateInput) =>
      updateReseller(reseller.ResellerId, input, crypto.randomUUID()),
    onSuccess: () => invalidate(),
  });

  const deleteMutation = useMutation({
    mutationFn: () => deleteReseller(reseller.ResellerId, crypto.randomUUID()),
    onSuccess: () => {
      invalidate();
      void router.navigate({ to: "/admin/resellers" });
    },
  });
  useLaraErrorToast(updateMutation.error, "Could not update reseller");
  useLaraErrorToast(deleteMutation.error, "Could not delete reseller");

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setValidationError(undefined);
    const parsed = resellerUpdateSchema.safeParse(state);
    const isFailed = !parsed.success;
    if (isFailed) {
      setValidationError(parsed.error.issues[0]?.message ?? "Invalid input");

      return;
    }
    updateMutation.mutate(parsed.data);
  };

  return (
    <form onSubmit={onSubmit} className="mt-6 space-y-4" noValidate>
      <Field
        id="ResellerName"
        label="Reseller name"
        value={state.ResellerName}
        onChange={(v) => setState((p) => ({ ...p, ResellerName: v }))}
      />
      <Field
        id="ContactEmail"
        label="Contact email"
        type="email"
        value={state.ContactEmail}
        onChange={(v) => setState((p) => ({ ...p, ContactEmail: v }))}
      />
      <ActiveToggle
        checked={state.IsActive}
        onChange={(v) => setState((p) => ({ ...p, IsActive: v }))}
      />
      <ErrorLine
        message={
          validationError ?? getError(updateMutation.error) ?? getError(deleteMutation.error)
        }
      />
      <Actions
        savePending={updateMutation.isPending}
        deletePending={deleteMutation.isPending}
        confirmingDelete={confirmingDelete}
        onRequestDelete={() => setConfirmingDelete(true)}
        onCancelDelete={() => setConfirmingDelete(false)}
        onConfirmDelete={() => deleteMutation.mutate()}
        resellerName={reseller.ResellerName}
      />
    </form>
  );
}

interface FieldProps {
  id: string;
  label: string;
  type?: string;
  value: string;
  onChange: (value: string) => void;
}

function Field({ id, label, type = "text", value, onChange }: FieldProps) {
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
        onChange={(e) => onChange(e.target.value)}
        className="h-10 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
    </div>
  );
}

function ActiveToggle({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="flex items-center gap-2 text-sm">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="size-4 rounded border-input"
      />
      Active
    </label>
  );
}

function ErrorLine({ message }: { message: string | undefined }) {
  if (message === undefined) return null;

  return (
    <p role="alert" className="text-sm text-destructive">
      {message}
    </p>
  );
}

interface ActionsProps {
  savePending: boolean;
  deletePending: boolean;
  confirmingDelete: boolean;
  onRequestDelete: () => void;
  onCancelDelete: () => void;
  onConfirmDelete: () => void;
  resellerName: string;
}

function Actions(props: ActionsProps) {
  const { savePending, deletePending, confirmingDelete } = props;

  return (
    <div className="flex flex-col gap-3 pt-2">
      <div className="flex flex-wrap items-center gap-3">
        <button
          type="submit"
          disabled={savePending || deletePending}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
        >
          {savePending ? "Saving..." : "Save changes"}
        </button>
        {!confirmingDelete ? (
          <button
            type="button"
            onClick={props.onRequestDelete}
            disabled={savePending || deletePending}
            className="inline-flex h-10 items-center gap-2 rounded-md border border-destructive px-4 text-sm font-medium text-destructive hover:bg-destructive/10 disabled:opacity-60"
          >
            <Trash2 aria-hidden="true" className="size-4" />
            Delete reseller
          </button>
        ) : null}
      </div>
      {confirmingDelete ? (
        <div
          role="group"
          aria-label="Confirm delete reseller"
          data-ui="reseller-delete-confirm"
          className="flex flex-col items-start gap-2 rounded-md border border-destructive/60 p-3"
        >
          <LineageBadge />
          <p className="text-xs text-muted-foreground">
            Deletes reseller &quot;{props.resellerName}&quot;. This cannot be undone.
          </p>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={deletePending}
              onClick={props.onCancelDelete}
              className="inline-flex h-9 items-center rounded-md border px-3 text-sm font-medium disabled:opacity-60"
            >
              Cancel
            </button>
            <button
              type="button"
              disabled={deletePending}
              onClick={props.onConfirmDelete}
              className="inline-flex h-9 items-center gap-2 rounded-md border border-destructive/60 bg-destructive/10 px-3 text-sm font-medium text-destructive hover:bg-destructive/20 disabled:opacity-60"
            >
              <Trash2 aria-hidden="true" className="size-4" />
              {deletePending ? "Deleting..." : "Confirm delete"}
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

const getError = formatLaraApiErrorOptional;
