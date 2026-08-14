import { Link, Outlet, useNavigate } from "@tanstack/react-router";
import { Home, PanelLeft, ShieldCheck } from "lucide-react";

import { AppShell } from "../shell/AppShell";
import { AppSidebar } from "../shell/AppSidebar";
import { CommandPalette } from "../shell/CommandPalette";
import { PreviewDebugDrawerLazy } from "../shell/PreviewDebugDrawerLazy";
import { RuntimeModeFlipButton } from "../shell/RuntimeModeFlipButton";
import { ProfileMenu } from "../shell/ProfileMenu";
import { RoleChip } from "../shell/RoleChip";
import { TopbarSearch } from "../shell/TopbarSearch";
import { NotificationBell } from "../notifications/NotificationBell";
import { clearLaraSession } from "../../lib/lara-api-session";
import { useSidebarCollapsed } from "../../lib/lara-sidebar-collapsed";
import { LaraShellRoleContext } from "../../lib/lara-shell-role";

/**
 * Admin console shell. Composes the design-system `AppShell` primitive with
 * the role-scoped `AppSidebar` (nav-tree source of truth) and installs the
 * `Admin` shell-role context so cross-shell surfaces (UpdateBanner et al.)
 * gate correctly per spec/21-app/16-ui-surfaces.md §3a.
 */
export function AdminShell() {
  const signOut = useAdminSignOut();

  return (
    <LaraShellRoleContext.Provider value="Admin">
      <AppShell
        sidebar={<AdminSidebar onSignOut={signOut} />}
        topbar={<AdminTopbar onSignOut={signOut} />}
        pageContent={<Outlet />}
      />
      <AdminOverlays />
    </LaraShellRoleContext.Provider>
  );
}

function useAdminSignOut() {
  const navigate = useNavigate();

  return () => {
    clearLaraSession();
    void navigate({ to: "/admin/login", replace: true });
  };
}

function AdminOverlays() {
  return (
    <>
      <CommandPalette />
      <PreviewDebugDrawerLazy />
      <RuntimeModeFlipButton />
    </>
  );
}

function AdminSidebar({ onSignOut }: { onSignOut: () => void }) {
  return (
    <div className="flex h-full flex-col gap-4">
      <AdminBrand />
      <AppSidebar onSignOut={onSignOut} />
    </div>
  );
}

function AdminBrand() {
  return (
    <Link
      to="/"
      className="focus-ring flex h-11 items-center gap-2.5 rounded-lg px-2 font-semibold text-foreground surface-hover"
    >
      <AdminBrandIcon />
      <AdminBrandName />
    </Link>
  );
}

function AdminBrandIcon() {
  return (
    <span aria-hidden="true" className="brand-tile">
      <ShieldCheck className="size-4" />
    </span>
  );
}

function AdminBrandName() {
  return (
    <span
      data-collapse-hide="true"
      className="font-display tracking-tight"
      style={{ fontSize: "0.975rem" }}
    >
      Licensing Portal
    </span>
  );
}

function SidebarToggle() {
  const [collapsed, toggle] = useSidebarCollapsed();
  const label = collapsed ? "Expand sidebar" : "Collapse sidebar";

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={label}
      aria-pressed={collapsed}
      className="focus-ring inline-flex size-9 items-center justify-center rounded-md border border-input surface-hover"
    >
      <PanelLeft aria-hidden="true" className="size-4" />
    </button>
  );
}

function AdminTopbar({ onSignOut }: { onSignOut: () => void }) {
  return (
    <div className="flex w-full items-center justify-between gap-3">
      <AdminIdentity />
      <div className="hidden flex-1 justify-center md:flex">
        <TopbarSearch />
      </div>
      <div className="flex items-center gap-2">
        <NotificationBell />
        <Link to="/" className="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" aria-label="Back to Homepage">
          <Home className="size-4" />
        </Link>
        <div className="text-xs text-muted-foreground hidden lg:block px-2 py-1 rounded-md border border-border/50 bg-muted/30 truncate max-w-[200px] whitespace-nowrap">
          {"Plan 09: Fluid UI & cPanel Release (Steps 90/100)"}
        </div>
        <ProfileMenu onSignOut={onSignOut} />
      </div>
    </div>
  );
}

function AdminIdentity() {
  return (
    <div className="flex items-center gap-3">
      <SidebarToggle />
      <span
        className="text-sm font-semibold text-foreground"
        style={{ fontFamily: "var(--font-display)" }}
      >
        Admin console
      </span>
      <RoleChip />
    </div>
  );
}
