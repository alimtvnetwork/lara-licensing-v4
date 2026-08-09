import { describe, expect, it, afterEach } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { UserRolePicker } from "@/components/admin/user-role-picker";
import type { UserRoleEntry } from "@/lib/lara-user-role";

/**
 * Proves AC-EC-TEST-003 from spec/21-app/27-error-code-test-matrix.md and
 * AC-RETRY-002 from spec/21-app/25-retry-decision-matrix.md: the last active
 * Admin cannot be revoked from the UI (client-side guard) so the server never
 * needs to emit AuthzLastAdminProtected in normal flows. The block also
 * covers the self-admin-revoke rule from spec/21-app/19-user-management.md.
 */
function renderPicker(props: {
  entry: UserRoleEntry;
  adminActiveCount: number;
  currentCallerUserId: number | null;
  userId: number;
}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <UserRolePicker {...props} />
    </QueryClientProvider>,
  );
}

describe("<UserRolePicker /> last-admin guard", () => {
  afterEach(() => cleanup());

  it("blocks revoke on the Admin row when only one Admin remains", () => {
    renderPicker({
      userId: 7,
      currentCallerUserId: 999,
      adminActiveCount: 1,
      entry: { UserId: 7, Roles: ["Admin"] },
    });
    expect(screen.getByText("Cannot revoke the last active Admin.")).toBeDefined();
    const revokeButtons = screen.queryAllByRole("button", { name: "Revoke" });
    expect(revokeButtons.length).toBe(0);
  });

  it("blocks self Admin revoke even when other Admins exist", () => {
    renderPicker({
      userId: 42,
      currentCallerUserId: 42,
      adminActiveCount: 5,
      entry: { UserId: 42, Roles: ["Admin"] },
    });
    expect(screen.getByText("You cannot revoke your own Admin role.")).toBeDefined();
    expect(screen.queryAllByRole("button", { name: "Revoke" }).length).toBe(0);
  });

  it("allows revoke on Admin when another Admin exists and caller is different", () => {
    renderPicker({
      userId: 7,
      currentCallerUserId: 1,
      adminActiveCount: 3,
      entry: { UserId: 7, Roles: ["Admin"] },
    });
    const revokeButton = screen.getByRole("button", { name: "Revoke" });
    expect((revokeButton as HTMLButtonElement).disabled).toBe(false);
  });

  it("shows Grant for unassigned non-Admin roles regardless of admin count", () => {
    renderPicker({
      userId: 7,
      currentCallerUserId: 1,
      adminActiveCount: 1,
      entry: { UserId: 7, Roles: [] },
    });
    const grantButtons = screen.getAllByRole("button", { name: "Grant" });
    expect(grantButtons.length).toBe(4);
  });
});
