/**
 * Plan 16 Step 83: preview debug drawer.
 *
 * Verifies: (1) drawer is null in production, (2) renders in preview,
 * (3) scenario select drives `setPreviewScenario` and log fires,
 * (4) hotkey Cmd+Shift+D toggles open state.
 */

import { describe, it, expect, beforeEach, vi, afterEach } from "vitest";
import { render, screen, fireEvent, cleanup, act } from "@testing-library/react";

import { freezeRuntimeMode, resetRuntimeMode } from "../src/lib/runtime-mode";
import {
  getPreviewScenario,
  resetPreviewScenarioForTest,
  setPreviewScenario,
} from "../src/lib/preview-scenario";
import { PreviewDebugDrawer } from "../src/components/shell/PreviewDebugDrawer";

function freezePreview(seed = "default"): void {
  freezeRuntimeMode({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: seed });
}

describe("PreviewDebugDrawer", () => {
  beforeEach(() => {
    resetRuntimeMode();
    resetPreviewScenarioForTest();
  });
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  it("renders nothing in production mode", () => {
    freezeRuntimeMode({ Mode: "production", ApiBaseUrl: "https://api.example.com", PreviewSeed: "default" });
    const { container } = render(<PreviewDebugDrawer />);
    expect(container.firstChild).toBeNull();
  });

  it("renders toggle in preview mode", () => {
    freezePreview();
    render(<PreviewDebugDrawer />);
    expect(screen.getByTestId("preview-debug-toggle")).toBeTruthy();
    expect(screen.queryByTestId("preview-debug-drawer")).toBeNull();
  });

  it("opens on toggle click and switches scenario", () => {
    freezePreview();
    const infoSpy = vi.spyOn(console, "info").mockImplementation(() => {});
    render(<PreviewDebugDrawer />);
    fireEvent.click(screen.getByTestId("preview-debug-toggle"));
    expect(screen.getByTestId("preview-debug-drawer")).toBeTruthy();
    fireEvent.change(screen.getByTestId("preview-debug-scenario"), { target: { value: "offline" } });
    expect(getPreviewScenario()).toBe("offline");
    expect(infoSpy).toHaveBeenCalledWith(
      "[preview-debug-drawer] scenario change",
      { From: null, To: "offline" },
    );
  });

  it("reflects external setPreviewScenario changes", () => {
    freezePreview();
    render(<PreviewDebugDrawer />);
    fireEvent.click(screen.getByTestId("preview-debug-toggle"));
    act(() => setPreviewScenario("slow"));
    const select = screen.getByTestId("preview-debug-scenario") as HTMLSelectElement;
    expect(select.value).toBe("slow");
  });

  it("toggles open state via Cmd+Shift+D hotkey", () => {
    freezePreview();
    render(<PreviewDebugDrawer />);
    expect(screen.queryByTestId("preview-debug-drawer")).toBeNull();
    fireEvent.keyDown(window, { key: "D", metaKey: true, shiftKey: true });
    expect(screen.getByTestId("preview-debug-drawer")).toBeTruthy();
    fireEvent.keyDown(window, { key: "d", ctrlKey: true, shiftKey: true });
    expect(screen.queryByTestId("preview-debug-drawer")).toBeNull();
  });
});
