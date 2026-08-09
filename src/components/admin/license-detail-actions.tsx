import { useState, type FormEvent } from "react";
import { useRouter } from "@tanstack/react-router";
import { toast } from "sonner";

import { SerialIssueForm } from "./serial-issue-form";
import { LineageBadge } from "./lineage-badge";

import { ApiErrorCodeType, formatLaraApiError, LaraApiError } from "../../lib/lara-api-error";
import {
  deleteLicense,
  updateLicense,
  type License,
  type LicenseDeleteResult,
  type LicenseRestoreSkippedReason,
} from "../../lib/lara-license";

interface Props {
  license: License;
  /**
   * ETag captured from `GET /Licenses/{LicenseId}` (see
   * spec/21-app/11-api-contracts/09-concurrency-control.md §Request
   * rules). `undefined` when the server omitted the header; in that
   * case Save and Revoke are disabled because sending a mutating
   * request without `If-Match` is guaranteed to fail with
   * `428 PreconditionRequired`, and inventing a value would defeat the
   * concurrency guarantee.
   */
  etag: string | undefined;
}

const describeError = formatLaraApiError;

/**
 * Human copy for the revoke toast. spec/21-app/48-quota-restore-on-revoke.md
 * §2 step 7 defines exactly four `RestoreSkippedReason` values plus the
 * happy path (`QuotaRestored = true`). We render each explicitly instead
 * of falling through to a generic string so operators can distinguish
 * "seat came back" from "admin issue, no seat charged" at a glance.
 */
const SKIPPED_COPY: Record<LicenseRestoreSkippedReason, string> = {
  AdminIssued: "Admin-issued license; no reseller quota was charged.",
  ClosedPeriod: "Quota period already closed; the seat cannot be restored.",
  TimeExpired: "License had already expired by time; no quota to restore.",
  AlreadyRestored: "Quota was already restored on a prior revoke.",
};

function describeRevokeOutcome(result: LicenseDeleteResult): string {
  if (result.QuotaRestored === true) return "Reseller quota restored (+1 seat).";
  if (result.RestoreSkippedReason !== undefined) return SKIPPED_COPY[result.RestoreSkippedReason];

  return `License #${result.LicenseId} revoked.`;
}

