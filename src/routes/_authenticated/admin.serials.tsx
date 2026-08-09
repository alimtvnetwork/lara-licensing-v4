import { useState, type FormEvent } from "react";
import { createFileRoute } from "@tanstack/react-router";

import { PageHeader } from "../../components/shell/PageHeader";
import { formatLaraApiError } from "../../lib/lara-api-error";
import { lookupSerial } from "../../lib/lara-serial";
// Plan 16 step 68: type-only consumer of the real-BE barrel. Runtime call
// still goes through `requestLaraApi` inside `lookupSerial`; only the type
// import is routed through `@/generated/api/real-be-schema` to pin the
// barrel as a load-bearing surface. See tests/real-be-schema-consumer.test.ts.
import type { SerialLookup } from "@/generated/api/real-be-schema";

export const Route = createFileRoute("/_authenticated/admin/serials")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Serial lookup | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: SerialLookupPage,
});

const INPUT_CLASS = "h-9 w-full rounded-md border border-input bg-background px-3 text-sm";
const SUBMIT_CLASS =
  "focus-ring inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60";
const CARD_CLASS = "rounded-md border border-border bg-card p-6";

function SerialLookupPage() {
  const [value, setValue] = useState("");
  const [result, setResult] = useState<SerialLookup | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setResult(null);
    setSubmitting(true);
    try {
      setResult(await lookupSerial(value.trim()));
    } catch (lookupError) {
      setError(formatLaraApiError(lookupError));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <PageHeader
        title="Serial lookup"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Serial lookup" }]}
        description="Submits GET /Serials/{SerialValue}."
      />
      <SerialForm
        value={value}
        onChange={setValue}
        onSubmit={onSubmit}
        submitting={submitting}
        error={error}
      />
      {result ? <SerialResultCard result={result} /> : null}
    </>
  );
}

interface SerialFormProps {
  value: string;
  onChange: (v: string) => void;
  onSubmit: (e: FormEvent<HTMLFormElement>) => void;
  submitting: boolean;
  error: string | null;
}

function SerialForm(props: SerialFormProps) {
  return (
    <form onSubmit={props.onSubmit} className={`${CARD_CLASS} max-w-3xl space-y-3`} noValidate>
      <label className="block text-sm">
        <span className="mb-1 block font-medium">Serial value</span>
        <input
          value={props.value}
          onChange={(e) => props.onChange(e.target.value)}
          required
          className={INPUT_CLASS}
        />
      </label>
      {props.error ? (
        <p role="alert" className="text-sm text-destructive">
          {props.error}
        </p>
      ) : null}
      <button
        type="submit"
        disabled={props.submitting || props.value.trim() === ""}
        className={SUBMIT_CLASS}
      >
        {props.submitting ? "Looking up..." : "Look up serial"}
      </button>
    </form>
  );
}

function SerialResultCard({ result }: { result: SerialLookup }) {
  return (
    <div className={`${CARD_CLASS} max-w-3xl`}>
      <p className="text-xs font-medium text-muted-foreground">SERIAL</p>
      <p className="mt-2 font-mono text-sm break-all">{result.SerialValue}</p>
      <dl className="mt-4 grid grid-cols-2 gap-2 text-sm">
        <dt className="text-muted-foreground">Serial id</dt>
        <dd>{result.SerialId}</dd>
        <dt className="text-muted-foreground">License id</dt>
        <dd>{result.LicenseId}</dd>
        <dt className="text-muted-foreground">Revoked</dt>
        <dd>{result.IsRevoked ? "Yes" : "No"}</dd>
        <dt className="text-muted-foreground">Created at</dt>
        <dd>{result.CreatedAt}</dd>
      </dl>
    </div>
  );
}
