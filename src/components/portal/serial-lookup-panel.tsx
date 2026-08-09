import { useMutation } from "@tanstack/react-query";
import { useCallback, useEffect, useState } from "react";

import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { Input } from "@/components/ui/input";
import { copyForErrorCode } from "@/lib/error-copy";
import { LaraApiError } from "@/lib/lara-api-error";
import { verifySerial, type VerifySerialResult } from "@/lib/lara-serial";

/**
 * Reusable serial lookup panel per Plan 09 step 50.
 *
 * Extracted from portal.home.tsx so the same panel can be embedded in future
 * surfaces (reseller serial page, admin lookup drawer) without copy-pasting
 * the three-state result rendering, LaraApiError -> copy mapping, and the
 * recent-lookups list. Recent lookups persist to localStorage under the
 * canonical LicensingPortal namespace so a portal user coming back on the
 * same device sees the last 5 serials they checked (no server calls).
 */

const STORAGE_KEY = "LicensingPortal.portalRecentSerials";
const MAX_HISTORY = 5;

interface HistoryEntry {
  readonly Serial: string;
  readonly IsValid: boolean;
  readonly CheckedAt: string;
}

interface PanelState {
  readonly kind: "idle" | "success" | "error";
  readonly result?: VerifySerialResult;
  readonly serial?: string;
  readonly message?: string;
}

export interface SerialLookupPanelProps {
  readonly testIdPrefix?: string;
  readonly initialSerial?: string;
}

function loadHistory(): HistoryEntry[] {
  if (typeof window === "undefined") return [];
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (raw === null) return [];
    const parsed: unknown = JSON.parse(raw);
    if (Array.isArray(parsed) === false) return [];
    const entries: HistoryEntry[] = [];
    for (const item of parsed) {
      if (
        typeof item === "object" &&
        item !== null &&
        typeof (item as HistoryEntry).Serial === "string" &&
        typeof (item as HistoryEntry).IsValid === "boolean" &&
        typeof (item as HistoryEntry).CheckedAt === "string"
      ) {
        entries.push(item as HistoryEntry);
      }
    }

    return entries.slice(0, MAX_HISTORY);
  } catch (error) {
    console.warn("SerialLookupPanel: failed to parse recent-serials storage", error);

    return [];
  }
}

function persistHistory(entries: HistoryEntry[]): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(entries.slice(0, MAX_HISTORY)));
  } catch (error) {
    console.warn("SerialLookupPanel: failed to persist recent-serials storage", error);
  }
}

export function SerialLookupPanel({
  testIdPrefix = "serial-lookup",
  initialSerial = "",
}: SerialLookupPanelProps) {
  const [serial, setSerial] = useState(initialSerial);
  const [state, setState] = useState<PanelState>({ kind: "idle" });
  const [history, setHistory] = useState<HistoryEntry[]>(() => loadHistory());

  const recordHistory = useCallback((entry: HistoryEntry) => {
    setHistory((current) => {
      const deduped = current.filter((row) => row.Serial !== entry.Serial);
      const next = [entry, ...deduped].slice(0, MAX_HISTORY);
      persistHistory(next);

      return next;
    });
  }, []);

  const mutation = useMutation({
    mutationFn: (value: string) => verifySerial(value),
    onSuccess: (result, value) => {
      setState({ kind: "success", result, serial: value });
      recordHistory({
        Serial: value,
        IsValid: result.IsValid,
        CheckedAt: new Date().toISOString(),
      });
    },
    onError: (error, value) => {
      const message =
        error instanceof LaraApiError
          ? copyForErrorCode(error.errorCode)
          : "Verification failed. Try again in a moment.";
      console.error("SerialLookupPanel.verifySerial failed", {
        serial: value,
        errorCode: error instanceof LaraApiError ? error.errorCode : "Unknown",
        status: error instanceof LaraApiError ? error.httpStatus : 0,
      });
      setState({ kind: "error", message, serial: value });
    },
  });

  useEffect(() => {
    if (initialSerial.trim().length > 0) {
      mutation.mutate(initialSerial.trim());
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialSerial]);

  return (
    <div className="flex flex-col gap-4" data-panel="serial-lookup">
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault();
          const trimmed = serial.trim();
          if (trimmed.length === 0) {
            setState({ kind: "error", message: "Enter a serial to verify." });

            return;
          }
          setState({ kind: "idle" });
          mutation.mutate(trimmed);
        }}
      >
        <label className="flex flex-col gap-2 text-sm font-medium">
          <span style={{ fontFamily: "var(--font-sans)" }}>Serial</span>
          <Input
            value={serial}
            onChange={(event) => setSerial(event.target.value)}
            placeholder="XXXX-XXXX-XXXX-XXXX"
            autoComplete="off"
            spellCheck={false}
            aria-invalid={state.kind === "error"}
            data-testid={`${testIdPrefix}-input`}
          />
        </label>
        <div className="flex items-center gap-3">
          <Button
            type="submit"
            disabled={mutation.isPending}
            data-testid={`${testIdPrefix}-submit`}
          >
            {mutation.isPending ? "Verifying..." : "Verify serial"}
          </Button>
        </div>
      </form>
      <ResultPanel state={state} testIdPrefix={testIdPrefix} />
      <HistoryList
        entries={history}
        onReplay={(value) => {
          setSerial(value);
          setState({ kind: "idle" });
          mutation.mutate(value);
        }}
        testIdPrefix={testIdPrefix}
      />
    </div>
  );
}

