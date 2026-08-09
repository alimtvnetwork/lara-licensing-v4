/**
 * Sidebar navigation trees per spec/24-app-ui-design-system/13-navigation-ia.md §5-§8.
 * Data-only module: item order, labels, routes, icons, permission keys, status.
 * Consumers (AppSidebar) translate `status` into Visible/Disabled per §3.
 *
 * Note: real Hidden-vs-Visible gating requires the FE permissions catalog
 * (`spec/21-app/40-permissions.md`) which is not yet wired into the session.
 * Until that lands, `status = D` (deferred) renders Disabled with a
 * "Coming soon" tooltip, and `C`/`A` render Visible. This preserves the
 * "no Forbidden on click" invariant from §3 because deferred items never
 * navigate.
 */
import type { LucideIcon } from "lucide-react";
import {
  AppWindow,
  Barcode,
  Building2,
  Download,
  Gauge,
  KeyRound,
  KeySquare,
  LayoutDashboard,
  LayoutGrid,
  LogOut,
  MonitorSmartphone,
  Package,
  PackageCheck,
  ScrollText,
  ShieldAlert,
  SlidersHorizontal,
  Tags,
  UserCircle,
  Users,
} from "lucide-react";

import type { LaraShellRoleType } from "@/lib/lara-shell-role";

export type NavGroupType = "Primary" | "Ops" | "Account";
export type NavStatusType = "C" | "A" | "D";

export interface NavItemType {
  label: string;
  route: string | null;
  icon: LucideIcon;
  permission: string | null;
  group: NavGroupType;
  status: NavStatusType;
  action?: "SignOut";
}

/** Substitute `$resellerId` placeholders in the Reseller tree. */
export function resolveRoute(route: string | null, resellerId: string | null): string | null {
  if (route === null) return null;
  if (resellerId === null) return route;

  return route.replaceAll("$resellerId", resellerId);
}

const AccountItems: NavItemType[] = [
  {
    label: "Profile",
    route: "/account/profile",
    icon: UserCircle,
    permission: "Users.ReadSelf",
    group: "Account",
    status: "C",
  },
  {
    label: "Sign out",
    route: null,
    icon: LogOut,
    permission: null,
    group: "Account",
    status: "C",
    action: "SignOut",
  },
];

const AdminTree: NavItemType[] = [
  {
    label: "Overview",
    route: "/admin",
    icon: LayoutDashboard,
    permission: "Admin.Overview.Read",
    group: "Primary",
    status: "D",
  },
  {
    label: "Resellers",
    route: "/admin/resellers",
    icon: Building2,
    permission: "Resellers.Manage",
    group: "Primary",
    status: "C",
  },
  {
    label: "Users",
    route: "/admin/users",
    icon: Users,
    permission: "Users.Manage",
    group: "Primary",
    status: "C",
  },
  {
    label: "Categories",
    route: "/admin/categories",
    icon: Tags,
    permission: "LicenseCategories.Manage",
    group: "Primary",
    status: "D",
  },
  {
    label: "Licenses",
    route: "/admin/licenses",
    icon: KeyRound,
    permission: "Licenses.Read",
    group: "Primary",
    status: "C",
  },
  {
    label: "Features",
    route: "/admin/features",
    icon: SlidersHorizontal,
    permission: "Features.Manage",
    group: "Primary",
    status: "C",
  },
  {
    label: "Audit",
    route: "/admin/audit",
    icon: ScrollText,
    permission: "AuditEvents.Read",
    group: "Ops",
    status: "A",
  },
  {
    label: "Abuse",
    route: "/admin/abuse",
    icon: ShieldAlert,
    permission: "RateLimitBuckets.Read",
    group: "Ops",
    status: "D",
  },
  {
    label: "Quota requests",
    route: "/admin/quota-requests",
    icon: Gauge,
    permission: "Quotas.Approve",
    group: "Ops",
    status: "A",
  },
  {
    label: "App updates",
    route: "/admin/app-updates",
    icon: PackageCheck,
    permission: "AppUpdates.Manage",
    group: "Ops",
    status: "A",
  },
  ...AccountItems,
];

