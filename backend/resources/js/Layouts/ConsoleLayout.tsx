import { type ReactNode } from "react";
import { Link, usePage } from "@inertiajs/react";
import { ShieldCheck, PanelLeft, Search, User as UserIcon, LogOut } from "lucide-react";
import { AppShell } from "./AppShell";
import { AppSidebar } from "./AppSidebar";
import { useSidebarCollapsed } from "@/lib/lara-sidebar-collapsed";
import { LaraShellRoleContext } from "@/lib/lara-shell-role";
import { UpdateBanner } from "@/Components/shell/UpdateBanner";

export default function ConsoleLayout({ children }: { children: ReactNode }) {
  const { auth } = usePage().props as any;
  const role = auth.user?.RoleName || "User";
  const [collapsed, toggleCollapsed] = useSidebarCollapsed();

  // Plan 06 step 68: /logout is role-agnostic now; /admin/logout was inside
  // the require.role:Admin|SuperAdmin group and 403'd for Resellers.
  const handleSignOut = () => {
    window.location.href = "/logout";
  };

  const shellLabel =
    role === "Reseller" ? "Reseller portal" : role === "EndUser" ? "Portal" : "Admin console";

  return (
    <LaraShellRoleContext.Provider value={role}>
      <AppShell
        sidebar={
          <div className="flex h-full flex-col gap-4">
            <Link href="/portal" className="focus-ring flex h-11 items-center gap-2.5 rounded-lg px-2 font-semibold text-foreground surface-hover">
              <span className="brand-tile"><ShieldCheck className="size-4" /></span>
              <span data-collapse-hide="true" className="font-display tracking-tight text-[0.975rem]">Licensing Portal</span>
            </Link>
            <AppSidebar onSignOut={handleSignOut} />
          </div>
        }
        topbar={
          <div className="flex w-full items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <button 
                onClick={toggleCollapsed}
                className="focus-ring inline-flex size-9 items-center justify-center rounded-md border border-input surface-hover"
              >
                <PanelLeft className="size-4" />
              </button>
              <span className="text-sm font-semibold font-display">{shellLabel}</span>
            </div>
            <div className="flex items-center gap-2">
              <div className="flex items-center gap-2 px-3 py-1.5 rounded-md border border-input bg-background text-sm font-medium">
                <UserIcon className="size-4 text-muted-foreground" />
                <span>{auth.user?.DisplayName}</span>
                <span className="text-xs text-muted-foreground ml-1">{role}</span>
              </div>
            </div>
          </div>
        }
        pageContent={
          <>
            <UpdateBanner />
            {children}
          </>
        }
      />
    </LaraShellRoleContext.Provider>
  );
}