function ResultPanel({
  state,
  testIdPrefix,
}: {
  readonly state: PanelState;
  readonly testIdPrefix: string;
}) {
  if (state.kind === "idle") {
    return (
      <EmptyState
        preset="search"
        headline="No serial verified yet"
        body="Verification results appear here after you submit a serial."
      />
    );
  }
  if (state.kind === "error") {
    return (
      <div
        role="alert"
        className="rounded-lg border border-destructive/40 bg-[color-mix(in_oklab,var(--color-destructive)_8%,transparent)] p-4 text-sm text-destructive"
        data-testid={`${testIdPrefix}-error`}
        style={{ fontFamily: "var(--font-sans)" }}
      >
        {state.message ?? "Verification failed."}
      </div>
    );
  }
  const result = state.result!;
  const tone = result.IsValid ? "success" : "destructive";

  return (
    <div
      className={`rounded-lg border p-4 text-sm ${tone === "success" ? "border-success/40 bg-[color-mix(in_oklab,var(--color-success)_8%,transparent)] text-foreground" : "border-destructive/40 bg-[color-mix(in_oklab,var(--color-destructive)_8%,transparent)] text-destructive"}`}
      data-testid={`${testIdPrefix}-result`}
      data-tone={tone}
      style={{ fontFamily: "var(--font-sans)" }}
    >
      <div className="text-base font-semibold">
        {result.IsValid ? "Serial is authorized" : "Serial is not authorized"}
      </div>
      <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
        <dt className="text-muted-foreground">Category</dt>
        <dd>{result.Category}</dd>
        <dt className="text-muted-foreground">Single-use</dt>
        <dd>{result.IsSingleUse ? "Yes" : "No"}</dd>
        {typeof result.ExpiresAt === "string" ? (
          <>
            <dt className="text-muted-foreground">Expires</dt>
            <dd>{new Date(result.ExpiresAt).toLocaleString()}</dd>
          </>
        ) : null}
      </dl>
    </div>
  );
}

function HistoryList({
  entries,
  onReplay,
  testIdPrefix,
}: {
  readonly entries: HistoryEntry[];
  readonly onReplay: (serial: string) => void;
  readonly testIdPrefix: string;
}) {
  if (entries.length === 0) return null;

  return (
    <section
      aria-labelledby={`${testIdPrefix}-history-label`}
      className="flex flex-col gap-2 rounded-lg border border-border bg-surface p-4"
      data-testid={`${testIdPrefix}-history`}
    >
      <h2
        id={`${testIdPrefix}-history-label`}
        className="text-sm font-semibold"
        style={{ fontFamily: "var(--font-display)" }}
      >
        Recent lookups
      </h2>
      <ul className="flex flex-col divide-y divide-border">
        {entries.map((entry) => (
          <li key={entry.Serial} className="flex items-center justify-between gap-3 py-2 text-xs">
            <div className="flex flex-col">
              <span className="font-mono">{entry.Serial}</span>
              <span className="text-muted-foreground">
                {entry.IsValid ? "Authorized" : "Not authorized"} -{" "}
                {new Date(entry.CheckedAt).toLocaleString()}
              </span>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => onReplay(entry.Serial)}
              data-testid={`${testIdPrefix}-history-replay`}
            >
              Recheck
            </Button>
          </li>
        ))}
      </ul>
    </section>
  );
}
