// Plan 09 step 95 contract.
//
// Locks the shared `RoutePending` and `RouteErrorState` shells: pending
// renders a title skeleton + N row skeletons with `role=status` and
// includes the PageHeader so shell layout doesn't jump on hydration;
// error renders `role=alert`, the formatted API error copy, and a Retry
// button that calls both `router.invalidate()` and the boundary `reset`.

import { describe, expect, it, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { createMemoryHistory, createRootRoute, createRoute, createRouter, RouterProvider } from "@tanstack/react-router";

import { RoutePending, RouteErrorState } from "@/components/shell/RouteFallbacks";

function renderWithRouter(el: React.ReactElement) {
  const rootRoute = createRootRoute({ component: () => el });
  const idx = createRoute({ getParentRoute: () => rootRoute, path: "/", component: () => el });
  const router = createRouter({
    routeTree: rootRoute.addChildren([idx]),
    history: createMemoryHistory({ initialEntries: ["/"] }),
  });
  return { router, ...render(<RouterProvider router={router} />) };
}

describe("RoutePending", () => {
  it("renders the header title and N row skeletons", async () => {
    renderWithRouter(<RoutePending title="Users" rows={3} />);
    expect(await screen.findByText("Users")).toBeTruthy();
    // 1 title skeleton + 3 row skeletons = 4 role=status announcers.
    const announcers = screen.getAllByRole("status", { name: /loading/i });
    expect(announcers.length).toBe(4);
    expect(screen.getByTestId("route-pending")).toBeTruthy();
  });
});

describe("RouteErrorState", () => {
  it("renders formatted error copy under role=alert and retries via invalidate + reset", async () => {
    const reset = vi.fn();
    const { router } = renderWithRouter(
      <RouteErrorState title="App updates" error={new Error("boom")} reset={reset} />,
    );
    const invalidate = vi.spyOn(router, "invalidate").mockResolvedValue(undefined);
    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toContain("App updates could not be loaded");
    fireEvent.click(screen.getByRole("button", { name: /retry/i }));
    expect(invalidate).toHaveBeenCalledTimes(1);
    expect(reset).toHaveBeenCalledTimes(1);
  });
});
