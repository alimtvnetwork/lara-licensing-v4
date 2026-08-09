// Centered-card composition shared by StateForbidden, StateNotFound, and
// StateError per spec/24-app-ui-design-system/16-route-shell-states.md §2.1-§2.4.
// Loading uses skeletons instead, so it does NOT reuse this shell.

import { useEffect, useId, useRef, type ReactNode } from "react";

export type StateCardProps = {
  icon: ReactNode;
  headline: string;
  body: string;
  primary: ReactNode;
  secondary?: ReactNode;
  requestId?: string | null;
  /**
   * v0.672.0: optional operationId (e.g. from `PreviewHandlerMissingError`)
   * so route error boundaries surface WHICH operation failed alongside the
   * RequestId. Rendered under the same `route-error-correlation` dl used by
   * `RouteErrorState` so support tickets and e2e assertions share one shape.
   */
  operationId?: string | null;
  /**
   * v0.679.0: when true, the correlation strip is ALWAYS rendered and
   * missing values fall back to the literal `CORRELATION_UNKNOWN` string
   * ("unknown"). Route error boundaries opt in so support tickets never
   * arrive without a visible Operation/Request row — an empty strip made
   * it look like the error carried no metadata, when in reality the ids
   * were dropped upstream. Forbidden/NotFound leave this off so their
   * correlation row still hides when nothing meaningful exists.
   */
  correlationFallback?: boolean;
};

export const CORRELATION_UNKNOWN = "unknown";

export function StateCard(props: StateCardProps) {
  const headingId = useId();
  const headingRef = useRef<HTMLHeadingElement | null>(null);
  useEffect(() => {
    // §2.4: focus MUST move to the h1 on mount for SR announcement.
    headingRef.current?.focus();
  }, []);

  return (
    <section
      role="region"
      aria-labelledby={headingId}
      className="fade-in mx-auto grid max-w-[480px] gap-4 rounded-2xl border border-border/70 bg-card px-7 py-9"
      style={{
        boxShadow: "var(--shadow-elevation-2)",
        backgroundImage:
          "linear-gradient(180deg, color-mix(in oklab, var(--primary) 4%, var(--card)) 0%, var(--card) 55%)",
      }}
    >
      <div
        aria-hidden="true"
        className="grid size-12 place-items-center rounded-xl text-primary"
        style={{
          backgroundImage:
            "linear-gradient(135deg, color-mix(in oklab, var(--primary) 18%, transparent), color-mix(in oklab, var(--accent) 12%, transparent))",
        }}
      >
        {props.icon}
      </div>
      <h1
        id={headingId}
        ref={headingRef}
        tabIndex={-1}
        className="font-display text-2xl font-semibold tracking-tight text-foreground outline-none"
      >
        {props.headline}
      </h1>
      <p className="text-sm leading-relaxed text-muted-foreground">{props.body}</p>
      <div className="flex flex-wrap gap-2">
        {props.primary}
        {props.secondary}
      </div>
      <StateCorrelation
        operationId={props.operationId}
        requestId={props.requestId}
        fallback={props.correlationFallback ?? false}
      />
      <StateRequestId requestId={props.requestId} />
    </section>
  );
}

/**
 * v0.672.0: correlation strip used by StateError (route errorComponent).
 * Renders opId + reqId with stable testids so support tickets and e2e both
 * grep for the same hooks (`route-error-operation-id`, `route-error-request-id`).
 * Mirrors the dl in `RouteFallbacks.RouteErrorState` so both surfaces agree.
 */
function StateCorrelation({
  operationId,
  requestId,
  fallback,
}: {
  operationId?: string | null;
  requestId?: string | null;
  fallback: boolean;
}) {
  const hasOp = typeof operationId === "string" && operationId.length > 0;
  const hasReq = typeof requestId === "string" && requestId.length > 0;
  if (!hasOp && !hasReq && !fallback) return null;
  const showOp = hasOp || fallback;
  const showReq = hasReq || fallback;
  const opValue = hasOp ? (operationId as string) : CORRELATION_UNKNOWN;
  const reqValue = hasReq ? (requestId as string) : CORRELATION_UNKNOWN;
  const opMissing = !hasOp && fallback;
  const reqMissing = !hasReq && fallback;

  return (
    <dl
      data-testid="route-error-correlation"
      className="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1 text-xs text-muted-foreground"
    >
      {showOp ? (
        <>
          <dt className="font-medium">Operation</dt>
          <dd
            data-testid="route-error-operation-id"
            data-missing={opMissing ? "true" : undefined}
            className="font-mono"
          >
            {opValue}
          </dd>
        </>
      ) : null}
      {showReq ? (
        <>
          <dt className="font-medium">Request</dt>
          <dd
            data-testid="route-error-request-id"
            data-missing={reqMissing ? "true" : undefined}
            className="font-mono"
          >
            {reqValue}
          </dd>
        </>
      ) : null}
    </dl>
  );
}

function StateRequestId({ requestId }: { requestId?: string | null }) {
  if (requestId === undefined) return null;
  const value = requestId ?? "no-request-id";
  const tail = value.length > 12 ? value.slice(-12) : value;

  return (
    <button
      type="button"
      onClick={() => void navigator.clipboard?.writeText(value)}
      className="justify-self-end font-mono text-xs text-muted-foreground hover:text-foreground"
      aria-label={`Copy request id ${value}`}
    >
      {tail}
    </button>
  );
}
