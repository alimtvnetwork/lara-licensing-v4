import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";

/**
 * Locks the behavioral contract of <ImpersonationBanner /> per
 * spec/21-app/46-impersonation.md AC-IMP-008 (banner MUST render on every
 * _authenticated route while a session is active, must display the target
 * and remaining TTL, and must expose an End action). The repo has no
 * pixel-level visual regression harness, so this suite exercises the same
 * observable surface (ARIA role, DOM text, control state) that AC-IMP-008
 * targets. Keeping this next to update-banner.test.tsx mirrors the update
 * banner harness the user asked us to follow.
 */

vi.mock("@/lib/lara-impersonation", async () => {
  const actual = await vi.importActual<typeof import("@/lib/lara-impersonation")>(
    "@/lib/lara-impersonation",
  );
  return { ...actual, endImpersonation: vi.fn() };
});

import {
  clearActiveImpersonation,
  endImpersonation,
  saveActiveImpersonation,
} from "@/lib/lara-impersonation";
import { ImpersonationBanner } from "@/components/impersonation-banner";

const endImpersonationMock = vi.mocked(endImpersonation);

function futureIso(secondsFromNow: number): string {
  return new Date(Date.now() + secondsFromNow * 1000).toISOString();
}

function activeEnvelope(overrides: Partial<{ ExpiresAt: string; TargetUserId: number }> = {}) {
  return {
    SessionId: "22222222-2222-4222-8222-222222222222",
    ImpersonatorUserId: 1,
    TargetUserId: overrides.TargetUserId ?? 42,
    Kind: "Impersonation" as const,
    ExpiresAt: overrides.ExpiresAt ?? futureIso(30 * 60),
  };
}

beforeEach(() => {
  endImpersonationMock.mockReset();
  clearActiveImpersonation();
});

afterEach(() => {
  cleanup();
  clearActiveImpersonation();
});

describe("<ImpersonationBanner />", () => {
  it("renders nothing when no impersonation session is active (AC-IMP-008 negative)", () => {
    const { container } = render(<ImpersonationBanner />);
    expect(container.firstChild).toBeNull();
  });

  it("renders target user id, remaining TTL, and an End button when active", () => {
    saveActiveImpersonation(activeEnvelope({ ExpiresAt: futureIso(125) }));
    render(<ImpersonationBanner />);
    expect(screen.getByRole("status")).toBeDefined();
    expect(screen.getByText(/Impersonating user #42/)).toBeDefined();
    expect(screen.getByText(/Ends in 2m/)).toBeDefined();
    expect(screen.getByRole("button", { name: /Return to Admin/i })).toBeDefined();
  });

  it("shows 'Session expired' when ExpiresAt is in the past", () => {
    saveActiveImpersonation(activeEnvelope({ ExpiresAt: futureIso(-10) }));
    render(<ImpersonationBanner />);
    expect(screen.getByText(/Session expired/)).toBeDefined();
  });

  it("clicking End invokes endImpersonation with OperatorEnded reason and disables the button while pending", async () => {
    saveActiveImpersonation(activeEnvelope());
    let resolve!: () => void;
    endImpersonationMock.mockImplementationOnce(
      () =>
        new Promise((r) => {
          resolve = () => r({ Ended: true } as never);
        }),
    );
    render(<ImpersonationBanner />);
    const btn = screen.getByRole("button", { name: /Return to Admin/i }) as HTMLButtonElement;
    await act(async () => {
      fireEvent.click(btn);
    });
    expect(endImpersonationMock).toHaveBeenCalledOnce();
    expect(endImpersonationMock.mock.calls[0][0]).toBe("OperatorEnded");
    expect(typeof endImpersonationMock.mock.calls[0][1]).toBe("string");
    expect(btn.disabled).toBe(true);
    await act(async () => {
      resolve();
      await Promise.resolve();
    });
  });

  it("surfaces the error message when endImpersonation rejects", async () => {
    saveActiveImpersonation(activeEnvelope());
    endImpersonationMock.mockRejectedValueOnce(new Error("network down"));
    render(<ImpersonationBanner />);
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /Return to Admin/i }));
    });
    expect(await screen.findByText("network down")).toBeDefined();
  });
});
