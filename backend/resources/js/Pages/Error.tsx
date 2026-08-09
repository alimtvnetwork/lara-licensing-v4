import { Head, Link } from "@inertiajs/react";
import { AlertTriangle } from "lucide-react";
import { laraCopyForErrorCode } from "../lib/lara-error-copy";

/**
 * Plan 06 step 77. Before this page existed, a LaraException raised on a web
 * (Inertia) route was rendered by the JSON envelope renderer in
 * `backend/bootstrap/app.php`, so the operator saw a raw
 * `{Status, Attributes, Results}` blob in the browser instead of spec 12 copy.
 */
interface ErrorProps {
  status: number;
  errorCode?: string | null;
  requestId?: string | null;
  errorId?: string | null;
  retryAfterSeconds?: number | null;
}

const STATUS_TITLES: Record<number, string> = {
  400: "That request could not be processed",
  401: "Sign in to continue",
  403: "You do not have access",
  404: "Not found",
  409: "This record changed",
  412: "This record changed",
  428: "A fresh read is required",
  429: "Too many requests",
  500: "Something failed on our side",
  503: "Service temporarily unavailable",
};

export default function ErrorPage({
  status,
  errorCode,
  requestId,
  errorId,
  retryAfterSeconds,
}: ErrorProps) {
  const title = STATUS_TITLES[status] ?? "Something went wrong";
  const body = laraCopyForErrorCode(errorCode, {
    retryAfterSeconds: retryAfterSeconds ?? undefined,
  });

  return (
    <main className="flex min-h-screen items-center justify-center bg-background p-6">
      <Head title={`${status} ${title}`} />
      <section className="w-full max-w-lg rounded-2xl border border-input bg-card p-8">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-input">
            <AlertTriangle className="size-4 text-muted-foreground" />
          </span>
          <div>
            <p className="font-mono text-xs uppercase tracking-wide text-muted-foreground">
              {status} · {errorCode ?? "unknown"}
            </p>
            <h1 className="mt-1 font-display text-xl font-semibold tracking-tight">{title}</h1>
            <p className="mt-2 text-sm text-muted-foreground">{body}</p>
          </div>
        </div>

        <dl className="mt-6 grid gap-2 rounded-lg border border-input p-4 text-xs">
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Request id</dt>
            <dd className="font-mono">{requestId || "unknown"}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Error id</dt>
            <dd className="font-mono">{errorId || "unknown"}</dd>
          </div>
        </dl>

        <div className="mt-6 flex gap-2">
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="focus-ring inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium"
          >
            Retry
          </button>
          <Link
            href="/portal"
            className="focus-ring inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground"
          >
            Back to console
          </Link>
        </div>
      </section>
    </main>
  );
}
