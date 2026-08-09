import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render as rtlRender, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactElement } from "react";

/**
 * LicenseDetailActions renders <LineageBadge /> inside the revoke confirm
 * block (Plan 09 Step 22). LineageBadge calls useQuery, which requires a
 * QueryClientProvider ancestor. Wrap every render so the revoke path and
 * every other path share the same tree.
 */
function render(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return rtlRender(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

/**
 * Locks AC-CONFLICT-001..005 from spec/21-app/49-concurrency-conflict-ux.md
 * against src/components/admin/license-detail-actions.tsx. Root cause the
 * suite prevents regressing: before v0.193.0 a 412 PreconditionFailed from
 * Save/Revoke surfaced only as a raw formatted string with no recovery
 * affordance, forcing operators to full-reload the page and discard edits.
 */

import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

const invalidateMock = vi.fn(async () => {});

vi.mock("@tanstack/react-router", () => ({
  useRouter: () => ({ invalidate: invalidateMock }),
}));

vi.mock("@/components/admin/serial-issue-form", () => ({
  SerialIssueForm: () => null,
}));

vi.mock("@/lib/lara-license", async () => {
  const actual = await vi.importActual<typeof import("@/lib/lara-license")>(
    "@/lib/lara-license",
  );
  return {
    ...actual,
    updateLicense: vi.fn(),
    deleteLicense: vi.fn(),
  };
});

import { updateLicense, deleteLicense, type License } from "@/lib/lara-license";
import { LicenseDetailActions } from "@/components/admin/license-detail-actions";

const updateMock = vi.mocked(updateLicense);
const deleteMock = vi.mocked(deleteLicense);

const license: License = {
  LicenseId: 7,
  ResellerId: 1,
  UserId: 100,
  LicensePrefixId: 1,
  LicenseCategoryId: 1,
  LicenseTierId: 1,
  EnvironmentId: 1,
  IsActive: true,
  ExpiresAt: "2027-01-01T00:00:00.000Z",
  IssuedAt: "2026-01-01T00:00:00.000Z",
  UpdatedAt: "2026-06-01T00:00:00.000Z",
} as unknown as License;

function conflictError(): LaraApiError {
  return new LaraApiError(
    "License has changed",
    ApiErrorCodeType.PreconditionFailed,
    412,
    "req-conflict-1",
  );
}

beforeEach(() => {
  invalidateMock.mockClear();
  updateMock.mockReset();
  deleteMock.mockReset();
});

afterEach(() => cleanup());

describe("<LicenseDetailActions /> concurrency conflict recovery", () => {
  it("AC-CONFLICT-001: renders status region with copy anchor and Reload button on 412 from Save", async () => {
    updateMock.mockRejectedValueOnce(conflictError());
    render(<LicenseDetailActions license={license} etag='W/"v1"' />);
    await act(async () => {
      fireEvent.submit(screen.getByRole("button", { name: /Save changes/i }).closest("form")!);
    });
    expect(screen.getByRole("status")).toBeDefined();
    expect(screen.getByText(/changed since you loaded it/i)).toBeDefined();
    expect(screen.getByRole("button", { name: /Reload latest and retry/i })).toBeDefined();
  });

  it("AC-CONFLICT-002: Reload latest invalidates router, clears conflict, preserves edits", async () => {
    updateMock.mockRejectedValueOnce(conflictError());
    render(<LicenseDetailActions license={license} etag='W/"v1"' />);
    const input = screen.getByPlaceholderText(/2026-12-31/) as HTMLInputElement;
    await act(async () => {
      fireEvent.change(input, { target: { value: "2028-05-05T00:00:00Z" } });
    });
    await act(async () => {
      fireEvent.submit(input.closest("form")!);
    });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /Reload latest and retry/i }));
    });
    expect(invalidateMock).toHaveBeenCalledOnce();
    expect(screen.queryByRole("status")).toBeNull();
    expect((screen.getByPlaceholderText(/2026-12-31/) as HTMLInputElement).value)
      .toBe("2028-05-05T00:00:00Z");
  });

  it("AC-CONFLICT-001 (revoke path): renders the same status region on 412 from Delete", async () => {
    deleteMock.mockRejectedValueOnce(conflictError());
    render(<LicenseDetailActions license={license} etag='W/"v1"' />);
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /Revoke license/i }));
    });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /Confirm revoke/i }));
    });
    expect(screen.getByRole("status")).toBeDefined();
    expect(screen.getByText(/changed since you loaded it/i)).toBeDefined();
  });

  it("AC-CONFLICT-005 negative: non-412 errors do NOT render the conflict status region", async () => {
    updateMock.mockRejectedValueOnce(
      new LaraApiError("boom", ApiErrorCodeType.ServerError, 500, "req-x"),
    );
    render(<LicenseDetailActions license={license} etag='W/"v1"' />);
    await act(async () => {
      fireEvent.submit(screen.getByRole("button", { name: /Save changes/i }).closest("form")!);
    });
    expect(screen.queryByRole("status")).toBeNull();
    expect(screen.getByRole("alert").textContent).toMatch(/Something failed on our side/);
  });

  it("cannot mutate without ETag: submit disabled and conflict path not reachable", () => {
    render(<LicenseDetailActions license={license} etag={undefined} />);
    const save = screen.getByRole("button", { name: /Save changes/i }) as HTMLButtonElement;
    expect(save.disabled).toBe(true);
    expect(updateMock).not.toHaveBeenCalled();
  });
});
