import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";

/**
 * Locks the client-side role gate required by
 * spec/21-app/46-impersonation.md §4.3 clause 1 (Admin-only invocation) for
 * both impersonation UI controls. The server is still the source of truth
 * for PermissionDenied, but the UI MUST NOT render the trigger for non-Admin
 * callers even if the component is imported from another shell.
 */

vi.mock("@/lib/lara-impersonation", async () => {
  const actual = await vi.importActual<typeof import("@/lib/lara-impersonation")>(
    "@/lib/lara-impersonation",
  );
  return {
    ...actual,
    startImpersonation: vi.fn(),
    endImpersonation: vi.fn(),
  };
});

import { clearActiveImpersonation, saveActiveImpersonation } from "@/lib/lara-impersonation";
import { ImpersonateUserButton } from "@/components/admin/impersonate-user-button";
import { ForceEndImpersonationButton } from "@/components/admin/force-end-impersonation-button";

const nonAdminRoles = ["Reseller", "Support", "Auditor", null] as const;
const adminRoles = ["Admin", "SuperAdmin"] as const;

beforeEach(() => {
  clearActiveImpersonation();
});

afterEach(() => {
  cleanup();
  clearActiveImpersonation();
});

describe("<ImpersonateUserButton /> role gate", () => {
  it.each(nonAdminRoles)("renders nothing when callerRole = %s", (role) => {
    const { container } = render(
      <ImpersonateUserButton
        targetUserId={42}
        targetLabel="user@example.test"
        callerUserId={1}
        callerRole={role as never}
        onStarted={() => {}}
      />,
    );
    expect(container.firstChild).toBeNull();
  });

  it.each(adminRoles)("renders the trigger when callerRole = %s", (role) => {
    render(
      <ImpersonateUserButton
        targetUserId={42}
        targetLabel="user@example.test"
        callerUserId={1}
        callerRole={role}
        onStarted={() => {}}
      />,
    );
    expect(screen.getByRole("button", { name: /Impersonate user/i })).toBeDefined();
  });

  it("does not throw a hooks-order error when callerRole flips across renders", () => {
    // Regression: the role guard used to short-circuit BEFORE useState,
    // so a caller whose role flipped Admin -> Reseller -> Admin (loader
    // refetch, impersonation start/end) would crash with "Rendered fewer
    // hooks than expected". Hooks must run unconditionally.
    const props = {
      targetUserId: 42,
      targetLabel: "user@example.test",
      callerUserId: 1,
      onStarted: () => {},
    };
    const { rerender, container } = render(
      <ImpersonateUserButton {...props} callerRole="Admin" />,
    );
    expect(screen.getByRole("button", { name: /Impersonate user/i })).toBeDefined();
    rerender(<ImpersonateUserButton {...props} callerRole="Reseller" />);
    expect(container.firstChild).toBeNull();
    rerender(<ImpersonateUserButton {...props} callerRole="Admin" />);
    expect(screen.getByRole("button", { name: /Impersonate user/i })).toBeDefined();
  });
});


describe("<ForceEndImpersonationButton /> role gate", () => {
  it.each(nonAdminRoles)("renders nothing when callerRole = %s even with an active session", (role) => {
    saveActiveImpersonation({
      SessionId: "33333333-3333-4333-8333-333333333333",
      ImpersonatorUserId: 1,
      TargetUserId: 42,
      Kind: "Impersonation",
      ExpiresAt: new Date(Date.now() + 60_000).toISOString(),
    });
    const { container } = render(
      <ForceEndImpersonationButton targetUserId={42} callerRole={role as never} onEnded={() => {}} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it("renders nothing for Admin when there is no active session", () => {
    const { container } = render(
      <ForceEndImpersonationButton targetUserId={42} callerRole="Admin" onEnded={() => {}} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it.each(adminRoles)("renders the Force-end trigger for %s with an active session", (role) => {
    saveActiveImpersonation({
      SessionId: "44444444-4444-4444-8444-444444444444",
      ImpersonatorUserId: 1,
      TargetUserId: 42,
      Kind: "Impersonation",
      ExpiresAt: new Date(Date.now() + 60_000).toISOString(),
    });
    render(<ForceEndImpersonationButton targetUserId={42} callerRole={role} onEnded={() => {}} />);
    expect(screen.getByRole("button", { name: /Force-end impersonation/i })).toBeDefined();
  });
});
