import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { formatLaraApiError } from "../../lib/lara-api-error";
import { RetryAfterBanner } from "../retry-after-banner";
import { useSubmitLock } from "../../lib/use-submit-lock";

import {
  createSerial,
  serialCreateSchema,
  type SerialCreateInput,
  type SerialCreateResult,
} from "../../lib/lara-serial";

const INPUT = "h-9 w-full rounded-md border border-input bg-background px-3 text-sm";

// Per spec/21-app/11-api-contracts/02-license-contracts.md §Idempotency:
// - Key is ULID or opaque, 16-128 chars.
// - TTL is exactly 24 hours from CreatedAt.
// - Replay with same key AND matching request body returns the stored response.
// - Reuse with a different body returns 409 IdempotencyConflict.
const IDEMPOTENCY_KEY_MIN = 16;
const IDEMPOTENCY_KEY_MAX = 128;
const IDEMPOTENCY_TTL_HOURS = 24;

interface Props {
  licenseId: number;
}

const describeError = formatLaraApiError;

function parseInput(prefixIdText: string, randomLengthText: string): SerialCreateInput {
  const input: SerialCreateInput = {};
  const trimmedPrefix = prefixIdText.trim();
  if (trimmedPrefix !== "") input.PrefixId = Number(trimmedPrefix);
  if (randomLengthText !== "") {
    const parsed = Number(randomLengthText);
    if (parsed === 16 || parsed === 24 || parsed === 32) input.RandomLength = parsed;
  }

  return serialCreateSchema.parse(input);
}

function generateUlid(): string {
  // Crockford base32 ULID. crypto.randomUUID() is available in all supported runtimes;
  // we fall back to it when the ULID generator cannot be initialized.
  const encoding = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";
  const time = Date.now();
  let timePart = "";
  let t = time;
  for (let i = 0; i < 10; i++) {
    timePart = encoding[t % 32] + timePart;
    t = Math.floor(t / 32);
  }
  const random = new Uint8Array(16);
  crypto.getRandomValues(random);
  let randomPart = "";
  for (let i = 0; i < 16; i++) randomPart += encoding[random[i] % 32];

  return timePart + randomPart;
}

function validateIdempotencyKey(raw: string): string | null {
  if (raw === "") return null;
  if (raw.length < IDEMPOTENCY_KEY_MIN || raw.length > IDEMPOTENCY_KEY_MAX) {
    return `Idempotency-Key must be ${IDEMPOTENCY_KEY_MIN}-${IDEMPOTENCY_KEY_MAX} characters.`;
  }

  return null;
}

