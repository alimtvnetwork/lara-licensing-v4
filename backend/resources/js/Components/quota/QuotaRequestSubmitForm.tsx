// Plan 06 step 69. Reseller quota-request submit form.
//
// Ported from src/components/quota/quota-request-submit-form.tsx. Field limits
// are mirrored from App\Http\Requests\Reseller\QuotaRequestStoreRequest
// (RequestedDelta 1..100000, Justification 8..1024) so the obvious rejections
// happen before the round trip; the server remains the authority and its
// ValidationFailed envelope is surfaced verbatim when it disagrees.
//
// The Idempotency-Key is minted by lara-api.ts as exactly 32 hex characters,
// which is what Reseller\QuotaRequestController::requireIdempotencyKey demands.

import * as React from "react";
import { router } from "@inertiajs/react";

import { Button } from "@/Components/ui/Button";
import { laraRequest, LaraApiError } from "@/lib/lara-api";
import { licenseCategoryOptions, licenseTierOptions } from "@/lib/closed-sets";
import {
  assertQuotaPreflight,
  QuotaPreflightError,
  type QuotaPreflightRow,
} from "@/lib/quotaPreflight";

const DELTA_MIN = 1;
const DELTA_MAX = 100000;
const JUSTIFICATION_MIN = 8;
const JUSTIFICATION_MAX = 1024;

// Plan 06 step 80: `quotas` are the shard-bound rows already rendered on the
// reseller dashboard, passed through by routes/web.php. They drive the
// preflight in lib/quotaPreflight.ts so an unprovisioned (category, tier)
// tuple is refused before the mutating round trip pins an Idempotency-Key.
export function QuotaRequestSubmitForm({ quotas = [] }: { quotas?: QuotaPreflightRow[] }) {
  const [categoryId, setCategoryId] = React.useState<number>(licenseCategoryOptions[0]?.value ?? 1);
  const [tierId, setTierId] = React.useState<number>(licenseTierOptions[0]?.value ?? 1);
  const [delta, setDelta] = React.useState<string>("10");
  const [justification, setJustification] = React.useState<string>("");
  const [error, setError] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);

  const submit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setNotice(null);
    const parsedDelta = Number.parseInt(delta, 10);
    if (!Number.isInteger(parsedDelta) || parsedDelta < DELTA_MIN || parsedDelta > DELTA_MAX) {
      setError(`Requested delta must be an integer between ${DELTA_MIN} and ${DELTA_MAX}.`);
      return;
    }
    if (justification.trim().length < JUSTIFICATION_MIN) {
      setError(`Justification must be at least ${JUSTIFICATION_MIN} characters.`);
      return;
    }
    try {
      const decision = assertQuotaPreflight(quotas, categoryId, tierId, "request");
      if (decision.Outcome === "Allowed") {
        setNotice(`${decision.Remaining} license(s) remaining before this request.`);
      } else if (decision.Outcome === "Warned") {
        setNotice(decision.Message);
      }
    } catch (cause) {
      setError(
        cause instanceof QuotaPreflightError
          ? `${cause.code}: ${cause.message}`
          : "Quota preflight failed.",
      );
      return;
    }
    setBusy(true);
    try {
      await laraRequest("/Api/Reseller/QuotaRequests", {
        method: "POST",
        body: {
          LicenseCategoryId: categoryId,
          LicenseTierId: tierId,
          RequestedDelta: parsedDelta,
          Justification: justification.trim(),
        },
      });
      setJustification("");
      router.reload();
    } catch (cause) {
      setError(
        cause instanceof LaraApiError
          ? `${cause.code}: ${cause.message} (request ${cause.requestId})`
          : "Quota request submission failed.",
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <form
      onSubmit={submit}
      className="border-border bg-card grid gap-4 rounded-lg border p-4 sm:grid-cols-2"
    >
      <div className="sm:col-span-2">
        <h2 className="text-base font-semibold">Request more allowance</h2>
        <p className="text-muted-foreground text-sm">
          An Admin reviews every request; approval increases your granted allowance for the selected
          category and tier.
        </p>
      </div>
      <label className="flex flex-col gap-1 text-sm">
        <span className="font-medium">Category</span>
        <select
          value={categoryId}
          onChange={(event) => setCategoryId(Number.parseInt(event.target.value, 10))}
          className="border-input bg-background h-9 rounded-md border px-3 text-sm"
        >
          {licenseCategoryOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="font-medium">Tier</span>
        <select
          value={tierId}
          onChange={(event) => setTierId(Number.parseInt(event.target.value, 10))}
          className="border-input bg-background h-9 rounded-md border px-3 text-sm"
        >
          {licenseTierOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="font-medium">Additional licenses</span>
        <input
          type="number"
          min={DELTA_MIN}
          max={DELTA_MAX}
          value={delta}
          onChange={(event) => setDelta(event.target.value)}
          className="border-input bg-background h-9 rounded-md border px-3 text-sm"
        />
      </label>
      <label className="flex flex-col gap-1 text-sm sm:col-span-2">
        <span className="font-medium">Justification</span>
        <textarea
          rows={3}
          minLength={JUSTIFICATION_MIN}
          maxLength={JUSTIFICATION_MAX}
          value={justification}
          onChange={(event) => setJustification(event.target.value)}
          placeholder="Why do you need this allowance?"
          className="border-input bg-background rounded-md border px-3 py-2 text-sm"
        />
      </label>
      {notice !== null && error === null && (
        <p role="status" className="text-muted-foreground text-sm sm:col-span-2">
          {notice}
        </p>
      )}
      {error !== null && (
        <p role="alert" className="text-destructive text-sm sm:col-span-2">
          {error}
        </p>
      )}
      <div className="sm:col-span-2">
        <Button type="submit" disabled={busy}>
          {busy ? "Submitting..." : "Submit request"}
        </Button>
      </div>
    </form>
  );
}
