import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";

/**
 * Locks Identifier truncation and copy contract per
 * spec/24-app-ui-design-system/14-breadcrumbs-and-page-header.md §4.3 and
 * §8 (canonical `…` character, copy always emits full value).
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { Identifier, middleEllipsis } from "@/components/shell/Identifier";
import { toast } from "sonner";

const FULL_VALUE = "lic_01HXABCDEF0123456789TAIL";

beforeEach(() => {
  vi.clearAllMocks();
});

afterEach(cleanup);

describe("middleEllipsis", () => {
  it("returns value unchanged when within budget", () => {
    expect(middleEllipsis("short", 10)).toBe("short");
  });

  it("uses the canonical `…` character, never three dots", () => {
    const out = middleEllipsis(FULL_VALUE, 14);
    expect(out).toContain("…");
    expect(out).not.toContain("...");
  });

  it("preserves head and tail so identifiers stay recognizable", () => {
    const out = middleEllipsis(FULL_VALUE, 14);
    expect(out.startsWith("lic_01HX")).toBe(true);
    expect(out.endsWith("TAIL")).toBe(true);
  });
});

describe("Identifier", () => {
  it("copies the full untruncated value even when display is ellipsized", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });

    render(<Identifier value={FULL_VALUE} maxChars={14} resource="license id" />);
    fireEvent.click(screen.getByRole("button", { name: /copy license id/i }));

    await waitFor(() => expect(writeText).toHaveBeenCalledWith(FULL_VALUE));
    expect(toast.success).toHaveBeenCalled();
    expect(toast.error).not.toHaveBeenCalled();
  });

  it("surfaces an error toast when clipboard write rejects (no swallow)", async () => {
    const writeText = vi.fn().mockRejectedValue(new Error("denied"));
    Object.assign(navigator, { clipboard: { writeText } });
    const consoleError = vi.spyOn(console, "error").mockImplementation(() => undefined);

    render(<Identifier value={FULL_VALUE} resource="license id" />);
    fireEvent.click(screen.getByRole("button", { name: /copy license id/i }));

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    expect(consoleError).toHaveBeenCalled();
    consoleError.mockRestore();
  });
});
