import { createContext, useContext } from "react";

/**
 * Shell role for gating cross-shell surfaces per spec/21-app/16-ui-surfaces.md §3a.
 * The update banner MUST render only for `AppBuilder` and `EndUser`; never for
 * `Admin` or `Reseller` shells. Consumers set `LaraShellRoleContext.Provider`
 * on their shell root; unset (null) means "not yet declared" and gated
 * surfaces render nothing.
 */
export type LaraShellRoleType = "Admin" | "AppBuilder" | "EndUser" | "Reseller";

export const LaraShellRoleContext = createContext<LaraShellRoleType | null>(null);

export function useLaraShellRole(): LaraShellRoleType | null {
  return useContext(LaraShellRoleContext);
}

export function shellRoleSeesUpdateBanner(role: LaraShellRoleType | null): boolean {
  return role === "AppBuilder" || role === "EndUser";
}
