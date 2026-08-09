import { createContext, useContext, type ReactNode } from "react";

export type LaraShellRoleType = "Admin" | "Reseller" | "EndUser";

export const LaraShellRoleContext = createContext<LaraShellRoleType | null>(null);

export function useLaraShellRole(): LaraShellRoleType | null {
  return useContext(LaraShellRoleContext);
}

export function shellRoleSeesUpdateBanner(role: LaraShellRoleType | null): boolean {
  return role === "EndUser"; // Matching spec/21-app/16-ui-surfaces.md §3a
}
