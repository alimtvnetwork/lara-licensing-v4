import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { createMemoryHistory, createRootRoute, createRoute, createRouter, RouterProvider } from "@tanstack/react-router";
import { StateError, StateForbidden, StateNotFound } from "@/components/state";

function renderWithRouter(el: React.ReactElement) {
  const rootRoute = createRootRoute({ component: () => el });
  const idx = createRoute({ getParentRoute: () => rootRoute, path: "/", component: () => el });
  const router = createRouter({
    routeTree: rootRoute.addChildren([idx]),
    history: createMemoryHistory({ initialEntries: ["/"] }),
  });
  return render(<RouterProvider router={router} />);
}

describe("route-state components", () => {
  const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
  const info = vi.spyOn(console, "info").mockImplementation(() => {});
  const err = vi.spyOn(console, "error").mockImplementation(() => {});
  beforeEach(() => { warn.mockClear(); info.mockClear(); err.mockClear(); });
  afterEach(() => { vi.clearAllMocks(); });

  it("Forbidden emits warn telemetry once and focuses h1", async () => {
    renderWithRouter(<StateForbidden route="/admin" attemptedPermissionKey="Admin.Read" requestId="req-abcdef123456" />);
    const h1 = await screen.findByRole("heading", { level: 1 });
    expect(h1.textContent).toContain("do not have access");
    expect(warn).toHaveBeenCalledTimes(1);
    const payload = warn.mock.calls[0][1] as { Event: string; AttemptedPermissionKey: string };
    expect(payload.Event).toBe("RouteForbidden");
    expect(payload.AttemptedPermissionKey).toBe("Admin.Read");
  });

  it("NotFound emits info telemetry with attempted path", async () => {
    renderWithRouter(<StateNotFound route="/x" attemptedPath="/does/not/exist" />);
    await screen.findByRole("heading", { level: 1 });
    expect(info).toHaveBeenCalledTimes(1);
    const payload = info.mock.calls[0][1] as { AttemptedPath: string; RequestId: string | null };
    expect(payload.AttemptedPath).toBe("/does/not/exist");
    expect(payload.RequestId).toBeNull();
  });

  it("Error emits error telemetry with ErrorCode extraction", async () => {
    const e = new Error("boom") as Error & { code?: string };
    e.code = "UnknownServerError";
    renderWithRouter(<StateError route="/y" error={e} reset={() => {}} requestId="req-1" />);
    await screen.findByRole("heading", { level: 1 });
    expect(err).toHaveBeenCalledTimes(1);
    const payload = err.mock.calls[0][1] as { ErrorCode: string; Message: string };
    expect(payload.ErrorCode).toBe("UnknownServerError");
    expect(payload.Message).toBe("boom");
  });

  it("NotFound omits RequestId chip when null (unmatched URL)", async () => {
    renderWithRouter(<StateNotFound route="/x" attemptedPath="/nope" />);
    await screen.findByRole("heading", { level: 1 });
    expect(screen.queryByLabelText(/Copy request id/)).toBeNull();
  });

  it("Error renders 'unknown' fallback when both operationId and requestId are missing", async () => {
    const e = new Error("no ids") as Error & { code?: string };
    e.code = "UnknownServerError";
    renderWithRouter(<StateError route="/z" error={e} reset={() => {}} />);
    await screen.findByRole("heading", { level: 1 });
    const strip = screen.getByTestId("route-error-correlation");
    expect(strip).toBeTruthy();
    const opCell = screen.getByTestId("route-error-operation-id");
    const reqCell = screen.getByTestId("route-error-request-id");
    expect(opCell.textContent).toBe("unknown");
    expect(reqCell.textContent).toBe("unknown");
    expect(opCell.getAttribute("data-missing")).toBe("true");
    expect(reqCell.getAttribute("data-missing")).toBe("true");
  });

  it("Error still renders real operationId when requestId is missing", async () => {
    const e = new Error("half ids") as Error & { code?: string };
    e.code = "UnknownServerError";
    renderWithRouter(<StateError route="/z" error={e} reset={() => {}} operationId="op-123" />);
    await screen.findByRole("heading", { level: 1 });
    expect(screen.getByTestId("route-error-operation-id").textContent).toBe("op-123");
    const reqCell = screen.getByTestId("route-error-request-id");
    expect(reqCell.textContent).toBe("unknown");
    expect(reqCell.getAttribute("data-missing")).toBe("true");
    expect(screen.getByTestId("route-error-operation-id").getAttribute("data-missing")).toBeNull();
  });

  it("Forbidden hides correlation strip entirely when both ids are missing (no fallback)", async () => {
    renderWithRouter(<StateForbidden route="/admin" attemptedPermissionKey="Admin.Read" />);
    await screen.findByRole("heading", { level: 1 });
    expect(screen.queryByTestId("route-error-correlation")).toBeNull();
  });
});
