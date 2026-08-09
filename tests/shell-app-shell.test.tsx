import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render } from "@testing-library/react";

/**
 * Locks AppShell scroll-lock and region contract per
 * spec/24-app-ui-design-system/12-shell-layout.md §3:
 * - mounting sets `data-app-shell="true"` on <html>; unmount restores the
 *   prior value (or removes the attribute if none). This is what activates
 *   the scroll-lock CSS in `src/styles.css`; a regression would leave the
 *   whole app scrolling twice or the shell unable to size.
 * - the four named regions (sidebar, topbar, main sub-grid: page-header,
 *   page-actions, page-content) render with their `data-*-region` markers
 *   so the CSS grid areas can be verified without pixel snapshots.
 */

import { AppShell, PageActions } from "@/components/shell/AppShell";

afterEach(() => {
  cleanup();
  document.documentElement.removeAttribute("data-app-shell");
});

describe("AppShell", () => {
  it("sets data-app-shell='true' on <html> while mounted and clears it on unmount", () => {
    expect(document.documentElement.getAttribute("data-app-shell")).toBeNull();
    const { unmount } = render(
      <AppShell sidebar={<span />} topbar={<span />} pageContent={<span />} />,
    );
    expect(document.documentElement.getAttribute("data-app-shell")).toBe("true");
    unmount();
    expect(document.documentElement.getAttribute("data-app-shell")).toBeNull();
  });

  it("restores the prior data-app-shell value on unmount (nested shells)", () => {
    document.documentElement.setAttribute("data-app-shell", "outer");
    const { unmount } = render(
      <AppShell sidebar={<span />} topbar={<span />} pageContent={<span />} />,
    );
    expect(document.documentElement.getAttribute("data-app-shell")).toBe("true");
    unmount();
    expect(document.documentElement.getAttribute("data-app-shell")).toBe("outer");
  });

  it("renders the four named grid regions with region markers", () => {
    const { container } = render(
      <AppShell
        sidebar={<span data-testid="s" />}
        topbar={<span data-testid="t" />}
        pageHeader={<span data-testid="ph" />}
        pageActions={<span data-testid="pa" />}
        pageContent={<span data-testid="pc" />}
      />,
    );
    expect(container.querySelector("[data-shell-region='sidebar']")).not.toBeNull();
    expect(container.querySelector("[data-shell-region='topbar']")).not.toBeNull();
    expect(container.querySelector("[data-shell-region='main']")).not.toBeNull();
    expect(container.querySelector("[data-page-region='page-header']")).not.toBeNull();
    expect(container.querySelector("[data-page-region='page-actions']")).not.toBeNull();
    expect(container.querySelector("[data-page-region='page-content']")).not.toBeNull();
  });

  it("keeps page-actions region mounted (empty) so PageActions portal has a target", () => {
    const { container } = render(
      <AppShell sidebar={<span />} topbar={<span />} pageContent={<span />} />,
    );
    expect(container.querySelector("[data-page-region='page-header']")).toBeNull();
    const actions = container.querySelector("[data-page-region='page-actions']");
    expect(actions).not.toBeNull();
    expect(actions?.childElementCount).toBe(0);
    expect(container.querySelector("[data-page-region='page-content']")).not.toBeNull();
  });

  it("PageActions portal projects children into the page-actions region", () => {
    const { container, getByText } = render(
      <AppShell
        sidebar={<span />}
        topbar={<span />}
        pageContent={
          <PageActions>
            <button type="button">New reseller</button>
          </PageActions>
        }
      />,
    );
    const actions = container.querySelector("[data-page-region='page-actions']");
    expect(actions).not.toBeNull();
    expect(actions?.contains(getByText("New reseller"))).toBe(true);
  });
});
