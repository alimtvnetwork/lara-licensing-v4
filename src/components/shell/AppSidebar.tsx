/**
 * AppSidebar: role-scoped sidebar tree per
 * spec/24-app-ui-design-system/13-navigation-ia.md §2-§10.
 *
 * Root cause of prior drift: every shell hand-rolled its own nav. This
 * component reads the frozen tree from `nav-tree.ts` and renders three
 * groups (Primary, Ops, Account pinned to inline-end-bottom) with
 * spec-conformant active-route styling and Hidden/Disabled semantics.
 *
 * Ops badges (§11), portal switcher (§9), and collapsed-sidebar state
 * are follow-ups; this file ships the static tree.
 */
import { Link, useRouterState } from "@tanstack/react-router";
import type { LucideIcon } from "lucide-react";

import { useLaraShellRole } from "@/lib/lara-shell-role";
import { navTreeForRole, resolveRoute, type NavGroupType, type NavItemType } from "./nav-tree";

interface AppSidebarProps {
  /** Reseller id substituted into `$resellerId` route placeholders. */
  resellerId?: string | null;
  /** Sign-out handler invoked when the user clicks the Sign out action. */
  onSignOut?: () => void;
}

const groupOrder: NavGroupType[] = ["Primary", "Ops", "Account"];
const groupLabel: Record<NavGroupType, string> = {
  Primary: "Workspace",
  Ops: "Ops",
  Account: "Account",
};

export function AppSidebar({ resellerId = null, onSignOut }: AppSidebarProps) {
  const role = useLaraShellRole();
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  if (role === null) return null;
  const tree = navTreeForRole(role);
  const activeRoute = pickActiveRoute(tree, pathname, resellerId);

  return (
    <nav
      aria-label="Primary navigation"
      className="flex h-full flex-col gap-3"
      style={{ fontFamily: "var(--font-sans)", fontSize: "0.875rem", lineHeight: 1.4 }}
    >
      {groupOrder.map((group, index) => (
        <NavGroupBlock
          key={group}
          group={group}
          items={tree.filter((i) => i.group === group)}
          resellerId={resellerId}
          activeRoute={activeRoute}
          onSignOut={onSignOut}
          showTopDivider={index > 0}
        />
      ))}
    </nav>
  );
}

interface NavGroupBlockProps {
  group: NavGroupType;
  items: NavItemType[];
  resellerId: string | null;
  activeRoute: string | null;
  onSignOut?: () => void;
  showTopDivider?: boolean;
}

function NavGroupBlock(props: NavGroupBlockProps) {
  if (props.items.length === 0) return null;
  const pinBottom = props.group === "Account" ? { marginBlockStart: "auto" } : undefined;

  return (
    <div className="flex flex-col gap-1" style={pinBottom} data-nav-group={props.group}>
      {props.showTopDivider ? (
        <div
          aria-hidden="true"
          data-collapse-hide="true"
          className="mx-3 mb-1 h-px"
          style={{
            backgroundImage:
              "linear-gradient(90deg, transparent, color-mix(in oklab, var(--border) 80%, transparent) 20%, color-mix(in oklab, var(--border) 80%, transparent) 80%, transparent)",
          }}
        />
      ) : null}
      <div
        className="flex items-center gap-2 px-3 pb-1 uppercase text-muted-foreground"
        style={{
          fontFamily: "var(--font-sans)",
          fontSize: "0.6875rem",
          fontWeight: 600,
          letterSpacing: "0.08em",
        }}
        data-collapse-hide="true"
      >
        <span
          aria-hidden="true"
          className="inline-block h-1 w-1 rounded-full"
          style={{ backgroundImage: "linear-gradient(180deg, var(--primary), var(--accent))" }}
        />
        {groupLabel[props.group]}
      </div>
      {props.items.map((item) => (
        <NavItemRow
          key={item.label + (item.route ?? "action")}
          item={item}
          resellerId={props.resellerId}
          activeRoute={props.activeRoute}
          onSignOut={props.onSignOut}
        />
      ))}
    </div>
  );
}

interface NavItemRowProps {
  item: NavItemType;
  resellerId: string | null;
  activeRoute: string | null;
  onSignOut?: () => void;
}

function NavItemRow({ item, resellerId, activeRoute, onSignOut }: NavItemRowProps) {
  const resolved = resolveRoute(item.route, resellerId);
  const disabled = item.status === "D";
  const isActive = resolved !== null && resolved === activeRoute;
  const Icon = item.icon;
  if (item.action === "SignOut") {
    return <SignOutButton icon={Icon} label={item.label} onSignOut={onSignOut} />;
  }
  if (disabled || resolved === null) {
    return <DisabledRow icon={Icon} label={item.label} reason="Coming soon" />;
  }

  return <LinkRow icon={Icon} label={item.label} to={resolved} active={isActive} />;
}

function LinkRow(props: { icon: LucideIcon; label: string; to: string; active: boolean }) {
  const Icon = props.icon;
  const activeStyle = props.active
    ? {
        backgroundImage:
          "linear-gradient(90deg, color-mix(in oklab, var(--primary) 18%, transparent), color-mix(in oklab, var(--primary) 6%, transparent))",
        color: "var(--primary)",
        fontWeight: 600,
        boxShadow: "inset 0 0 0 1px color-mix(in oklab, var(--primary) 22%, transparent)",
      }
    : undefined;

  return (
    <Link
      to={props.to}
      aria-current={props.active ? "page" : undefined}
      className="focus-ring relative flex items-center gap-3 rounded-lg px-3 py-2 text-foreground transition-colors surface-hover"
      style={activeStyle}
    >
      {props.active ? (
        <span
          aria-hidden
          className="absolute inset-y-1.5 left-0 w-[3px] rounded-full"
          style={{
            backgroundImage: "linear-gradient(180deg, var(--primary), var(--accent))",
          }}
        />
      ) : null}
      <Icon className="size-4 shrink-0" aria-hidden />
      <span className="truncate" data-collapse-hide="true">
        {props.label}
      </span>
    </Link>
  );
}

function DisabledRow(props: { icon: LucideIcon; label: string; reason: string }) {
  const Icon = props.icon;

  return (
    <span
      aria-disabled="true"
      title={props.reason}
      className="flex cursor-not-allowed items-center gap-3 rounded-md px-3 py-2 text-muted-foreground opacity-70"
    >
      <Icon className="size-4" aria-hidden />
      <span className="truncate" data-collapse-hide="true">
        {props.label}
      </span>
    </span>
  );
}

function SignOutButton(props: { icon: LucideIcon; label: string; onSignOut?: () => void }) {
  const Icon = props.icon;

  return (
    <button
      type="button"
      onClick={props.onSignOut}
      className="focus-ring flex items-center gap-3 rounded-md px-3 py-2 text-left text-foreground surface-hover"
    >
      <Icon className="size-4" aria-hidden />
      <span className="truncate" data-collapse-hide="true">
        {props.label}
      </span>
    </button>
  );
}

/** Deepest-match wins per spec §10. */
function pickActiveRoute(
  tree: NavItemType[],
  pathname: string,
  resellerId: string | null,
): string | null {
  let best: string | null = null;
  for (const item of tree) {
    const resolved = resolveRoute(item.route, resellerId);
    if (resolved === null) continue;
    if (pathname === resolved || pathname.startsWith(resolved + "/")) {
      if (best === null || resolved.length > best.length) best = resolved;
    }
  }

  return best;
}
