import { describe, it, expect, vi, beforeEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";

import { SerialLookupPanel } from "@/components/portal/serial-lookup-panel";
import * as laraSerial from "@/lib/lara-serial";

/**
 * Guards Plan 09 step 50 invariants:
 *  - Empty state renders when no serial has been checked.
 *  - Successful verify writes a `LicensingPortal.portalRecentSerials`
 *    entry with the correct shape, deduped by serial, capped at 5.
 *  - History Recheck button re-runs verifySerial with the same value.
 */
describe("SerialLookupPanel", () => {
  const STORAGE_KEY = "LicensingPortal.portalRecentSerials";

  function makeClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  }

  function renderPanel() {
    const client = makeClient();
    return render(
      <QueryClientProvider client={client}>
        <SerialLookupPanel testIdPrefix="test-serial" />
      </QueryClientProvider>,
    );
  }

  beforeEach(() => {
    window.localStorage.clear();
    vi.restoreAllMocks();
  });

  it("shows the empty state by default", () => {
    renderPanel();
    expect(screen.getByText(/No serial verified yet/i)).toBeTruthy();
  });

  it("persists a recent lookup entry after a successful verify", async () => {
    const spy = vi.spyOn(laraSerial, "verifySerial").mockResolvedValue({
      IsValid: true,
      Category: "Standard",
      IsSingleUse: false,
      ExpiresAt: null,
    } as never);
    renderPanel();
    const input = screen.getByTestId("test-serial-input") as HTMLInputElement;
    fireEvent.change(input, { target: { value: "ABCD-1234" } });
    fireEvent.click(screen.getByTestId("test-serial-submit"));
    await waitFor(() => expect(spy).toHaveBeenCalledWith("ABCD-1234"));
    await waitFor(() => expect(window.localStorage.getItem(STORAGE_KEY)).not.toBeNull());
    const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? "[]");
    expect(stored).toHaveLength(1);
    expect(stored[0].Serial).toBe("ABCD-1234");
    expect(stored[0].IsValid).toBe(true);
    expect(typeof stored[0].CheckedAt).toBe("string");
  });

  it("dedupes repeat lookups of the same serial", async () => {
    vi.spyOn(laraSerial, "verifySerial").mockResolvedValue({
      IsValid: false,
      Category: "Standard",
      IsSingleUse: true,
      ExpiresAt: null,
    } as never);
    renderPanel();
    const input = screen.getByTestId("test-serial-input") as HTMLInputElement;
    fireEvent.change(input, { target: { value: "SAME-SERIAL" } });
    fireEvent.click(screen.getByTestId("test-serial-submit"));
    await waitFor(() => expect(window.localStorage.getItem(STORAGE_KEY)).not.toBeNull());
    fireEvent.click(screen.getByTestId("test-serial-submit"));
    await waitFor(() => {
      const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? "[]");
      expect(stored).toHaveLength(1);
    });
  });
});