const ResellerTree: NavItemType[] = [
  {
    label: "Overview",
    route: "/reseller/$resellerId",
    icon: LayoutDashboard,
    permission: "Reseller.Overview.Read",
    group: "Primary",
    status: "D",
  },
  {
    label: "Packages",
    route: "/reseller/$resellerId/packages",
    icon: Package,
    permission: "LicensePackages.Manage",
    group: "Primary",
    status: "D",
  },
  {
    label: "Licenses",
    route: "/reseller/$resellerId/licenses",
    icon: KeyRound,
    permission: "Licenses.Read",
    group: "Primary",
    status: "C",
  },
  {
    label: "Serials",
    route: "/reseller/$resellerId/serials",
    icon: Barcode,
    permission: "Serials.Lookup",
    group: "Primary",
    status: "C",
  },
  {
    label: "Quota",
    route: "/reseller/$resellerId/quota-requests",
    icon: Gauge,
    permission: "QuotaRequests.Submit",
    group: "Primary",
    status: "C",
  },
  {
    label: "Activity",
    route: "/reseller/$resellerId/activity",
    icon: ScrollText,
    permission: "AuditEvents.ReadOwn",
    group: "Ops",
    status: "D",
  },
  ...AccountItems,
];

const AppBuilderTree: NavItemType[] = [
  {
    label: "Overview",
    route: "/builder",
    icon: LayoutDashboard,
    permission: "Builder.Overview.Read",
    group: "Primary",
    status: "D",
  },
  {
    label: "Clients",
    route: "/builder/clients",
    icon: AppWindow,
    permission: "Clients.Manage",
    group: "Primary",
    status: "D",
  },
  {
    label: "Keys",
    route: "/builder/keys",
    icon: KeySquare,
    permission: "ClientKeys.Manage",
    group: "Primary",
    status: "D",
  },
  {
    label: "Updates",
    route: "/builder/updates",
    icon: PackageCheck,
    permission: "AppUpdates.ReadOwn",
    group: "Primary",
    status: "C",
  },
  {
    label: "Logs",
    route: "/builder/logs",
    icon: ScrollText,
    permission: "AuditEvents.ReadOwn",
    group: "Ops",
    status: "D",
  },
  ...AccountItems,
];

const EndUserTree: NavItemType[] = [
  {
    label: "Home",
    route: "/portal/home",
    icon: LayoutDashboard,
    permission: "Serials.Verify",
    group: "Primary",
    status: "C",
  },
  {
    label: "Products",
    route: "/app/products",
    icon: LayoutGrid,
    permission: "EndUser.Products.Read",
    group: "Primary",
    status: "D",
  },
  {
    label: "Devices",
    route: "/app/devices",
    icon: MonitorSmartphone,
    permission: "EndUser.Devices.Read",
    group: "Primary",
    status: "D",
  },
  {
    label: "Profile",
    route: "/account/profile",
    icon: UserCircle,
    permission: "Users.ReadSelf",
    group: "Account",
    status: "C",
  },
  {
    label: "Update",
    route: "/portal/updates",
    icon: Download,
    permission: "AppUpdates.ReadOwn",
    group: "Account",
    status: "C",
  },
  {
    label: "Sign out",
    route: null,
    icon: LogOut,
    permission: null,
    group: "Account",
    status: "C",
    action: "SignOut",
  },
];

export function navTreeForRole(role: LaraShellRoleType): NavItemType[] {
  if (role === "Admin") return AdminTree;
  if (role === "Reseller") return ResellerTree;
  if (role === "AppBuilder") return AppBuilderTree;

  return EndUserTree;
}

export function landingRouteForRole(role: LaraShellRoleType, resellerId: string | null): string {
  if (role === "Admin") return "/admin";
  if (role === "AppBuilder") return "/builder";
  if (role === "EndUser") return "/portal/home";

  return resellerId === null ? "/reseller" : `/reseller/${resellerId}`;
}