export function SerialIssueForm({ licenseId }: Props) {
  const [prefixId, setPrefixId] = useState("");
  const [randomLength, setRandomLength] = useState("");
  const [idempotencyKey, setIdempotencyKey] = useState("");
  const [serial, setSerial] = useState<SerialCreateResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errorRaw, setErrorRaw] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState(false);

  const trimmedKey = idempotencyKey.trim();
  const keyLengthError = validateIdempotencyKey(trimmedKey);
  const submitLock = useSubmitLock(errorRaw);

  function handleGenerateKey() {
    setIdempotencyKey(generateUlid());
  }

  async function submitCore() {
    setError(null);
    setErrorRaw(null);
    if (keyLengthError) {
      setError(keyLengthError);
      toast.error("Invalid Idempotency-Key", { description: keyLengthError });

      return;
    }
    setSubmitting(true);
    try {
      const input = parseInput(prefixId, randomLength);
      const created = await createSerial(
        licenseId,
        input,
        trimmedKey === "" ? undefined : trimmedKey,
      );
      setSerial(created);
    } catch (submitError) {
      const description = describeError(submitError);
      setError(description);
      setErrorRaw(submitError);
      toast.error("Could not issue serial", { description });
    } finally {
      setSubmitting(false);
    }
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await submitCore();
  }

  if (serial) {
    return (
      <div className="rounded-md border border-border bg-muted/40 p-4">
        <p className="text-xs font-medium text-muted-foreground">SERIAL ISSUED</p>
        <p className="mt-2 font-mono text-sm break-all">{serial.SerialValue}</p>
        <p className="mt-1 text-xs text-muted-foreground">
          Serial #{serial.SerialId} at {serial.CreatedAt}
        </p>
        {trimmedKey !== "" ? (
          <p className="mt-2 text-xs text-muted-foreground">
            Idempotency-Key <span className="font-mono">{trimmedKey}</span> is reserved for{" "}
            {IDEMPOTENCY_TTL_HOURS} h. Replaying it with the same body returns this exact response;
            a different body will return 409 IdempotencyConflict.
          </p>
        ) : null}
        <button
          type="button"
          onClick={() => {
            setSerial(null);
            setPrefixId("");
            setRandomLength("");
            setIdempotencyKey("");
            setError(null);
          }}
          className="mt-3 inline-flex h-8 items-center rounded-md border border-input px-3 text-xs font-medium hover:bg-accent"
        >
          Issue another serial
        </button>
      </div>
    );
  }

  return (
    <form
      onSubmit={onSubmit}
      className="space-y-3 rounded-md border border-border bg-muted/30 p-4"
      noValidate
    >
      <p className="text-xs font-medium text-muted-foreground">
        ISSUE SERIAL FOR LICENSE #{licenseId}
      </p>
      <label className="block text-sm">
        <span className="mb-1 block font-medium">PrefixId (optional)</span>
        <input
          value={prefixId}
          onChange={(e) => setPrefixId(e.target.value)}
          inputMode="numeric"
          className={INPUT}
        />
      </label>
      <label className="block text-sm">
        <span className="mb-1 block font-medium">RandomLength</span>
        <select
          value={randomLength}
          onChange={(e) => setRandomLength(e.target.value)}
          className={INPUT}
        >
          <option value="">Server default</option>
          <option value="16">16</option>
          <option value="24">24</option>
          <option value="32">32</option>
        </select>
      </label>
      <div className="block text-sm">
        <div className="mb-1 flex items-center justify-between gap-2">
          <span className="font-medium">Idempotency-Key (optional)</span>
          <button
            type="button"
            onClick={handleGenerateKey}
            className="inline-flex h-7 items-center rounded-md border border-input px-2 text-xs font-medium hover:bg-accent"
          >
            Generate ULID
          </button>
        </div>
        <input
          value={idempotencyKey}
          onChange={(e) => setIdempotencyKey(e.target.value)}
          minLength={IDEMPOTENCY_KEY_MIN}
          maxLength={IDEMPOTENCY_KEY_MAX}
          placeholder={`ULID or opaque token, ${IDEMPOTENCY_KEY_MIN}-${IDEMPOTENCY_KEY_MAX} chars`}
          className={INPUT}
          aria-invalid={keyLengthError !== null}
          aria-describedby="idempotency-help"
        />
        <p id="idempotency-help" className="mt-1 text-xs text-muted-foreground">
          Optional. Reserved for {IDEMPOTENCY_TTL_HOURS} h from first use. Replay with the same key
          and same body returns the original serial verbatim. Replay with the same key and a
          different body returns 409 IdempotencyConflict and counts toward the abuse bucket.
        </p>
        {keyLengthError ? (
          <p role="alert" className="mt-1 text-xs text-destructive">
            {keyLengthError}
          </p>
        ) : null}
      </div>
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
        disabled={submitting || keyLengthError !== null || submitLock.locked}
        aria-disabled={submitting || keyLengthError !== null || submitLock.locked}
        data-submit-locked={submitLock.locked ? "true" : "false"}
        className="inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
      >
        {submitLock.locked
          ? `Retry in ${submitLock.remainingSeconds}s`
          : submitting
            ? "Issuing..."
            : "Issue serial"}
      </button>
    </form>
  );
}
