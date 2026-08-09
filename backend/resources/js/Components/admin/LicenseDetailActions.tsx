import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";

import { Button } from "@/Components/ui/Button";
import { ApiErrorCode, LaraApiError, laraRequest } from "@/lib/lara-api";
import { readEtag } from "@/lib/lara-etag";

/**
 * Plan 06 step 72. Inertia port of `src/components/admin/license-detail-actions.tsx`,
 * implementing the spec 49 v1.1.0 concurrency-conflict recovery contract.
 *
 * Differences from the SPA original are only in the reload primitive:
 * TanStack's `router.invalidate()` becomes Inertia's
 * `router.reload({ preserveState: true })`, which re-runs the Laravel
 * route (fresh `license` + `etag` props) without unmounting this
 * component, so operator edits in local state survive (spec 49 §3.1).
 */

export interface LicenseRow {
  LicenseId: number;
  LicenseKey: string;
  Status: string;
  TierName: string;
  ProductVersion: string;
  ExpiresAt: string;
  Version: number;
}

interface Props {
  license: LicenseRow;
  resellerSlug: string;
  /**
   * ETag from `GET /Api/Admin/Licenses/{LicenseKey}` (spec
   * 11-api-contracts/09-concurrency-control.md). `null` when the header
   * was absent: mutating without `If-Match` deterministically fails with
   * `PreconditionRequired`, so both controls stay disabled rather than
   * inventing a validator.
   */
  etag: string | null;
}

const STATUS_ACTIVE = "Active";
const STATUS_SUSPENDED = "Suspended";
const STATUS_REVOKED = "Revoked";

/**
 * spec/21-app/48-quota-restore-on-revoke.md §2 step 7: exactly four
 * skip reasons plus the happy path. Rendered explicitly so an operator
 * can tell "seat came back" from "no seat was ever charged".
 */
const SKIPPED_COPY: Record<string, string> = {
  AdminIssued: "Admin-issued license; no reseller quota was charged.",
  ClosedPeriod: "Quota period already closed; the seat cannot be restored.",
  TimeExpired: "License had already expired by time; no quota to restore.",
  AlreadyRestored: "Quota was already restored on a prior revoke.",
};

interface RevokeResult {
  LicenseId: number;
  QuotaRestored?: boolean;
  RestoreSkippedReason?: string | null;
}

function describeRevokeOutcome(result: RevokeResult | undefined): string {
  if (result === undefined) return "License revoked.";
  if (result.QuotaRestored === true) return "Reseller quota restored (+1 seat).";
  const reason = result.RestoreSkippedReason;
  if (reason && SKIPPED_COPY[reason]) return SKIPPED_COPY[reason];
  return `License #${result.LicenseId} revoked.`;
}

function describeError(error: unknown): string {
  if (error instanceof LaraApiError) {
    return `${error.message} (Code ${error.code}, Request ${error.requestId})`;
  }
  return error instanceof Error ? error.message : "Unexpected error.";
}

function isConflict(error: unknown): boolean {
  return error instanceof LaraApiError && error.code === ApiErrorCode.PreconditionFailed;
}

