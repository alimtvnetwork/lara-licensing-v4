import { useQueryClient } from "@tanstack/react-query";
import { createFileRoute, useRouter } from "@tanstack/react-router";
import { AlertTriangle, Lock, RefreshCw } from "lucide-react";
import { useMemo, useState } from "react";

import { PageHeader } from "../../components/shell/PageHeader";
import { useApi, useApiMutation, apiQueryKey } from "../../hooks/use-api";
import { ApiErrorCodeType, LaraApiError, formatLaraApiError } from "../../lib/lara-api-error";
import type { AdminRuntimeConfigUpdateRequest, RuntimeConfigDoc } from "../../generated/api/schema";
import { appToast } from "../../hooks/use-app-toast";

/**
 * Plan 16 Step 53. Admin > Runtime page.
 *
 * Reads via `admin.runtime-config.show` and writes via
 * `admin.runtime-config.update` with `If-Match: <UpdatedAt>` (INV-RM-06,
 * spec/28-runtime-modes/05-admin-runtime-toggle.md §C-01..C-03).
 *
 * Surfaces distinct states:
 *   - 412 PreconditionFailed  -> inline "config changed" banner + refetch
 *     (Toast is banned for this code per hooks/use-app-toast §Routing).
 *   - 423 AllowRuntimeToggle=false -> read-only lock notice, no submit.
 *   - success -> success toast, cache invalidated, diff panel resets.
 *
 * The submit control is gated by an explicit confirmation checkbox (U-04);
 * `AllowRuntimeToggle: false -> true` is disabled at the UI level too (U-05,
 * M-03), and the server also refuses that transition.
 */

export const Route = createFileRoute("/_authenticated/admin/runtime")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Runtime | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: AdminRuntimePage,
});

const RUNTIME_CONFIG_QUERY_KEY = apiQueryKey("admin.runtime-config.show", {});
const LOCKED_MARKER = "LARA_RUNTIME_CONFIG_LOCKED";
const MODE_OPTIONS = ["preview", "dev", "production"] as const;

type Mode = (typeof MODE_OPTIONS)[number];

interface FormState {
  Mode: Mode;
  ApiBaseUrl: string;
  PreviewSeed: string;
  AllowRuntimeToggle: boolean;
}

function docToForm(doc: RuntimeConfigDoc): FormState {
  return {
    Mode: doc.Mode,
    ApiBaseUrl: doc.ApiBaseUrl ?? "",
    PreviewSeed: doc.PreviewSeed ?? "",
    AllowRuntimeToggle: doc.AllowRuntimeToggle,
  };
}

function diffKeys(current: RuntimeConfigDoc, next: FormState): string[] {
  const changes: string[] = [];
  if (current.Mode !== next.Mode) changes.push("Mode");
  if ((current.ApiBaseUrl ?? "") !== next.ApiBaseUrl) changes.push("ApiBaseUrl");
  if ((current.PreviewSeed ?? "") !== next.PreviewSeed) changes.push("PreviewSeed");
  if (current.AllowRuntimeToggle !== next.AllowRuntimeToggle) changes.push("AllowRuntimeToggle");

  return changes;
}

function buildUpdatePayload(form: FormState, ifMatch: string): AdminRuntimeConfigUpdateRequest {
  return {
    IfMatch: ifMatch,
    Mode: form.Mode,
    ApiBaseUrl: form.Mode === "production" ? form.ApiBaseUrl.trim() : null,
    PreviewSeed: form.Mode === "preview" ? form.PreviewSeed.trim() : "",
    AllowRuntimeToggle: form.AllowRuntimeToggle,
  };
}

function isLockedError(err: unknown): boolean {
  if (!(err instanceof LaraApiError)) return false;
  if (err.errorCode === ApiErrorCodeType.RuntimeConfigLocked) return true;

  return (
    err.errorCode === ApiErrorCodeType.AuthForbidden && (err.message ?? "").includes(LOCKED_MARKER)
  );
}

function isConflictError(err: unknown): boolean {
  if (!(err instanceof LaraApiError)) return false;

  return (
    err.errorCode === ApiErrorCodeType.RuntimeConfigConflict ||
    err.errorCode === ApiErrorCodeType.PreconditionFailed
  );
}

