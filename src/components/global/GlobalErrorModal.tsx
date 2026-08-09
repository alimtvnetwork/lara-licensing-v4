/**
 * Plan 11 step 29 + spec/03-error-manage v1.1 compliance:
 * Global Error Modal shows ErrorCode, message, RequestId, ErrorId,
 * HTTP status, Timestamp, and (when present) Source component. A
 * "Copy All" button copies the full diagnostic payload; when the
 * async Clipboard API is unavailable it falls back to a hidden
 * textarea + `document.execCommand("copy")` so support correlation
 * survives insecure contexts and older browsers.
 */

import { useCallback, useSyncExternalStore } from "react";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import {
  clearErrorStore,
  getErrorStoreSnapshot,
  subscribeErrorStore,
  type ErrorStoreEntry,
} from "@/lib/error-store";
import { RetryPolicyType } from "@/lib/lara-retry";

function isFatal(entry: ErrorStoreEntry): boolean {
  return (
    entry.retryPolicy === RetryPolicyType.NoRetry ||
    entry.retryPolicy === RetryPolicyType.FatalClear
  );
}

function selectFatal(entries: ReadonlyArray<ErrorStoreEntry>): ErrorStoreEntry | undefined {
  return entries.find(isFatal);
}

const EMPTY_SNAPSHOT: ReadonlyArray<ErrorStoreEntry> = Object.freeze([]);
function serverEmptySnapshot(): ReadonlyArray<ErrorStoreEntry> {
  return EMPTY_SNAPSHOT;
}

function formatTimestamp(at: number): string {
  return new Date(at).toISOString();
}

function serializePayload(entry: ErrorStoreEntry): string {
  const payload = {
    ErrorCode: entry.errorCode,
    HttpStatus: entry.httpStatus,
    Message: entry.message,
    RequestId: entry.requestId ?? null,
    ErrorId: entry.errorId ?? null,
    SourceComponent: entry.sourceComponent ?? null,
    Timestamp: formatTimestamp(entry.at),
    Details: entry.details ?? null,
    RateLimit: entry.rateLimit ?? null,
  };

  return JSON.stringify(payload, null, 2);
}

async function writeClipboard(text: string): Promise<void> {
  if (navigator.clipboard?.writeText !== undefined) {
    await navigator.clipboard.writeText(text);

    return;
  }
  fallbackCopy(text);
}

function fallbackCopy(text: string): void {
  const ta = document.createElement("textarea");
  ta.value = text;
  ta.setAttribute("readonly", "");
  ta.style.position = "fixed";
  ta.style.opacity = "0";
  document.body.appendChild(ta);
  ta.select();
  document.execCommand("copy");
  document.body.removeChild(ta);
}

export function GlobalErrorModal() {
  const entries = useSyncExternalStore(
    subscribeErrorStore,
    getErrorStoreSnapshot,
    serverEmptySnapshot,
  );
  const fatal = selectFatal(entries);
  const open = fatal !== undefined;

  const handleOpenChange = useCallback((next: boolean) => {
    const isFailed = !next;
    if (isFailed) clearErrorStore();
  }, []);

  const copyErrorId = useCallback(async () => {
    if (fatal?.errorId === undefined) return;
    try {
      await writeClipboard(fatal.errorId);
    } catch (cause) {
      pushLaraApiError(new Error());
    }
  }, [fatal]);

  const copyAll = useCallback(async () => {
    if (fatal === undefined) return;
    try {
      await writeClipboard(serializePayload(fatal));
    } catch (cause) {
      pushLaraApiError(new Error());
    }
  }, [fatal]);

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent data-testid="global-error-modal">
        <DialogHeader>
          <DialogTitle>{fatal?.errorCode ?? "Error"}</DialogTitle>
          <DialogDescription>{fatal?.message ?? ""}</DialogDescription>
        </DialogHeader>
        {fatal !== undefined ? (
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            {fatal.operationId !== undefined ? (
              <>
                <dt className="text-muted-foreground">Operation</dt>
                <dd className="font-mono" data-testid="global-error-operation-id">
                  {fatal.operationId}
                </dd>
              </>
            ) : null}
            {fatal.requestId !== undefined ? (
              <>
                <dt className="text-muted-foreground">Request</dt>
                <dd className="font-mono" data-testid="global-error-request-id">
                  {fatal.requestId}
                </dd>
              </>
            ) : null}
            {fatal.errorId !== undefined ? (
              <>
                <dt className="text-muted-foreground">Error ID</dt>
                <dd className="font-mono break-all" data-testid="global-error-error-id">
                  {fatal.errorId}
                </dd>
              </>
            ) : null}
            <dt className="text-muted-foreground">HTTP</dt>
            <dd className="font-mono">{fatal.httpStatus}</dd>
            <dt className="text-muted-foreground">Timestamp</dt>
            <dd className="font-mono" data-testid="global-error-timestamp">
              {formatTimestamp(fatal.at)}
            </dd>
            {fatal.sourceComponent !== undefined ? (
              <>
                <dt className="text-muted-foreground">Source</dt>
                <dd className="font-mono" data-testid="global-error-source">
                  {fatal.sourceComponent}
                </dd>
              </>
            ) : null}
          </dl>
        ) : null}
        <DialogFooter>
          {fatal?.errorId !== undefined ? (
            <Button
              variant="outline"
              onClick={() => {
                void copyErrorId();
              }}
              data-testid="global-error-copy-id"
            >
              Copy Error ID
            </Button>
          ) : null}
          {fatal !== undefined ? (
            <Button
              variant="outline"
              onClick={() => {
                void copyAll();
              }}
              data-testid="global-error-copy-all"
            >
              Copy All
            </Button>
          ) : null}
          <Button onClick={() => handleOpenChange(false)} data-testid="global-error-dismiss">
            Dismiss
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
