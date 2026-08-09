import { useState, type FormEvent } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { RetryAfterBanner } from "../retry-after-banner";
import { useSubmitLock } from "../../lib/use-submit-lock";
import { formatLaraApiErrorOptional } from "../../lib/lara-api-error";
import { useLaraErrorToast } from "../../lib/use-lara-error-toast";
import {
  adjustQuota,
  quotaAdjustSchema,
  quotaLedgerQueryOptions,
  resellerQuotasQueryOptions,
  type QuotaAdjustInput,
} from "../../lib/lara-quota";
import { LICENSE_CATEGORY_IDS } from "../../lib/lara-license";

const TIER_IDS = [1, 2, 3, 4] as const;

/**
 * Admin-only POST /Resellers/{id}/Quotas/{categoryId}/Adjust form (Step 46).
 * Signed non-zero Delta; Idempotency-Key required (AC-API-QR-004).
 */
export function QuotaAdjustForm({ resellerId }: { resellerId: number }) {
  const [categoryId, setCategoryId] = useState<number>(1);
  const [tierId, setTierId] = useState<number>(1);
  const [delta, setDelta] = useState<string>("1");
  const [reason, setReason] = useState<string>("");
  const [validationError, setValidationError] = useState<string | undefined>();
  const client = useQueryClient();
  const mutation = useMutation({
    mutationFn: (input: QuotaAdjustInput) =>
      adjustQuota(resellerId, categoryId, input, crypto.randomUUID()),
    onSuccess: () => {
      setDelta("1");
      setReason("");
      void client.invalidateQueries({ queryKey: resellerQuotasQueryOptions(resellerId).queryKey });
      void client.invalidateQueries({ queryKey: quotaLedgerQueryOptions(resellerId).queryKey });
    },
  });
  useLaraErrorToast(mutation.error, "Could not adjust quota");
  const apiError = formatLaraApiErrorOptional(mutation.error);
  const submitLock = useSubmitLock(mutation.error);

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setValidationError(undefined);
    const parsed = quotaAdjustSchema.safeParse({
      LicenseTierId: tierId,
      Delta: Number(delta),
      Reason: reason.trim(),
    });
    const isFailed = !parsed.success;
    if (isFailed) {
      setValidationError(parsed.error.issues[0]?.message ?? "Invalid input");

      return;
    }
    mutation.mutate(parsed.data);
  };

  return (
    <form
      onSubmit={onSubmit}
      className="mt-4 grid gap-3 rounded-md border border-border bg-card p-4"
    >
      <p className="text-sm font-medium">Adjust quota</p>
      <div className="grid gap-3 md:grid-cols-4">
        <Field label="CategoryId">
          <select
            value={categoryId}
            onChange={(e) => setCategoryId(Number(e.target.value))}
            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
          >
            {LICENSE_CATEGORY_IDS.map((id) => (
              <option key={id} value={id}>
                {id}
              </option>
            ))}
          </select>
        </Field>
        <Field label="TierId">
          <select
            value={tierId}
            onChange={(e) => setTierId(Number(e.target.value))}
            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
          >
            {TIER_IDS.map((id) => (
              <option key={id} value={id}>
                Tier{id}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Delta (signed, non-zero)">
          <input
            value={delta}
            onChange={(e) => setDelta(e.target.value)}
            inputMode="numeric"
            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
          />
        </Field>
        <Field label="Reason">
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={500}
            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
          />
        </Field>
      </div>
      <RetryAfterBanner error={mutation.error} onRetry={() => mutation.reset()} />
      {validationError ? (
        <p role="alert" className="text-sm text-destructive">
          {validationError}
        </p>
      ) : null}
      {apiError ? (
        <p role="alert" className="text-sm text-destructive">
          {apiError}
        </p>
      ) : null}
      <div>
        <button
          type="submit"
          disabled={mutation.isPending || submitLock.locked}
          aria-disabled={mutation.isPending || submitLock.locked}
          data-submit-locked={submitLock.locked ? "true" : "false"}
          className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
        >
          {submitLock.locked
            ? `Retry in ${submitLock.remainingSeconds}s`
            : mutation.isPending
              ? "Adjusting..."
              : "Apply adjustment"}
        </button>
      </div>
    </form>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium uppercase text-muted-foreground">
        {label}
      </span>
      {children}
    </label>
  );
}