function AdminRuntimePage() {
  const router = useRouter();
  const qc = useQueryClient();
  const query = useApi("admin.runtime-config.show", {}, { staleTime: 15_000 });

  return (
    <>
      <PageHeader
        title="Runtime configuration"
        description="Toggle the deployed runtime mode. Writes are If-Match guarded; a stale form re-fetches."
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Runtime" }]}
      />
      <RuntimeBody
        state={query}
        onRefetch={() => {
          void qc.invalidateQueries({ queryKey: RUNTIME_CONFIG_QUERY_KEY });
          void router.invalidate();
        }}
      />
    </>
  );
}

interface RuntimeBodyProps {
  state: ReturnType<typeof useApi<"admin.runtime-config.show">>;
  onRefetch: () => void;
}

function RuntimeBody({ state, onRefetch }: RuntimeBodyProps) {
  if (state.isPending) return <LoadingPanel />;
  if (state.isError || !state.data) return <ErrorPanel error={state.error} onRetry={onRefetch} />;

  return <RuntimeForm doc={state.data} onRefetch={onRefetch} />;
}

function LoadingPanel() {
  return (
    <div
      className="mt-6 h-56 animate-pulse rounded-md border border-border bg-muted"
      aria-label="Loading runtime configuration"
    />
  );
}

interface ErrorPanelProps {
  error: unknown;
  onRetry: () => void;
}

function ErrorPanel({ error, onRetry }: ErrorPanelProps) {
  return (
    <div role="alert" className="mt-6 border-y border-destructive py-6">
      <p className="font-medium">Runtime configuration could not be loaded</p>
      <p className="mt-1 text-sm text-muted-foreground">{formatLaraApiError(error)}</p>
      <button
        type="button"
        onClick={onRetry}
        className="focus-ring mt-4 inline-flex h-9 items-center gap-2 rounded-md border border-input px-3 text-sm font-medium surface-hover"
      >
        <RefreshCw aria-hidden="true" className="size-4" /> Retry
      </button>
    </div>
  );
}

interface RuntimeFormProps {
  doc: RuntimeConfigDoc;
  onRefetch: () => void;
}

function RuntimeForm({ doc, onRefetch }: RuntimeFormProps) {
  const [form, setForm] = useState<FormState>(() => docToForm(doc));
  const [confirmed, setConfirmed] = useState(false);
  const [conflict, setConflict] = useState(false);
  const changes = useMemo(() => diffKeys(doc, form), [doc, form]);
  const locked = !doc.AllowRuntimeToggle;
  const mutation = useApiMutation("admin.runtime-config.update", {
    onSuccess: (next) => onUpdateSuccess(next, doc, setForm, setConfirmed, setConflict, onRefetch),
    onError: (err) => onUpdateError(err, setConflict, onRefetch),
  });

  return (
    <form
      className="mt-6 space-y-6"
      onSubmit={(e) => {
        e.preventDefault();
        if (!confirmed || changes.length === 0 || locked) return;
        mutation.mutate({ params: buildUpdatePayload(form, doc.UpdatedAt) });
      }}
    >
      {locked ? <LockedNotice /> : null}
      {conflict ? <ConflictNotice onRefresh={onRefetch} /> : null}
      <CurrentPanel doc={doc} />
      <EditorPanel
        form={form}
        onChange={setForm}
        locked={locked}
        currentToggle={doc.AllowRuntimeToggle}
      />
      <DiffPanel changes={changes} form={form} doc={doc} />
      <ConfirmRow
        changes={changes}
        confirmed={confirmed}
        setConfirmed={setConfirmed}
        pending={mutation.isPending}
        locked={locked}
      />
    </form>
  );
}

function onUpdateSuccess(
  next: RuntimeConfigDoc,
  _prev: RuntimeConfigDoc,
  setForm: (v: FormState) => void,
  setConfirmed: (v: boolean) => void,
  setConflict: (v: boolean) => void,
  onRefetch: () => void,
) {
  console.info("admin.runtime-config: updated", { ToMode: next.Mode, UpdatedAt: next.UpdatedAt });
  appToast.success("Runtime configuration updated", { description: `Mode is now ${next.Mode}.` });
  setForm(docToForm(next));
  setConfirmed(false);
  setConflict(false);
  onRefetch();
}

