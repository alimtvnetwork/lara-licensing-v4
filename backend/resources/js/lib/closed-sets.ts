import { ENVIRONMENT_NAMES } from "./environment";

// Plan 06 step 69. Closed-set label decoders for the Inertia console.

//
// Ordinals are mirrored from backend/config/lara.php (`license_categories`,
// `license_tiers`) and App\Http\Resources\QuotaRequestResource::statusName.
// Adding a member requires editing both places in the same commit; unknown
// ordinals render "unknown" rather than an empty cell so a drifted catalog is
// visible in the UI instead of looking like missing data.

export const LICENSE_CATEGORY_LABELS: Record<number, string> = {
  1: "Daily",
  2: "Weekly",
  3: "Monthly",
  4: "Yearly",
  5: "Lifetime",
  6: "Dev",
  7: "Key",
};

export const LICENSE_TIER_LABELS: Record<number, string> = {
  1: "Tier1",
  2: "Tier2",
  3: "Tier3",
};

export const QUOTA_REQUEST_STATUS = {
  Pending: 1,
  Approved: 2,
  Denied: 3,
  Cancelled: 4,
} as const;

export const QUOTA_REQUEST_STATUS_LABELS: Record<number, string> = {
  1: "Pending",
  2: "Approved",
  3: "Denied",
  4: "Cancelled",
};

export function categoryLabel(ordinal: number | null | undefined): string {
  if (ordinal === null || ordinal === undefined) return "unknown";
  return LICENSE_CATEGORY_LABELS[ordinal] ?? "unknown";
}

export function tierLabel(ordinal: number | null | undefined): string {
  if (ordinal === null || ordinal === undefined) return "unknown";
  return LICENSE_TIER_LABELS[ordinal] ?? "unknown";
}

export function quotaStatusLabel(ordinal: number | null | undefined): string {
  if (ordinal === null || ordinal === undefined) return "unknown";
  return QUOTA_REQUEST_STATUS_LABELS[ordinal] ?? "unknown";
}

export const licenseCategoryOptions = Object.entries(LICENSE_CATEGORY_LABELS).map(
  ([ordinal, label]) => ({ value: Number(ordinal), label }),
);

export const licenseTierOptions = Object.entries(LICENSE_TIER_LABELS).map(
  ([ordinal, label]) => ({ value: Number(ordinal), label }),
);

// Registry half of this module, consumed by Components/admin/LicenseFacts.tsx.
// Mirrors src/lib/closed-sets.ts REGISTRY (labels and ordering included) so the
// Inertia console and the SPA decode the same ordinals identically. LicenseTier
// carries the spec 43 ordinals 1..4; the submit form above intentionally offers
// only the three tiers that backend/config/lara.php `license_tiers` accepts, so
// a request for Unlimited cannot be composed and then rejected with 400.

// Environment is owned by ./environment.ts (Plan 06 step 82), which mirrors
// EnvironmentService.php and spec 44. Re-exported here only so existing
// registry consumers keep one import site; do not restate the members.
export const ENVIRONMENT_LABELS: Record<number, string> = Object.fromEntries(
  ENVIRONMENT_NAMES.map((label, index) => [index + 1, label]),
);


export type ClosedSetName = "LicenseCategory" | "LicenseTier" | "Environment" | "QuotaRequestStatus";

export interface ClosedSetOption {
  readonly value: number;
  readonly label: string;
}

const CLOSED_SET_REGISTRY: Record<ClosedSetName, readonly ClosedSetOption[]> = {
  LicenseCategory: licenseCategoryOptions,
  LicenseTier: [
    { value: 1, label: "Tier 1" },
    { value: 2, label: "Tier 2" },
    { value: 3, label: "Tier 3" },
    { value: 4, label: "Unlimited" },
  ],
  Environment: Object.entries(ENVIRONMENT_LABELS).map(([ordinal, label]) => ({
    value: Number(ordinal),
    label,
  })),
  QuotaRequestStatus: Object.entries(QUOTA_REQUEST_STATUS_LABELS).map(([ordinal, label]) => ({
    value: Number(ordinal),
    label,
  })),
};

export function closedSetOptions(name: ClosedSetName): readonly ClosedSetOption[] {
  return CLOSED_SET_REGISTRY[name];
}

/** Returns undefined for an unknown ordinal; call sites render "unknown". */
export function findClosedSetOption(name: ClosedSetName, value: number): ClosedSetOption | undefined {
  return CLOSED_SET_REGISTRY[name].find((option) => option.value === value);
}
