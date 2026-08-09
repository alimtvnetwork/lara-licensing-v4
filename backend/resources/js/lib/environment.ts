// Plan 06 step 82. Sole normative environment closed-set guard for the
// Laravel-served Inertia console.
//
// Root cause this module closes (one sentence): the Inertia console restated
// the LicenseEnvironment closed set as a bare label map in
// backend/resources/js/lib/closed-sets.ts (ENVIRONMENT_LABELS) with no
// membership guard and no mismatch formatter, so a drifted ordinal reached
// POST handlers as a 400/409 round-trip instead of failing in the form.
//
// Mirrors, in this order of authority:
//  - spec/21-app/44-environments.md v1.0.0 section 2 (closed set Production=1,
//    Staging=2, Development=3; ordinals are 1-based positions).
//  - backend/config/lara.php key `environments` (same order).
//  - backend/app/Services/EnvironmentService.php (ordinalToName, nameToOrdinal,
//    assertMatch, ordinalMax).
//  - src/lib/lara-environment.ts (SPA half; identical member set).
//
// Adding or reordering a member requires editing spec 44, config/lara.php,
// EnvironmentService, src/lib/lara-environment.ts, and this file in the same
// commit. tests/laravel-environment-guard.test.ts asserts the parity.

export const ENVIRONMENT_NAMES = [
  "Production",
  "Staging",
  "Development",
] as const;

export type EnvironmentName = (typeof ENVIRONMENT_NAMES)[number];

export const EnvironmentId = {
  Production: 1,
  Staging: 2,
  Development: 3,
} as const;

export type EnvironmentIdValue = (typeof EnvironmentId)[keyof typeof EnvironmentId];

export const ENVIRONMENT_IDS = [1, 2, 3] as const;

/** Mirrors EnvironmentService::ordinalMax(), used for validator max: rules. */
export function environmentOrdinalMax(): number {
  return ENVIRONMENT_NAMES.length;
}

export function isEnvironmentId(value: unknown): value is EnvironmentIdValue {
  return (
    typeof value === "number" &&
    Number.isInteger(value) &&
    value >= 1 &&
    value <= ENVIRONMENT_NAMES.length
  );
}

export function isEnvironmentName(value: unknown): value is EnvironmentName {
  return (
    typeof value === "string" &&
    (ENVIRONMENT_NAMES as readonly string[]).includes(value)
  );
}

/**
 * Membership guard mirroring EnvironmentService::ordinalToName(). Throws with
 * the offending field name so the caller can attach it to the form error,
 * matching the server `ValidationFailed` / `MembershipRequired` pair without
 * restating the spec path in runtime copy.
 */
export function parseEnvironmentId(
  value: unknown,
  field = "EnvironmentId",
): EnvironmentIdValue {
  const numeric = typeof value === "string" && value.trim() !== "" ? Number(value) : value;
  if (!isEnvironmentId(numeric)) {
    throw new Error(
      `${field} must be one of 1 (Production), 2 (Staging), 3 (Development); received ${String(value)}.`,
    );
  }
  return numeric;
}

/** Mirrors EnvironmentService::ordinalToName(); throws on non-members. */
export function environmentName(ordinal: unknown): EnvironmentName {
  return ENVIRONMENT_NAMES[parseEnvironmentId(ordinal) - 1] as EnvironmentName;
}

/** Mirrors EnvironmentService::nameToOrdinal(); throws on non-members. */
export function environmentOrdinal(name: unknown): EnvironmentIdValue {
  if (!isEnvironmentName(name)) {
    throw new Error(
      `Environment must be one of ${ENVIRONMENT_NAMES.join(", ")}; received ${String(name)}.`,
    );
  }
  return (ENVIRONMENT_NAMES.indexOf(name) + 1) as EnvironmentIdValue;
}

/**
 * Non-throwing decoder for table and detail cells. Unknown ordinals render
 * "unknown" so a drifted catalog is visible instead of an empty cell, matching
 * the closed-sets.ts convention.
 */
export function environmentLabel(ordinal: number | null | undefined): string {
  if (ordinal === null || ordinal === undefined) return "unknown";
  return isEnvironmentId(ordinal) ? ENVIRONMENT_NAMES[ordinal - 1]! : "unknown";
}

export const environmentOptions: readonly { value: EnvironmentIdValue; label: EnvironmentName }[] =
  ENVIRONMENT_NAMES.map((label, index) => ({
    value: (index + 1) as EnvironmentIdValue,
    label,
  }));

/**
 * Client-side mirror of EnvironmentService::assertMatch(). Returns the opaque
 * `<Requested>/<Licensed>` marker per AC-API-VER-010 when the pair mismatches,
 * or null when they match. Never returns the licensed name.
 */
export function environmentMismatchMarker(
  licensedName: unknown,
  requestedOrdinal: unknown,
): string | null {
  const licensed = environmentOrdinal(licensedName);
  const requested = parseEnvironmentId(requestedOrdinal);
  return licensed === requested ? null : `${requested}/${licensed}`;
}

/** Throws with the opaque marker when the requested environment mismatches. */
export function assertEnvironmentMatch(
  licensedName: unknown,
  requestedOrdinal: unknown,
): void {
  const marker = environmentMismatchMarker(licensedName, requestedOrdinal);
  if (marker !== null) {
    throw new Error(
      `Requested environment does not match license environment (${marker}).`,
    );
  }
}