export function LicenseDetailActions({ license, resellerSlug, etag }: Props) {
  const [expiresAt, setExpiresAt] = useState(license.ExpiresAt ?? "");
  const [status, setStatus] = useState(
    license.Status === STATUS_SUSPENDED ? STATUS_SUSPENDED : STATUS_ACTIVE,
  );
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<null | "save" | "revoke">(null);
  const [busy, setBusy] = useState<"none" | "save" | "revoke" | "reload">("none");
  const [confirmRevoke, setConfirmRevoke] = useState(false);
  const reloadRef = useRef<HTMLButtonElement | null>(null);

  /**
   * Plan 06 step 75. The `etag` page prop is produced in `routes/web.php` by
   * reading `ETag` off a controller response that never traversed
   * `EtagMiddleware` (the web stack does not include it), so it is null in
   * practice. Re-read the real API endpoint once on mount: that response DOES
   * pass the middleware, and `lib/lara-etag.ts` captures the strong validator.
   */
  const [liveEtag, setLiveEtag] = useState<string | null>(etag);
  const isRevoked = license.Status === STATUS_REVOKED;
  const effectiveEtag = liveEtag ?? etag;
  const canMutate = typeof effectiveEtag === "string" && effectiveEtag !== "" && !isRevoked;
  const missingEtagMessage =
    "Cannot save or revoke without an ETag; reload the page to fetch the current concurrency token.";
  const query = `?ResellerSlug=${encodeURIComponent(resellerSlug)}`;

  const resourcePath = `/Api/Admin/Licenses/${encodeURIComponent(license.LicenseKey)}${query}`;

  const refreshEtag = useCallback(async () => {
    try {
      await laraRequest(resourcePath);
    } catch {
      // A failed read leaves the cache untouched; the missing-ETag copy below
      // stays visible rather than pretending we hold a validator.
      return;
    }
    setLiveEtag(readEtag(resourcePath));
  }, [resourcePath]);

  useEffect(() => {
    if (isRevoked) return;
    void refreshEtag();
  }, [isRevoked, refreshEtag]);

  /** spec 49 §5 AC-CONFLICT-003: block a second attempt, refocus reload. */
  function blockedByConflict(): boolean {
    if (conflict === null) return false;
    reloadRef.current?.focus();
    return true;
  }

  function reloadLatest() {
    setBusy("reload");
    router.reload({
      only: ["license", "ledger", "etag"],
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        setConflict(null);
        setError(null);
        void refreshEtag();
        toast.message("Loaded latest license. Review your edits, then retry.");
      },
      onFinish: () => setBusy("none"),
    });
  }

  function handleFailure(kind: "save" | "revoke", failure: unknown) {
    setError(describeError(failure));
    if (isConflict(failure)) {
      setConflict(kind);
      // spec 49 §5 AC-CONFLICT-004: no error code, no Request Id in the toast.
      toast.error("This license changed since you loaded it", {
        description: "Reload latest to fetch the fresh version, then retry.",
      });
      return;
    }
    toast.error(kind === "save" ? "Could not save license" : "Could not revoke license", {
      description: describeError(failure),
    });
  }

  async function onSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (blockedByConflict()) return;
    setError(null);
    if (!canMutate) {
      setError(missingEtagMessage);
      toast.error("Could not save license", { description: missingEtagMessage });
      return;
    }
    setBusy("save");
    try {
      const trimmed = expiresAt.trim();
      await laraRequest(resourcePath, {
        method: "PATCH",
        ifMatch: effectiveEtag as string,
        body: { Status: status, ExpiresAt: trimmed === "" ? null : trimmed },
      });
      toast.success("License saved");
      reloadLatest();
    } catch (saveError) {
      handleFailure("save", saveError);
    } finally {
      setBusy("none");
    }
  }

  async function onRevoke() {
    if (blockedByConflict()) return;
    setError(null);
    if (!canMutate) {
      setError(missingEtagMessage);
      toast.error("Could not revoke license", { description: missingEtagMessage });
      return;
    }
    const trimmedReason = reason.trim();
    if (trimmedReason === "") {
      setError("Revoke reason is required (1..512 characters).");
      return;
    }
    setBusy("revoke");
    try {
      const envelope = await laraRequest<RevokeResult>(resourcePath, {
        method: "DELETE",
        ifMatch: effectiveEtag as string,
        body: { Reason: trimmedReason },
      });
      toast.success("License revoked", { description: describeRevokeOutcome(envelope.Results[0]) });
      setConfirmRevoke(false);
      reloadLatest();
    } catch (revokeError) {
      handleFailure("revoke", revokeError);
    } finally {
      setBusy("none");
    }
  }

  const conflictRegion =
    conflict === null ? null : (
      <div
        role="status"
        className="rounded-md border border-amber-500/60 bg-amber-500/10 p-3 text-sm"
      >
        <p className="font-medium">This license changed since you loaded it.</p>
        <p className="mt-1 text-muted-foreground">
          Your edits are preserved. Reload the latest version, review the changes, then retry.
        </p>
        <Button
          ref={reloadRef}
          type="button"
          variant="outline"
          size="sm"
          className="mt-2"
          onClick={reloadLatest}
          disabled={busy !== "none"}
        >
          {busy === "reload" ? "Reloading..." : "Reload latest and retry"}
        </Button>
      </div>
    );

  return (
    <div className="space-y-6">
      <form onSubmit={onSave} className="space-y-4" noValidate>
        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          Update license
        </p>
        <label className="block">
          <span className="mb-1 block text-sm font-medium">Status</span>
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            disabled={!canMutate}
            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
          >
            <option value={STATUS_ACTIVE}>Active</option>
            <option value={STATUS_SUSPENDED}>Suspended</option>
          </select>
        </label>
        <label className="block">
          <span className="mb-1 block text-sm font-medium">
            ExpiresAt (ISO 8601, blank for never)
          </span>
          <input
            value={expiresAt}
            onChange={(e) => setExpiresAt(e.target.value)}
            placeholder="2026-12-31T23:59:59Z"
            disabled={!canMutate}
            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
          />
        </label>
        {error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}
        {conflictRegion}
        {isRevoked ? (
          <p className="text-xs text-muted-foreground">
            This license is revoked; it can no longer be updated.
          </p>
        ) : null}
        {!isRevoked && (effectiveEtag === null || effectiveEtag === "") ? (
          <p className="text-xs text-muted-foreground">{missingEtagMessage}</p>
        ) : null}
        <Button type="submit" disabled={busy !== "none" || !canMutate}>
          {busy === "save" ? "Saving..." : "Save changes"}
        </Button>
      </form>

      <div className="rounded-md border border-destructive/40 p-4">
        <p className="text-sm font-semibold text-destructive">Revoke license</p>
        <p className="mt-1 text-xs text-muted-foreground">
          Revocation is terminal. All serials issued under this license stop verifying.
        </p>
        {confirmRevoke ? (
          <div className="mt-3 space-y-3">
            <label className="block">
              <span className="mb-1 block text-sm font-medium">Reason (required)</span>
              <input
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                maxLength={512}
                placeholder="Chargeback, duplicate issue, ..."
                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              />
            </label>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="destructive"
                size="sm"
                onClick={() => void onRevoke()}
                disabled={busy !== "none" || !canMutate}
              >
                {busy === "revoke" ? "Revoking..." : "Confirm revoke"}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setConfirmRevoke(false)}
              >
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="mt-3 border-destructive text-destructive"
            onClick={() => setConfirmRevoke(true)}
            disabled={!canMutate}
          >
            Revoke license
          </Button>
        )}
      </div>
    </div>
  );
}
