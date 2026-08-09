import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";

/**
 * Locks AppSidebar contracts per
 * spec/24-app-ui-design-system/13-navigation-ia.md §2, §3, §10:
 * - deepest-prefix match wins for active-route detection (§10)
 * - `status === "D"` renders `<span aria-disabled="true">` with a
 *   "Coming soon" tooltip, never a link that would 403 (§3, AC-ADS-043)
 * - active item exposes `aria-current="page"` (AC-ADS-045)
 * - `role === null` renders nothing (shell not yet declared)
 * - `SignOut` action invokes the caller-supplied handler
 * - reseller `$resellerId` placeholder is substituted in resolved routes
 */

let mockPathname = "/admin";

vi.mock("@tanstack/react-router", () => ({
  Link: ({ to, children, ...rest }: { to: string; children: React.ReactNode }) => (
    <a href={to} {...rest}>{children}</a>
  ),
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    select({ location: { pathname: mockPathname } }),
}));

import { AppSidebar } from "@/components/shell/AppSidebar";
import { LaraShellRoleContext, type LaraShellRoleType } from "@/lib/lara-shell-role";

function renderWithRole(role: LaraShellRoleType | null, props: React.ComponentProps<typeof AppSidebar> = {}) {
  return render(
    <LaraShellRoleContext.Provider value={role}>
      <AppSidebar {...props} />
    </LaraShellRoleContext.Provider>,
  );
}

afterEach(() => {
  cleanup();
  mockPathname = "/admin";
});

describe("AppSidebar", () => {
  it("renders nothing when role is unset", () => {
    const { container } = renderWithRole(null);
    expect(container.firstChild).toBeNull();
  });

  it("marks the active admin route with aria-current='page' on exact match", () => {
    mockPathname = "/admin/licenses";
    renderWithRole("Admin");
    const link = screen.getByRole("link", { name: /licenses/i });
    expect(link.getAttribute("aria-current")).toBe("page");
    expect(link.getAttribute("href")).toBe("/admin/licenses");
  });

  it("resolves deepest-prefix match, keeping parent active on child pathnames", () => {
    mockPathname = "/admin/licenses/new";
    renderWithRole("Admin");
    const link = screen.getByRole("link", { name: /licenses/i });
    expect(link.getAttribute("aria-current")).toBe("page");
  });

  it("renders deferred items as aria-disabled spans (no Forbidden on click)", () => {
    renderWithRole("Admin");
    const overview = screen.getByText("Overview");
    const row = overview.closest("[aria-disabled='true']");
    expect(row).not.toBeNull();
    expect(row?.getAttribute("title")).toBe("Coming soon");
    expect(screen.queryByRole("link", { name: /^overview$/i })).toBeNull();
  });

  it("invokes onSignOut when the Sign out button is clicked", () => {
    const onSignOut = vi.fn();
    renderWithRole("Admin", { onSignOut });
    fireEvent.click(screen.getByRole("button", { name: /sign out/i }));
    expect(onSignOut).toHaveBeenCalledTimes(1);
  });

  it("substitutes $resellerId placeholder in the reseller tree", () => {
    mockPathname = "/reseller/rsl_ABC/licenses";
    renderWithRole("Reseller", { resellerId: "rsl_ABC" });
    const link = screen.getByRole("link", { name: /^licenses$/i });
    expect(link.getAttribute("href")).toBe("/reseller/rsl_ABC/licenses");
    expect(link.getAttribute("aria-current")).toBe("page");
  });

  it("pins the Account group to inline-end-bottom via margin-block-start:auto", () => {
    const { container } = renderWithRole("Admin");
    const account = container.querySelector("[data-nav-group='Account']") as HTMLElement | null;
    expect(account).not.toBeNull();
    expect(account?.style.marginBlockStart).toBe("auto");
  });
});
