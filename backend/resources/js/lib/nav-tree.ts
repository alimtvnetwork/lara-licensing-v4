import { Link, usePage } from "@inertiajs/react";
import { 
  AppWindow, Barcode, Building2, Download, Gauge, KeyRound, KeySquare, 
  LayoutDashboard, LayoutGrid, LogOut, MonitorSmartphone, Package, 
  PackageCheck, ScrollText, ShieldAlert, SlidersHorizontal, Tags, 
  UserCircle, Users, type LucideIcon 
} from "lucide-react";
import { type NavGroupType, type NavItemType, type NavStatusType } from "./nav-tree";

export function resolveRoute(route: string | null, resellerId: string | null | number): string | null {
  if (route === null) return null;
  if (resellerId === null) return route;
  return route.replaceAll("$resellerId", String(resellerId));
}

const AccountItems: NavItemType[] = [
  { label: "Profile", route: "/account/profile", icon: UserCircle, permission: "Users.ReadSelf", group: "Account", status: "C" },
  { label: "Sign out", route: "/logout", icon: LogOut, permission: null, group: "Account", status: "C", action: "SignOut" },
];

const AdminTree: NavItemType[] = [
  { label: "Overview", route: "/admin", icon: LayoutDashboard, permission: "Admin.Overview.Read", group: "Primary", status: "D" },
  { label: "Resellers", route: "/admin/resellers", icon: Building2, permission: "Resellers.Manage", group: "Primary", status: "C" },
  { label: "Users", route: "/admin/users", icon: Users, permission: "Users.Manage", group: "Primary", status: "C" },
  { label: "Categories", route: "/admin/categories", icon: Tags, permission: "LicenseCategories.Manage", group: "Primary", status: "D" },
  { label: "Licenses", route: "/admin/licenses", icon: KeyRound, permission: "Licenses.Read", group: "Primary", status: "C" },
  { label: "Features", route: "/admin/features", icon: SlidersHorizontal, permission: "Features.Manage", group: "Primary", status: "C" },
  { label: "Audit", route: "/admin/audit", icon: ScrollText, permission: "AuditEvents.Read", group: "Ops", status: "C" },
  { label: "App updates", route: "/admin/app-updates", icon: Download, permission: "AppUpdates.Manage", group: "Ops", status: "C" },
  { label: "Runtime", route: "/admin/runtime", icon: Gauge, permission: "Runtime.Manage", group: "Ops", status: "C" },
  { label: "Quota requests", route: "/admin/quota-requests", icon: KeySquare, permission: "Quotas.Manage", group: "Ops", status: "C" },
  { label: "Design", route: "/admin/design", icon: LayoutGrid, permission: "Admin.Dev", group: "Ops", status: "C" },
];

const ResellerTree: NavItemType[] = [
  { label: "Dashboard", route: "/reseller/$resellerId", icon: LayoutDashboard, permission: "Reseller.Read", group: "Primary", status: "C" },
  { label: "Licenses", route: "/reseller/$resellerId/licenses", icon: KeyRound, permission: "Licenses.Read", group: "Primary", status: "C" },
  { label: "Quotas", route: "/reseller/$resellerId/quota-requests", icon: KeySquare, permission: "Quotas.Read", group: "Primary", status: "C" },
];

export function navTreeForRole(role: string): NavItemType[] {
  const base = role === "Admin" || role === "SuperAdmin" ? AdminTree : role === "Reseller" ? ResellerTree : [];
  return [...base, ...AccountItems];
}