function onUpdateError(err: unknown, setConflict: (v: boolean) => void, onRefetch: () => void) {
  console.warn("admin.runtime-config: update failed", { error: err });
  if (isConflictError(err)) {
    setConflict(true);
    onRefetch();

    return;
  }
  if (isLockedError(err)) {
    appToast.warning("Runtime toggle is locked. Redeploy to re-enable.");

    return;
  }
  appToast.fromApiError(err, "Runtime configuration update failed");
}

function CurrentPanel({ doc }: { doc: RuntimeConfigDoc }) {
  return (
    <section aria-labelledby="rc-current" className="surface-elevated rounded-md p-4">
      <h2 id="rc-current" className="mb-3 text-sm font-medium">
        Current
      </h2>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        <FieldRow label="Mode" value={doc.Mode} />
        <FieldRow label="ApiBaseUrl" value={doc.ApiBaseUrl ?? "(null)"} />
        <FieldRow label="PreviewSeed" value={doc.PreviewSeed || "(empty)"} />
        <FieldRow label="AllowRuntimeToggle" value={String(doc.AllowRuntimeToggle)} />
        <FieldRow label="Version" value={doc.Version} />
        <FieldRow label="UpdatedAt (If-Match)" value={doc.UpdatedAt} mono />
      </dl>
    </section>
  );
}

function FieldRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <>
      <dt className="text-muted-foreground">{label}</dt>
      <dd style={mono ? { fontFamily: "var(--font-mono)" } : undefined}>{value}</dd>
    </>
  );
}

interface EditorPanelProps {
  form: FormState;
  onChange: (next: FormState) => void;
  locked: boolean;
  currentToggle: boolean;
}

function EditorPanel({ form, onChange, locked, currentToggle }: EditorPanelProps) {
  const toggleUpDisabled = currentToggle === false; // M-03: cannot re-enable from UI

  return (
    <section aria-labelledby="rc-editor" className="surface-elevated rounded-md p-4">
      <h2 id="rc-editor" className="mb-3 text-sm font-medium">
        Proposed
      </h2>
      <fieldset disabled={locked} className="space-y-4">
        <ModeSelect value={form.Mode} onChange={(Mode) => onChange({ ...form, Mode })} />
        <ApiBaseUrlInput form={form} onChange={onChange} />
        <PreviewSeedInput form={form} onChange={onChange} />
        <ToggleRow form={form} onChange={onChange} disabledUp={toggleUpDisabled} />
      </fieldset>
    </section>
  );
}

function ModeSelect({ value, onChange }: { value: Mode; onChange: (m: Mode) => void }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block text-muted-foreground">Mode</span>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value as Mode)}
        className="focus-ring h-9 rounded-md border border-input bg-background px-2"
      >
        {MODE_OPTIONS.map((m) => (
          <option key={m} value={m}>
            {m}
          </option>
        ))}
      </select>
    </label>
  );
}

function ApiBaseUrlInput({
  form,
  onChange,
}: {
  form: FormState;
  onChange: (n: FormState) => void;
}) {
  const required = form.Mode === "production";

  return (
    <label className="block text-sm">
      <span className="mb-1 block text-muted-foreground">
        ApiBaseUrl {required ? "(required for production)" : "(null unless production)"}
      </span>
      <input
        type="url"
        value={form.ApiBaseUrl}
        disabled={!required}
        onChange={(e) => onChange({ ...form, ApiBaseUrl: e.target.value })}
        className="focus-ring h-9 w-full rounded-md border border-input bg-background px-2 disabled:opacity-50"
        placeholder="https://api.example.com"
      />
    </label>
  );
}

function PreviewSeedInput({
  form,
  onChange,
}: {
  form: FormState;
  onChange: (n: FormState) => void;
}) {
  const required = form.Mode === "preview";

  return (
    <label className="block text-sm">
      <span className="mb-1 block text-muted-foreground">
        PreviewSeed {required ? "(required for preview)" : "(empty unless preview)"}
      </span>
      <input
        type="text"
        value={form.PreviewSeed}
        disabled={!required}
        onChange={(e) => onChange({ ...form, PreviewSeed: e.target.value })}
        className="focus-ring h-9 w-full rounded-md border border-input bg-background px-2 disabled:opacity-50"
        placeholder="default | empty | error"
      />
    </label>
  );
}

interface ToggleRowProps {
  form: FormState;
  onChange: (n: FormState) => void;
  disabledUp: boolean;
}

