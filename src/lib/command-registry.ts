/**
 * Command registry per spec/24-app-ui-design-system/32-command-registry.md §6.
 *
 * Root cause of prior gap: no closed set for the Command Palette existed, so
 * §5 (permission-hidden rule) and §2 (Label / CommandId shape) could not be
 * enforced. This module ships v1 Navigation commands derived from the frozen
 * `nav-tree`, guaranteeing that Palette entries match the sidebar routes and
 * never expose Disabled or `null`-route items.
 *
 * Action commands (§7) with Dialog `Kind` land alongside plan 07 steps 26+
 * (per-surface Dialog refits). Not shipping them here avoids Palette entries
 * that would silently fail because the target Dialog does not yet exist.
 */
import type { LucideIcon } from "lucide-react";

import { navTreeForRole, resolveRoute, type NavItemType } from "@/components/shell/nav-tree";
import type { LaraShellRoleType } from "@/lib/lara-shell-role";

export type CommandKindType = "Navigate";
export type CommandGroupType = "Navigation";

export interface CommandRow {
  commandId: string;
  label: string;
  kind: CommandKindType;
  target: string;
  permission: string | null;
  icon: LucideIcon;
  group: CommandGroupType;
}

const CommandIdPrefixByRole: Record<LaraShellRoleType, string> = {
  Admin: "Nav.Admin",
  Reseller: "Nav.Reseller",
  AppBuilder: "Nav.Builder",
  EndUser: "Nav.EndUser",
};

/** Palette-visible commands for the caller's shell role. Deferred / actionless items are excluded. */
export function commandsForRole(role: LaraShellRoleType, resellerId: string | null): CommandRow[] {
  const prefix = CommandIdPrefixByRole[role];

  return navTreeForRole(role)
    .filter(isPaletteVisible)
    .map((item) => toCommandRow(item, prefix, resellerId))
    .filter((row): row is CommandRow => row !== null);
}

function isPaletteVisible(item: NavItemType): boolean {
  if (item.action !== undefined) return false;
  if (item.route === null) return false;

  return item.status === "C" || item.status === "A";
}

function toCommandRow(
  item: NavItemType,
  prefix: string,
  resellerId: string | null,
): CommandRow | null {
  const target = resolveRoute(item.route, resellerId);
  if (target === null || target.includes("$")) return null;

  return {
    commandId: `${prefix}.${slugify(item.label)}`,
    label: item.label,
    kind: "Navigate",
    target,
    permission: item.permission,
    icon: item.icon,
    group: "Navigation",
  };
}

function slugify(label: string): string {
  return label.replace(/[^A-Za-z0-9]+/g, "");
}
