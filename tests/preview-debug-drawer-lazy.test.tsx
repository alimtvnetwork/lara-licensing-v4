/**
 * Plan 16 Step 84: verify the tree-shake guard wrapper.
 *
 * In production the wrapper must return null WITHOUT importing the
 * drawer module (so Vite emits a separate chunk that stays unloaded).
 * In preview it must lazily resolve and render the toggle.
 */

import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import * as runtimeMode from "../src/lib/runtime-mode";

afterEach(() => {
  vi.restoreAllMocks();
});

describe("PreviewDebugDrawerLazy", () => {
  it("renders null in production without loading the drawer chunk", async () => {
    vi.spyOn(runtimeMode, "isPreview").mockReturnValue(false);
    vi.spyOn(runtimeMode, "isDev").mockReturnValue(false);
    const { PreviewDebugDrawerLazy } = await import(
      "../src/components/shell/PreviewDebugDrawerLazy"
    );
    const { container } = render(<PreviewDebugDrawerLazy />);
    expect(container.innerHTML).toBe("");
  });

  it("lazily renders the drawer toggle in preview", async () => {
    vi.spyOn(runtimeMode, "isPreview").mockReturnValue(true);
    vi.spyOn(runtimeMode, "isDev").mockReturnValue(false);
    vi.spyOn(runtimeMode, "getRuntimeMode").mockReturnValue({
      Mode: "preview",
      ApiBaseUrl: null,
      PreviewSeed: "default",
    });
    const { PreviewDebugDrawerLazy } = await import(
      "../src/components/shell/PreviewDebugDrawerLazy"
    );
    render(<PreviewDebugDrawerLazy />);
    const toggle = await screen.findByTestId("preview-debug-toggle", {}, { timeout: 3000 });
    expect(toggle.textContent).toContain("Debug:");
  });
});
