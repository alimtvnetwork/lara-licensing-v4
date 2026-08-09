/**
 * Closed-set Badge registry per
 * spec/24-app-ui-design-system/25-component-badge-status.md §4.
 *
 * Single source of truth: enum value -> (intent, Icon, Label). Renderers
 * MUST fail loudly on unmapped values (dev throw / prod warn); silent
 * fallback to `neutral` is banned (AC-BDG-002).
 *
 * When a source enum in `spec/21-app/*` changes (add/rename/remove), the
 * corresponding table below MUST update in the same commit. The
 * `tests/badge-closed-sets.test.ts` file asserts parity.
 */
import type { LucideIcon } from "lucide-react";
import {
  Archive,
  Ban,
  Beaker,
  Boxes,
  Building2,
  CalendarX2,
  CheckCircle2,
  Circle,
  Clock,
  FileText,
  Hourglass,
  KeyRound,
  Link2,
  Package,
  PauseCircle,
  RefreshCw,
  Shield,
  ShieldCheck,
  Sparkles,
  Store,
  Undo2,
  User,
  Wrench,
  XCircle,
} from "lucide-react";

import type { BadgeIntent } from "@/components/ui/badge";

export interface BadgeSpec {
  readonly intent: BadgeIntent;
  readonly icon: LucideIcon;
  readonly label: string;
}

export type LicenseStateValue =
  | "Draft"
  | "Issued"
  | "Active"
  | "GracePeriod"
  | "Expired"
  | "Revoked"
  | "Suspended";

export enum SerialStateType { Unbound = "Unbound", Bound = "Bound", Rebinding = "Rebinding", Retired = "Retired" }
export enum BuilderKeyStateType { Active = "Active", Rotating = "Rotating", Revoked = "Revoked" }
export enum QuotaRequestStatusType { Pending = "Pending", Approved = "Approved", Denied = "Denied", Cancelled = "Cancelled" }
export enum UserRoleType { SuperAdmin = "SuperAdmin", Admin = "Admin", Reseller = "Reseller", AppBuilder = "AppBuilder", EndUser = "EndUser" }
export enum LicenseTierType { Trial = "Trial", Standard = "Standard", Professional = "Professional", Enterprise = "Enterprise" }

export const LicenseStateBadge: Record<LicenseStateValue, BadgeSpec> = {
  Draft: { intent: "neutral", icon: FileText, label: "Draft" },
  Issued: { intent: "info", icon: Sparkles, label: "Issued" },
  Active: { intent: "success", icon: CheckCircle2, label: "Active" },
  GracePeriod: { intent: "warning", icon: Clock, label: "Grace period" },
  Expired: { intent: "destructive", icon: CalendarX2, label: "Expired" },
  Revoked: { intent: "destructive", icon: Ban, label: "Revoked" },
  Suspended: { intent: "warning", icon: PauseCircle, label: "Suspended" },
};

export const SerialStateBadge: Record<SerialStateValue, BadgeSpec> = {
  Unbound: { intent: "neutral", icon: Circle, label: "Unbound" },
  Bound: { intent: "success", icon: Link2, label: "Bound" },
  Rebinding: { intent: "warning", icon: RefreshCw, label: "Rebinding" },
  Retired: { intent: "destructive", icon: Archive, label: "Retired" },
};

export const BuilderKeyStateBadge: Record<BuilderKeyStateValue, BadgeSpec> = {
  Active: { intent: "success", icon: KeyRound, label: "Active" },
  Rotating: { intent: "warning", icon: RefreshCw, label: "Rotating" },
  Revoked: { intent: "destructive", icon: Ban, label: "Revoked" },
};

export const QuotaRequestStatusBadge: Record<QuotaRequestStatusValue, BadgeSpec> = {
  Pending: { intent: "info", icon: Hourglass, label: "Pending" },
  Approved: { intent: "success", icon: CheckCircle2, label: "Approved" },
  Denied: { intent: "destructive", icon: XCircle, label: "Denied" },
  Cancelled: { intent: "neutral", icon: Undo2, label: "Cancelled" },
};

export const UserRoleBadge: Record<UserRoleValue, BadgeSpec> = {
  SuperAdmin: { intent: "accent", icon: ShieldCheck, label: "Super admin" },
  Admin: { intent: "accent", icon: Shield, label: "Admin" },
  Reseller: { intent: "accent", icon: Store, label: "Reseller" },
  AppBuilder: { intent: "accent", icon: Wrench, label: "App builder" },
  EndUser: { intent: "neutral", icon: User, label: "End user" },
};

export const LicenseTierBadge: Record<LicenseTierValue, BadgeSpec> = {
  Trial: { intent: "neutral", icon: Beaker, label: "Trial" },
  Standard: { intent: "info", icon: Package, label: "Standard" },
  Professional: { intent: "info", icon: Boxes, label: "Professional" },
  Enterprise: { intent: "accent", icon: Building2, label: "Enterprise" },
};

export type BadgeRegistryName =
  | "LicenseState"
  | "SerialState"
  | "BuilderKeyState"
  | "QuotaRequestStatus"
  | "UserRole"
  | "LicenseTier";

const REGISTRIES: Record<BadgeRegistryName, Record<string, BadgeSpec>> = {
  LicenseState: LicenseStateBadge,
  SerialState: SerialStateBadge,
  BuilderKeyState: BuilderKeyStateBadge,
  QuotaRequestStatus: QuotaRequestStatusBadge,
  UserRole: UserRoleBadge,
  LicenseTier: LicenseTierBadge,
};

/**
 * Resolve an enum value to its Badge spec. On unmapped values:
 * - dev (`import.meta.env.DEV`): throw so the drift is caught in tests.
 * - prod: `console.warn` with `BadgeUnknownValue` and fall back to a
 *   destructive-toned "Unknown" spec so the surface stays legible while
 *   telemetry surfaces the drift (AC-BDG-002).
 */
export function resolveBadgeSpec(registry: BadgeRegistryName, value: string): BadgeSpec {
  const entry = REGISTRIES[registry][value];
  if (entry) return entry;
  const message = `BadgeUnknownValue Registry=${registry} Value=${value}`;
  if (import.meta.env.DEV) throw new Error(message);
  console.warn(message);

  return { intent: "destructive", icon: XCircle, label: value };
}
