import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { useSidebarCollapsed } from "@/lib/lara-sidebar-collapsed";

export interface AppShellProps {
  sidebar: ReactNode;
  topbar: ReactNode;
  pageHeader?: ReactNode;
  pageActions?: ReactNode;
  pageContent: ReactNode;
}

const PageActionsSlotContext = createContext<HTMLElement | null>(null);

export function AppShell(props: AppShellProps) {
  const [actionsSlot, setActionsSlot] = useState<HTMLElement | null>(null);
  const [collapsed] = useSidebarCollapsed();

  useEffect(() => {
    document.documentElement.setAttribute("data-app-shell", "true");
    return () => document.documentElement.removeAttribute("data-app-shell");
  }, []);

  return (
    <PageActionsSlotContext.Provider value={actionsSlot}>
      <div className="shell-app" data-sidebar-collapsed={collapsed ? "true" : undefined}>
        <aside
          className="sidebar-gradient border-r border-sidebar-border sticky top-0 h-dvh overflow-y-auto p-3"
          style={{ gridArea: "sidebar", zIndex: "var(--z-sidebar)" }}
          data-shell-region="sidebar"
        >
          {props.sidebar}
        </aside>
        
        <header
          className="glass-topbar sticky top-0 flex items-center px-4 lg:px-6"
          style={{
            gridArea: "topbar",
            height: "var(--shell-topbar)",
            zIndex: "var(--z-topbar)",
          }}
          data-shell-region="topbar"
        >
          {props.topbar}
        </header>

        <main
          className="app-canvas overflow-y-auto"
          style={{ gridArea: "main" }}
          data-shell-region="main"
        >
          <div className="page-content-container fade-in grid gap-8 [grid-template-rows:auto_auto_1fr]">
            {props.pageHeader && <div data-page-region="page-header">{props.pageHeader}</div>}
            <div 
              data-page-region="page-actions" 
              ref={setActionsSlot} 
              className="flex flex-wrap items-center justify-end gap-2 empty:hidden"
            >
              {props.pageActions}
            </div>
            <div data-page-content data-page-region="page-content">{props.pageContent}</div>
          </div>
        </main>
      </div>
    </PageActionsSlotContext.Provider>
  );
}

export function PageActions({ children }: { children: ReactNode }) {
  const slot = useContext(PageActionsSlotContext);
  if (!slot) return null;
  return createPortal(children, slot);
}
