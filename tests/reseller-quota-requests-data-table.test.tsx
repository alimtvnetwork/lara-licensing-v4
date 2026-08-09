import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

/**
 * Locks the reseller-mode contract of `<ResellerQuotaRequestsDataTable />`
 * introduced in v0.316.0 (Plan 09 step 45):
 *
 * AC-QREQ-R1  Pending rows render a Cancel affordance; non-Pending rows do NOT.
 * AC-QREQ-R2  Cancel opens a two-stage confirm; "Confirm cancel" calls
 *             cancelQuotaRequest exactly once with the row id and a uuid
 *             Idempotency-Key.
 * AC-QREQ-R3  Status chip filter narrows the visible rows without touching
 *             the underlying `rows` prop.
 *
 * Root cause the suite prevents regressing: the DataTable convergence hid
 * the previous list's implicit "no Cancel for terminal statuses" invariant
 * inside a `RowActions` switch. A refactor that flipped that branch or
 * dropped `crypto.randomUUID()` would still render fine and every existing
 * unit test would pass, but AC-QREQ-R1/R2 would break silently.
 */

vi.mock("../src/lib/lara-quota", async () => {
  const actual = await vi.importActual<typeof import("../src/lib/lara-quota")>(
    "../src/lib/lara-quota",
  );
  return {
    ...actual,
    cancelQuotaRequest: vi.fn(),
  };
});

import {
  cancelQuotaRequest,
  QuotaRequestStatusType,
  type QuotaRequest,
} from "../src/lib/lara-quota";
import { ResellerQuotaRequestsDataTable } from "../src/components/quota/reseller-quota-requests-data-table";

const cancelMock = vi.mocked(cancelQuotaRequest);
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function makeRow(overrides: Partial<QuotaRequest>): QuotaRequest {
  return {
    QuotaRequestId: 501,
    ResellerId: 42,
    LicenseCategoryId: 1,
    LicenseTierId: 1,
    RequestedDelta: 5,
    Status: QuotaRequestStatusType.Pending,
    SubmittedByUserId: 7,
    SubmittedAt: "2026-08-01T00:00:00.000Z",
    ...overrides,
  };
}

function renderTable(rows: QuotaRequest[]) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ResellerQuotaRequestsDataTable rows={rows} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  cancelMock.mockReset();
  cancelMock.mockResolvedValue(makeRow({ Status: "Cancelled" }));
});
afterEach(() => cleanup());

describe("<ResellerQuotaRequestsDataTable />", () => {
  it("AC-QREQ-R1: Pending row renders Cancel; terminal rows do not", () => {
    renderTable([
      makeRow({ QuotaRequestId: 601, Status: "Pending" }),
      makeRow({ QuotaRequestId: 602, Status: "Approved" }),
      makeRow({ QuotaRequestId: 603, Status: "Denied" }),
      makeRow({ QuotaRequestId: 604, Status: "Cancelled" }),
    ]);
    // Exactly one Cancel affordance (the Pending row).
    expect(screen.getAllByRole("button", { name: "Cancel" })).toHaveLength(1);
  });

  it("AC-QREQ-R2: two-stage cancel calls cancelQuotaRequest with row id + uuid key", async () => {
    renderTable([makeRow({ QuotaRequestId: 777 })]);
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Confirm cancel" }));
    });
    expect(cancelMock).toHaveBeenCalledTimes(1);
    const [id, idem] = cancelMock.mock.calls[0]!;
    expect(id).toBe(777);
    expect(typeof idem).toBe("string");
    // Prefix per component convention; uuid trailing token.
    expect(idem.startsWith("qr-cancel-777-")).toBe(true);
    expect(idem.slice("qr-cancel-777-".length)).toMatch(UUID_RE);
  });

  it("AC-QREQ-R3: status chip narrows visible rows", async () => {
    renderTable([
      makeRow({ QuotaRequestId: 701, Status: "Pending" }),
      makeRow({ QuotaRequestId: 702, Status: "Approved" }),
    ]);
    // Both IDs visible under All.
    expect(screen.getByText("701")).not.toBeNull();
    expect(screen.getByText("702")).not.toBeNull();
    await act(async () => {
      fireEvent.click(screen.getByRole("radio", { name: "Approved" }));
    });
    expect(screen.queryByText("701")).toBeNull();
    expect(screen.getByText("702")).not.toBeNull();
  });
});
