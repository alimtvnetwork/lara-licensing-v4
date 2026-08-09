// Route-shell state pages per
// spec/24-app-ui-design-system/16-route-shell-states.md §3-§5.
// Kept as tiny composables so route boundaries can render them directly.

import { AlertOctagon, SearchX, ShieldOff } from "lucide-react";
import { Link, useRouter } from "@tanstack/react-router";
import { StateCard } from "./state-card";
import { useStateTelemetry } from "./use-state-telemetry";
import { LaraApiError } from "../../lib/lara-api-error";

const ICON_SIZE = 24;

export function StateForbidden(props: {
  route: string;
  attemptedPermissionKey?: string;
  userId?: string | null;
  requestId?: string | null;
  overviewTo?: string;
}) {
  useStateTelemetry("RouteForbidden", {
    Route: props.route,
    AttemptedPermissionKey: props.attemptedPermissionKey ?? null,
    UserId: props.userId ?? null,
    RequestId: props.requestId ?? null,
  });

  return (
    <StateCard
      icon={<ShieldOff size={ICON_SIZE} aria-hidden />}
      headline="You do not have access to this page."
      body="Your account does not include the permission required for this section. If you believe this is a mistake, contact your admin."
      primary={<PrimaryLink to={props.overviewTo ?? "/"}>Return to overview</PrimaryLink>}
      requestId={props.requestId ?? null}
    />
  );
}

export function StateNotFound(props: {
  route: string;
  attemptedPath: string;
  userId?: string | null;
  requestId?: string | null;
  overviewTo?: string;
}) {
  useStateTelemetry("RouteNotFound", {
    Route: props.route,
    AttemptedPath: props.attemptedPath,
    UserId: props.userId ?? null,
    RequestId: props.requestId ?? null,
  });

  return (
    <StateCard
      icon={<SearchX size={ICON_SIZE} aria-hidden />}
      headline="We could not find that page."
      body="The link may be outdated, or the record no longer exists. Head back to your overview to continue."
      primary={<PrimaryLink to={props.overviewTo ?? "/"}>Return to overview</PrimaryLink>}
      requestId={props.requestId ?? undefined}
    />
  );
}

export function StateError(props: {
  route: string;
  error: Error;
  requestId?: string | null;
  /**
   * v0.672.0: optional operationId override. When absent, StateError
   * derives it from `error` (e.g. `PreviewHandlerMissingError`) so any
   * LaraApiError subclass carrying a first-class `operationId` field is
   * surfaced in the route error boundary automatically.
   */
  operationId?: string | null;
  reset: () => void;
}) {
  const router = useRouter();
  const message = sanitizeMessage(props.error.message);
  const lara = props.error instanceof LaraApiError ? props.error : undefined;
  const laraLike = lara as unknown as { operationId?: unknown } | undefined;
  const derivedOpId =
    typeof laraLike?.operationId === "string" && laraLike.operationId.length > 0
      ? laraLike.operationId
      : undefined;
  const operationId = props.operationId ?? derivedOpId ?? null;
  const requestId = props.requestId ?? lara?.requestId ?? null;
  useStateTelemetry("RouteError", {
    Route: props.route,
    ErrorCode: extractCode(props.error),
    RequestId: requestId,
    OperationId: operationId,
    Message: message,
  });

  return (
    <StateCard
      icon={<AlertOctagon size={ICON_SIZE} className="text-destructive" aria-hidden />}
      headline="Something went wrong on our side."
      body="We could not complete this request. Try again in a moment. If it keeps happening, share the request ID with support."
      primary={
        <button
          type="button"
          onClick={() => {
            void router.invalidate();
            props.reset();
          }}
          className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          Try again
        </button>
      }
      secondary={<PrimaryLink to="/">Return to overview</PrimaryLink>}
      requestId={requestId}
      operationId={operationId}
      correlationFallback
    />
  );
}

function PrimaryLink({ to, children }: { to: string; children: React.ReactNode }) {
  return (
    <Link
      to={to}
      className="inline-flex items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium text-foreground hover:bg-accent"
    >
      {children}
    </Link>
  );
}

function sanitizeMessage(m: string): string {
  return m.length > 256 ? `${m.slice(0, 253)}...` : m;
}

function extractCode(err: Error): string {
  const code = (err as { code?: unknown }).code;

  return typeof code === "string" && code !== "" ? code : "UnknownServerError";
}
