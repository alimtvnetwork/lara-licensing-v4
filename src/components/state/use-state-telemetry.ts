// State telemetry per spec/24-app-ui-design-system/16-route-shell-states.md §2.5.
// Emits one log line per mount, structured, never swallowed.

import { useEffect, useRef } from "react";

export type StateEvent = "RouteForbidden" | "RouteNotFound" | "RouteError" | "RoutePending";

type StatePayload = Record<string, unknown> & { Route: string };

const LEVELS: Record<StateEvent, "warn" | "info" | "error" | "debug"> = {
  RouteForbidden: "warn",
  RouteNotFound: "info",
  RouteError: "error",
  RoutePending: "debug",
};

export function useStateTelemetry(event: StateEvent, payload: StatePayload): void {
  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    const level = LEVELS[event];
    const line = { Event: event, Level: level, Ts: new Date().toISOString(), ...payload };
    // AC-RSS-009: fires exactly once per mount.
    (console[level === "debug" ? "log" : level] as (...a: unknown[]) => void)(
      `lara.state.${event}`,
      line,
    );
  }, [event, payload]);
}
