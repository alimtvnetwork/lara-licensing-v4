// Plan 09 step 95. Shared route-level fallback shells.
//
// Root cause this closes: after v0.305.0 shipped `Skeleton` + `EmptyState`,
// every admin list route (`admin.users`, `admin.resellers`,
// `admin.app-updates`, `admin.audit`) still hand-rolled its own
// `h-64 animate-pulse rounded-md ... bg-muted` pending block plus a
// duplicated retry `<div role="alert">`, so the just-shipped primitives
// were unused and drift was guaranteed. Centralizing here keeps future
// list routes (licenses, quota-requests) fluid on first paint.

import { useRouter } from "@tanstack/react-router";
import { RefreshCw } from "lucide-react";
import type { ReactNode } from "react";

import { PageHeader } from "./PageHeader";
import { SkeletonList, Skeleton } from "../ui/skeleton";
import { formatLaraApiError, LaraApiError } from "../../lib/lara-api-error";

export interface RoutePendingProps {
  /** Page title shown in the header while data loads. */
  title: string;
  /** Optional description slot to match the resolved page. */
  description?: string;
  /** Number of skeleton rows to render. Defaults to 6. */
  rows?: number;
}

/**
 * Skeleton scaffold for suspended route loaders. Renders the real
 * `PageHeader` (so the shell doesn't jump on hydration) followed by a
 * title skeleton and a stack of row skeletons that match the eventual
 * table silhouette.
 */
export function RoutePending({ title, description, rows = 6 }: RoutePendingProps) {
  return (
    <>
      <PageHeader title={title} description={description} />
      <div className="mt-6 grid gap-3" data-testid="route-pending">
        <Skeleton variant="title" />
        <SkeletonList rows={rows} />
      </div>
    </>
  );
}

export interface RouteErrorStateProps {
  title: string;
  error: Error;
  /** TanStack Router `reset` callback bound to the error boundary. */
  reset: () => void;
  /** Optional friendlier headline; defaults to `"<title> could not be loaded"`. */
  headline?: string;
  /** Extra actions rendered next to Retry (e.g. contact support links). */
  actions?: ReactNode;
}

/**
 * Standardized error boundary body. Calls `router.invalidate()` AND
 * `reset()` on retry (per tanstack-errors-notfound guidance) so the
 * loader actually re-runs instead of just clearing the boundary.
 */
export function RouteErrorState({ title, error, reset, headline, actions }: RouteErrorStateProps) {
  const router = useRouter();
  const retry = () => {
    void router.invalidate();
    reset();
  };
  // Plan 17 Step 16 + Step 40: surface the failing operationId + requestId
  // in the route-level error boundary so users (and support tickets) can
  // see WHICH call broke. `operationId` is now a first-class optional
  // field on `LaraApiError`, tagged by `useApi` / `useApiMutation` at the
  // call site; `PreviewHandlerMissingError` still carries it directly.
  const lara = error instanceof LaraApiError ? error : undefined;
  const opId = lara?.operationId;
  const requestId = lara?.requestId;

  return (
    <>
      <PageHeader title={title} />
      <div
        role="alert"
        className="mt-6 rounded-md border border-destructive/40 bg-destructive/5 p-6"
        data-testid="route-error"
      >
        <p className="font-medium text-foreground">{headline ?? `${title} could not be loaded`}</p>
        <p className="mt-1 text-sm text-muted-foreground">{formatLaraApiError(error)}</p>
        {opId !== undefined || requestId !== undefined ? (
          <dl
            data-testid="route-error-correlation"
            className="mt-3 grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1 text-xs text-muted-foreground"
          >
            {opId !== undefined ? (
              <>
                <dt className="font-medium">Operation</dt>
                <dd data-testid="route-error-operation-id" className="font-mono">
                  {opId}
                </dd>
              </>
            ) : null}
            {requestId !== undefined ? (
              <>
                <dt className="font-medium">Request</dt>
                <dd data-testid="route-error-request-id" className="font-mono">
                  {requestId}
                </dd>
              </>
            ) : null}
          </dl>
        ) : null}
        <div className="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            onClick={retry}
            className="focus-ring inline-flex h-9 items-center gap-2 rounded-md border border-input px-3 text-sm font-medium surface-hover"
          >
            <RefreshCw aria-hidden="true" className="size-4" /> Retry
          </button>
          {actions}
        </div>
      </div>
    </>
  );
}
