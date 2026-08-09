import { Link, usePage } from "@inertiajs/react";
import { type LucideIcon } from "lucide-react";
import { navTreeForRole, resolveRoute } from "@/lib/nav-tree";

interface AppSidebarProps {
  onSignOut?: () => void;
}

export function AppSidebar({ onSignOut }: AppSidebarProps) {
  const { auth } = usePage().props as any;
  const role = auth.user?.RoleName || "User";
  const tenantId = auth.user?.TenantId;
  const pathname = window.location.pathname;

  const tree = navTreeForRole(role);
  
  return (
    <nav aria-label="Primary navigation" className="flex h-full flex-col gap-3">
      <div className="flex flex-col gap-1">
        {tree.map((item) => {
          const resolved = resolveRoute(item.route, tenantId);
          const Icon = item.icon;
          const isActive = resolved && (pathname === resolved || pathname.startsWith(resolved + "/"));

          if (item.action === "SignOut") {
            return (
              <button
                key="signout"
                onClick={onSignOut}
                className="focus-ring flex items-center gap-3 rounded-md px-3 py-2 text-left text-foreground surface-hover"
              >
                <Icon className="size-4 shrink-0" />
                <span className="truncate" data-collapse-hide="true">{item.label}</span>
              </button>
            );
          }

          return (
            <Link
              key={item.label}
              href={resolved || "#"}
              className={`focus-ring flex items-center gap-3 rounded-lg px-3 py-2 transition-colors surface-hover ${
                isActive ? "text-primary font-semibold bg-primary/10" : "text-foreground"
              }`}
            >
              <Icon className="size-4 shrink-0" />
              <span className="truncate" data-collapse-hide="true">{item.label}</span>
            </Link>
          );
        })}
      </div>
    </nav>
  );
}