export function LicenseDetailActions({ license, etag }: Props) {
  const router = useRouter();
  const [expiresAt, setExpiresAt] = useState(license.ExpiresAt ?? "");
  const [isActive, setIsActive] = useState(license.IsActive);
  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<null | "save" | "revoke">(null);
  const [busy, setBusy] = useState<"none" | "save" | "revoke" | "reload">("none");
  const [confirmRevoke, setConfirmRevoke] = useState(false);
  const canMutate = typeof etag === "string";
  const missingEtagMessage =
    "Cannot save or revoke without an ETag; reload the page to fetch the current concurrency token.";

  function isConflict(err: unknown): boolean {
    return err instanceof LaraApiError && err.errorCode === ApiErrorCodeType.PreconditionFailed;
  }

  async function reloadLatest() {
    setBusy("reload");
    try {
      await router.invalidate();
      setConflict(null);
      setError(null);
      toast.message("Loaded latest license. Review your edits, then retry.");
    } finally {
      setBusy("none");
    }
  }

  async function onSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setConflict(null);
    if (typeof etag !== "string") {
      setError(missingEtagMessage);
      toast.error("Could not save license", { description: missingEtagMessage });

      return;
    }
    setBusy("save");
    try {
      const trimmed = expiresAt.trim();
      await updateLicense(
        license.LicenseId,
        {
          IsActive: isActive,
          ExpiresAt: trimmed === "" ? null : trimmed,
        },
        crypto.randomUUID(),
        etag,
      );
      await router.invalidate();
    } catch (saveError) {
      const description = describeError(saveError);
      setError(description);
      if (isConflict(saveError)) {
        setConflict("save");
        toast.error("License changed while you were editing", {
          description: "Reload latest to fetch the fresh version, then retry your save.",
        });
      } else {
        toast.error("Could not save license", { description });
      }
    } finally {
      setBusy("none");
    }
  }

  async function onRevoke() {
    setError(null);
    setConflict(null);
    if (typeof etag !== "string") {
      setError(missingEtagMessage);
      toast.error("Could not revoke license", { description: missingEtagMessage });

      return;
    }
    setBusy("revoke");
    try {
      const result = await deleteLicense(license.LicenseId, crypto.randomUUID(), etag);
      await router.invalidate();
      toast.success("License revoked", { description: describeRevokeOutcome(result) });
    } catch (revokeError) {
      const description = describeError(revokeError);
      setError(description);
      if (isConflict(revokeError)) {
        setConflict("revoke");
        toast.error("License changed while you were editing", {
          description: "Reload latest to fetch the fresh version, then retry the revoke.",
        });
      } else {
        toast.error("Could not revoke license", { description });
      }
    } finally {
      setBusy("none");
      setConfirmRevoke(false);
    }
  }

  return (
    <div className="mt-6 space-y-6">
      <form
        onSubmit={onSave}
        className="space-y-4 rounded-md border border-border bg-card p-6"
        noValidate
      >
        <h2 className="text-lg font-semibold">Update license</h2>
        <label className="block">
          <span className="mb-1 block text-sm font-medium">
            ExpiresAt (ISO 8601, blank for never)
          </span>
          <input
            value={expiresAt}
            onChange={(e) => setExpiresAt(e.target.value)}
            placeholder="2026-12-31T23:59:59Z"
            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
          />
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={isActive}
            onChange={(e) => setIsActive(e.target.checked)}
          />
          Active
        </label>
        {error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}
        {conflict !== null ? (
          <div
            role="status"
            className="rounded-md border border-amber-500/60 bg-amber-500/10 p-3 text-sm"
          >
            <p className="font-medium">This license changed since you loaded it.</p>
            <p className="mt-1 text-muted-foreground">
              Your edits are preserved. Reload the latest version, review the changes, then retry.
            </p>
            <button
              type="button"
              onClick={() => void reloadLatest()}
              disabled={busy !== "none"}
              className="mt-2 inline-flex h-8 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent disabled:opacity-60"
            >
              {busy === "reload" ? "Reloading..." : "Reload latest and retry"}
            </button>
          </div>
        ) : null}
        <button
          type="submit"
          disabled={busy !== "none" || !canMutate}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
        >
          {busy === "save" ? "Saving..." : "Save changes"}
        </button>
      </form>

      <SerialIssueForm licenseId={license.LicenseId} />

      <div className="rounded-md border border-destructive/40 bg-card p-6">
        <h2 className="text-lg font-semibold text-destructive">Revoke license</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Revocation is terminal. All serials issued under this license stop verifying.
        </p>
        {confirmRevoke ? (
          <div className="mt-4 flex flex-col gap-3">
            <LineageBadge />
            <div className="flex gap-2">
              <button
                type="button"
                onClick={() => void onRevoke()}
                disabled={busy !== "none" || !canMutate}
                className="inline-flex h-9 items-center rounded-md bg-destructive px-3 text-sm font-medium text-destructive-foreground hover:bg-destructive/90 disabled:opacity-60"
              >
                {busy === "revoke" ? "Revoking..." : "Confirm revoke"}
              </button>
              <button
                type="button"
                onClick={() => setConfirmRevoke(false)}
                className="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent"
              >
                Cancel
              </button>
            </div>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => setConfirmRevoke(true)}
            className="mt-4 inline-flex h-9 items-center rounded-md border border-destructive px-3 text-sm font-medium text-destructive hover:bg-destructive/10"
          >
            Revoke license
          </button>
        )}
      </div>
    </div>
  );
}