function ToggleRow({ form, onChange, disabledUp }: ToggleRowProps) {
  return (
    <label className="flex items-start gap-2 text-sm">
      <input
        type="checkbox"
        checked={form.AllowRuntimeToggle}
        disabled={disabledUp && !form.AllowRuntimeToggle}
        onChange={(e) => onChange({ ...form, AllowRuntimeToggle: e.target.checked })}
        className="mt-1"
      />
      <span>
        AllowRuntimeToggle
        <span className="ml-1 text-muted-foreground">
          (M-03: cannot be re-enabled from this UI; requires a deploy.)
        </span>
      </span>
    </label>
  );
}

interface DiffPanelProps {
  changes: string[];
  form: FormState;
  doc: RuntimeConfigDoc;
}

function DiffPanel({ changes, form, doc }: DiffPanelProps) {
  if (changes.length === 0) {
    return <p className="text-sm text-muted-foreground">No pending changes.</p>;
  }

  return (
    <section aria-labelledby="rc-diff" className="rounded-md border border-border bg-muted/40 p-4">
      <h2 id="rc-diff" className="mb-2 text-sm font-medium">
        Pending diff
      </h2>
      <ul className="space-y-1 text-sm" style={{ fontFamily: "var(--font-mono)" }}>
        {changes.map((k) => (
          <li key={k}>
            {k}: <span className="text-destructive">{readCurrent(doc, k)}</span>
            {" -> "}
            <span className="text-primary">{readNext(form, k)}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

function readCurrent(doc: RuntimeConfigDoc, key: string): string {
  if (key === "Mode") return doc.Mode;
  if (key === "ApiBaseUrl") return doc.ApiBaseUrl ?? "(null)";
  if (key === "PreviewSeed") return doc.PreviewSeed || "(empty)";
  if (key === "AllowRuntimeToggle") return String(doc.AllowRuntimeToggle);

  return "";
}

function readNext(form: FormState, key: string): string {
  if (key === "Mode") return form.Mode;
  if (key === "ApiBaseUrl")
    return form.Mode === "production" ? form.ApiBaseUrl || "(empty)" : "(null)";
  if (key === "PreviewSeed")
    return form.Mode === "preview" ? form.PreviewSeed || "(empty)" : "(empty)";
  if (key === "AllowRuntimeToggle") return String(form.AllowRuntimeToggle);

  return "";
}

interface ConfirmRowProps {
  changes: string[];
  confirmed: boolean;
  setConfirmed: (v: boolean) => void;
  pending: boolean;
  locked: boolean;
}

function ConfirmRow({ changes, confirmed, setConfirmed, pending, locked }: ConfirmRowProps) {
  const disabled = locked || changes.length === 0 || !confirmed || pending;

  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={confirmed}
          disabled={locked || changes.length === 0}
          onChange={(e) => setConfirmed(e.target.checked)}
        />
        I have reviewed the diff and want to apply it.
      </label>
      <button
        type="submit"
        disabled={disabled}
        className="focus-ring inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
      >
        {pending ? "Applying..." : "Apply"}
      </button>
    </div>
  );
}

function LockedNotice() {
  return (
    <div
      role="status"
      className="flex items-start gap-2 rounded-md border border-border bg-muted/40 p-3 text-sm"
    >
      <Lock aria-hidden="true" className="mt-0.5 size-4" />
      <div>
        <p className="font-medium">Runtime toggle is locked</p>
        <p className="text-muted-foreground">
          AllowRuntimeToggle is false. The endpoint returns 423 until a deploy re-enables it (spec
          §M-03).
        </p>
      </div>
    </div>
  );
}

function ConflictNotice({ onRefresh }: { onRefresh: () => void }) {
  return (
    <div
      role="alert"
      className="flex items-start gap-2 rounded-md border border-destructive bg-destructive/5 p-3 text-sm"
    >
      <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 text-destructive" />
      <div className="flex-1">
        <p className="font-medium">Configuration changed since you loaded this page</p>
        <p className="text-muted-foreground">
          Another operator saved a newer version. Refresh to load the latest If-Match token, then
          reapply your change.
        </p>
      </div>
      <button
        type="button"
        onClick={onRefresh}
        className="focus-ring inline-flex h-8 items-center gap-2 rounded-md border border-input px-2 surface-hover"
      >
        <RefreshCw aria-hidden="true" className="size-4" /> Refresh
      </button>
    </div>
  );
}
