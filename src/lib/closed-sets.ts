/**
 * `useClosedSet` helper per spec 24/19-component-select.md §2 (closed-set
 * parity rule). Every Select in the app MUST source its options from this
 * module. Inline `options={[...]}` literals fail
 * `linter-scripts/check-closed-set-select.py` (AC-SEL-001).
 *
 * Each registered set maps 1:1 to the Zod schema listed in spec 19 §2 so
 * runtime validation and UI options stay derived from the same enum
 * declaration. Ordering matches spec 19 §2's "Ordering" column verbatim.
 *
 * Adding a new set requires:
 *   1. A row in spec 24/19-component-select.md §2.
 *   2. A parity test in `tests/closed-sets.test.ts` locking the option
 *      list to the underlying schema / spec table.
 *   3. A registry entry below.
 *
 * Deprecated options render with a `(deprecated)` suffix and
 * `disabled=true` per spec 19 §6; historical values equal to a deprecated
 * option still render as the trigger value (handled at the render site,
 * not here).
 */
import { useMemo } from "react";

import {
  LicenseCategoryIdType,
  LicenseTierIdType,
  type LicenseCategoryIdValue,
  type LicenseTierIdValue,
} from "@/lib/lara-license";
import { EnvironmentIdType, type EnvironmentIdValue } from "@/lib/lara-environment";
import { APP_ROLE_VALUES, type AppRoleType } from "@/lib/lara-user-role";
import { QuotaRequestStatusType, type QuotaRequestStatusValue } from "@/lib/lara-quota";

export interface ClosedSetOption<T extends string | number = string | number> {
  readonly value: T;
  readonly label: string;
  readonly disabled?: boolean;
  readonly deprecated?: boolean;
  readonly helperText?: string;
}

export type ClosedSetName =
  | "LicenseCategory"
  | "LicenseTier"
  | "Environment"
  | "AppRole"
  | "QuotaRequestStatus";

interface Registry {
  LicenseCategory: readonly ClosedSetOption<LicenseCategoryIdValue>[];
  LicenseTier: readonly ClosedSetOption<LicenseTierIdValue>[];
  Environment: readonly ClosedSetOption<EnvironmentIdValue>[];
  AppRole: readonly ClosedSetOption<AppRoleType>[];
  QuotaRequestStatus: readonly ClosedSetOption<QuotaRequestStatusValue>[];
}

const REGISTRY: Registry = {
  // Ordering: ascending ordinal 1..7, per spec 21/05 §Canonical set.
  LicenseCategory: [
    { value: LicenseCategoryIdType.Daily, label: "Daily" },
    { value: LicenseCategoryIdType.Weekly, label: "Weekly" },
    { value: LicenseCategoryIdType.Monthly, label: "Monthly" },
    { value: LicenseCategoryIdType.Yearly, label: "Yearly" },
    { value: LicenseCategoryIdType.Lifetime, label: "Lifetime" },
    { value: LicenseCategoryIdType.Dev, label: "Dev" },
    { value: LicenseCategoryIdType.Key, label: "Key" },
  ],
  // Ordering: spec 21/43 table order.
  LicenseTier: [
    { value: LicenseTierIdType.Tier1, label: "Tier 1" },
    { value: LicenseTierIdType.Tier2, label: "Tier 2" },
    { value: LicenseTierIdType.Tier3, label: "Tier 3" },
    { value: LicenseTierIdType.Unlimited, label: "Unlimited" },
  ],
  // Ordering: canonical Production, Staging, Development per spec 21/44 §2.
  Environment: [
    { value: EnvironmentIdType.Production, label: "Production" },
    { value: EnvironmentIdType.Staging, label: "Staging" },
    { value: EnvironmentIdType.Development, label: "Development" },
  ],
  // Ordering: spec 21/04 table order (Admin, Reseller, AppBuilder, EndUser).
  AppRole: APP_ROLE_VALUES.map((role) => ({
    value: role,
    label: role === "AppBuilder" ? "App builder" : role === "EndUser" ? "End user" : role,
  })),
  // Ordering: spec 21/42 state-machine order.
  QuotaRequestStatus: [
    { value: QuotaRequestStatusType.Pending, label: "Pending" },
    { value: QuotaRequestStatusType.Approved, label: "Approved" },
    { value: QuotaRequestStatusType.Denied, label: "Denied" },
    { value: QuotaRequestStatusType.Cancelled, label: "Cancelled" },
  ],
};

/**
 * Resolve a registered closed set to its option list. Unknown names throw
 * in dev and warn + return an empty list in prod, matching the AC-SEL-004
 * empty-set contract (parent surface renders `AuthzPermissionDenied` when
 * the empty list stems from missing permission, never inferred here).
 */
export function useClosedSet<K extends ClosedSetName>(name: K): Registry[K] {
  return useMemo(() => resolveClosedSet(name), [name]);
}

export function resolveClosedSet<K extends ClosedSetName>(name: K): Registry[K] {
  const entry = REGISTRY[name];
  const isFailed = !entry;
  if (isFailed) {
    const message = `ClosedSetUnknown Name=${String(name)}`;
    if (import.meta.env.DEV) throw new Error(message);
    console.warn(message);

    return [] as unknown as Registry[K];
  }

  return entry;
}

/**
 * Locate an option by its value; used by trigger renderers when a historical
 * (possibly deprecated) value must still render as the current trigger label
 * per spec 19 §6. Returns undefined for genuinely unknown values so callers
 * fall through to the placeholder rather than silently rendering "Unknown".
 */
export function findClosedSetOption<K extends ClosedSetName>(
  name: K,
  value: Registry[K][number]["value"],
): Registry[K][number] | undefined {
  return resolveClosedSet(name).find((o) => o.value === value) as Registry[K][number] | undefined;
}
