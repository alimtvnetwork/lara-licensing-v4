// removed jest-dom import to avoid resolution error
import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { DemoLoginPanel } from "@/components/auth/DemoLoginPanel";
import { getRuntimeMode } from "@/lib/runtime-mode";
import { useHydrated } from "@/hooks/use-hydrated";
import { signInWithSeedIdentity } from "@/lib/preview-auth";
import { vi } from "vitest";

// Mock the dependencies
vi.mock("@/lib/runtime-mode", () => ({
  getRuntimeMode: vi.fn(),
}));

vi.mock("@/hooks/use-hydrated", () => ({
  useHydrated: vi.fn(),
}));

vi.mock("@/lib/preview-auth", () => ({
  DEMO_IDENTITIES: [
    {
      id: "admin",
      label: "Super Admin",
      email: "admin@lara.local",
      role: "admin",
      description: "Full platform control",
    },
  ],
  signInWithSeedIdentity: vi.fn().mockResolvedValue(undefined),
}));

// Mock sonner toast
vi.mock("sonner", () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  },
}));

describe("DemoLoginPanel", () => {
  const onSuccess = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("renders all demo identities", () => {
    render(<DemoLoginPanel onSuccess={onSuccess} />);
    
    expect(screen.getByText("Super Admin")).toBeTruthy();
    expect(screen.getByText("Full platform control")).toBeTruthy();
  });

  it("calls signInWithSeedIdentity and onSuccess when Quick Sign-in is clicked", async () => {
    render(<DemoLoginPanel onSuccess={onSuccess} />);
    
    const signInButton = screen.getByTestId("demo-login-admin");
    fireEvent.click(signInButton);
    
    await waitFor(() => {
      expect(signInWithSeedIdentity).toHaveBeenCalledWith("admin");
      expect(onSuccess).toHaveBeenCalled();
    });
  });

  it("copies email to clipboard when copy button is clicked", async () => {
    // Mock clipboard API
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, {
      clipboard: {
        writeText,
      },
    });

    render(<DemoLoginPanel onSuccess={onSuccess} />);
    
    const copyButton = screen.getByTitle("Copy email");
    fireEvent.click(copyButton);
    
    expect(writeText).toHaveBeenCalledWith("admin@lara.local");
  });
});
