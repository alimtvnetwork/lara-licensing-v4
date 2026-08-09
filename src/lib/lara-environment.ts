import { z } from "zod";

/**
 * Sole client-side normative module for the LicenseEnvironment closed set
 * defined in spec/21-app/44-environments.md §2 (Production=1, Staging=2,
 * Development=3; ordinals stable per AC-LENV-005). Every other client module
 * MUST import from here rather than restate the members, matching the
 * single-owner rule that keeps spec 44 the sole normative source.
 *
 * Also owns the caller-environment derivation used by the end-user verify
 * handshake (`POST /Verify/Final`). The caller passes an EnvironmentId
 * derived from the AppBuilder OAuth client per spec 44 §3; on the client
 * that value comes from `VITE_LARA_ENVIRONMENT_ID`. A misconfigured or
 * out-of-set value is a client-side defect and MUST fail loudly before any
 * verify request fires so the server never sees an EnvironmentMismatch that
 * was actually a client bug.
 */
export const EnvironmentIdType = {
  Production: 1,
  Staging: 2,
  Development: 3,
} as const;
export type EnvironmentIdValue = (typeof EnvironmentIdType)[keyof typeof EnvironmentIdType];
export const ENVIRONMENT_IDS = [1, 2, 3] as const;

export const environmentIdSchema = z.union([z.literal(1), z.literal(2), z.literal(3)]);

export function isEnvironmentId(value: unknown): value is EnvironmentIdValue {
  return environmentIdSchema.safeParse(value).success;
}

/**
 * Parses an unknown into an EnvironmentIdValue or throws with the field name
 * so upstream loggers can surface where the misconfiguration originated.
 * The message intentionally lists the legal ordinals to keep the fix obvious
 * without leaking the spec path into runtime output.
 */
export function parseEnvironmentId(value: unknown, field: string): EnvironmentIdValue {
  const parsed = environmentIdSchema.safeParse(typeof value === "string" ? Number(value) : value);
  const isFailed = !parsed.success;
  if (isFailed) {
    throw new Error(
      `${field} must be one of 1 (Production), 2 (Staging), 3 (Development); received ${String(value)}.`,
    );
  }

  return parsed.data;
}

/**
 * Reads the caller's EnvironmentId from `VITE_LARA_ENVIRONMENT_ID` and
 * validates it against the closed set. Called by the verify handshake so a
 * misconfigured build fails at the entry point rather than after a network
 * round-trip returns an opaque EnvironmentMismatch (spec 44 §3 rule 2).
 */
export function resolveCallerEnvironmentId(): EnvironmentIdValue {
  const raw = import.meta.env.VITE_LARA_ENVIRONMENT_ID;
  if (raw === undefined || raw === "") {
    throw new Error(
      "VITE_LARA_ENVIRONMENT_ID is not configured. Set it to 1, 2, or 3 per spec/21-app/44-environments.md §2.",
    );
  }

  return parseEnvironmentId(raw, "VITE_LARA_ENVIRONMENT_ID");
}
