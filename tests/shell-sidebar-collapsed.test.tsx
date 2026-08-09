import { act, cleanup, render, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

/**
 * Locks the `LaraSidebarCollapsed` persistence contract per
 * spec/24-app-ui-design-system/13-navigation-ia.md §9:
 * - default is expanded (false) when nothing is stored,
 * - toggle writes the PascalCase key `LaraSidebarCollapsed`,
 * - a subsequent mount hydrates from localStorage without observing an
 *   expanded flash for SSR (getServerSnapshot returns false, so any
 *   collapsed value is applied post-mount via useEffect).
 */

import { useSidebarCollapsed } from "@/lib/lara-sidebar-collapsed";
import { AppShell } from "@/components/shell/AppShell";

const KEY = "LaraSidebarCollapsed";

beforeEach(() => { window.localStorage.removeItem(KEY); });
afterEach(() => {
  cleanup();
  window.localStorage.removeItem(KEY);
  document.documentElement.removeAttribute("data-app-shell");
});

describe("useSidebarCollapsed", () => {
  it("defaults to expanded (false) and persists toggled state under LaraSidebarCollapsed", () => {
    const { result } = renderHook(() => useSidebarCollapsed());
    expect(result.current[0]).toBe(false);
    act(() => { result.current[1](); });
    expect(result.current[0]).toBe(true);
    expect(window.localStorage.getItem(KEY)).toBe("1");
    act(() => { result.current[1](); });
    expect(result.current[0]).toBe(false);
    expect(window.localStorage.getItem(KEY)).toBe("0");
  });

  it("applies data-sidebar-collapsed on .shell-app when persisted state is collapsed", () => {
    window.localStorage.setItem(KEY, "1");
    const { container } = render(
      <AppShell sidebar={<span />} topbar={<span />} pageContent={<span />} />,
    );
    const shell = container.querySelector(".shell-app");
    expect(shell?.getAttribute("data-sidebar-collapsed")).toBe("true");
  });
});
