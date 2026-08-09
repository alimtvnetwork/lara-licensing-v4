import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

/**
 * Locks the admin-mode contract of <QuotaRequestList /> per
 * spec/21-app/42-quota-requests.md and Plan 05 Step 49:
 *
 * AC-QREQ-A1  mode="admin" + Status=Pending renders BOTH Approve and Deny.
 * AC-QREQ-A2  clicking Approve calls approveQuotaRequest exactly once with
 *             the row's QuotaRequestId and a fresh Idempotency-Key (uuid).
 * AC-QREQ-A3  clicking Deny calls denyQuotaRequest exactly once with a
 *             non-empty Reason and a fresh Idempotency-Key (uuid).
 * AC-QREQ-A4  non-Pending rows expose NO action buttons in admin mode
 *             (Approved/Denied/Cancelled are terminal per spec §State machine).
 *
 * Root cause the suite prevents regressing: RequestActions short-circuits
 * on `isPending` and dispatches to AdminButtons; a refactor that flipped
 * the branch or dropped `crypto.randomUUID()` would still render fine and
 * pass every existing unit test, but silently break AC-QREQ-005/006.
 */

vi.mock("../src/lib/lara-quota", async () => {
  const actual = await vi.importActual<typeof import("../src/lib/lara-quota")>(
    "../src/lib/lara-quota",
  );
  return {
    ...actual,
    approveQuotaRequest: vi.fn(),
    denyQuotaRequest: vi.fn(),
    cancelQuotaRequest: vi.fn(),
  };
});

vi.mock("../src/lib/use-lara-error-toast", () => ({
  useLaraErrorToast: () => undefined,
}));

import {
  approveQuotaRequest,
  denyQuotaRequest,
  quotaRequestListQueryOptions,
  type QuotaRequest,
} from "../src/lib/lara-quota";
import { QuotaRequestList } from "../src/components/quota/quota-request-list";

const approveMock = vi.mocked(approveQuotaRequest);
const denyMock = vi.mocked(denyQuotaRequest);

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function makeRow(overrides: Partial<QuotaRequest>): QuotaRequest {
  return {
    QuotaRequestId: 501,
    ResellerId: 42,
    LicenseCategoryId: 1,
    LicenseTierId: 1,
    RequestedDelta: 5,
    Status: "Pending",
    SubmittedByUserId: 7,
    SubmittedAt: "2026-08-01T00:00:00.000Z",
    ...overrides,
  };
}

function renderList(rows: QuotaRequest[]) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  // quotaRequestListQueryOptions queryFn returns QuotaRequest[] (Results array
  // after envelope unwrap). Seed exactly that shape.
  client.setQueryData(quotaRequestListQueryOptions(42, "Pending").queryKey, rows);
  return render(
    <QueryClientProvider client={client}>
      <QuotaRequestList resellerId={42} resellerSlug="acme" mode="admin" status="Pending" />
    </QueryClientProvider>,
  );

}

beforeEach(() => {
  approveMock.mockReset();
  denyMock.mockReset();
  approveMock.mockResolvedValue(makeRow({ Status: "Approved" }));
  denyMock.mockResolvedValue(makeRow({ Status: "Denied" }));
});
afterEach(() => cleanup());

describe("<QuotaRequestList mode=admin />", () => {
  it("AC-QREQ-A1: Pending row renders Approve and Deny", () => {
    renderList([makeRow({})]);
    expect(screen.getByRole("button", { name: "Approve" })).not.toBeNull();
    expect(screen.getByRole("button", { name: "Deny" })).not.toBeNull();
  });

  it("AC-QREQ-A2: Approve calls approveQuotaRequest with the row id and a uuid idempotency key", async () => {
    renderList([makeRow({ QuotaRequestId: 777 })]);
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Approve" }));
    });
    expect(approveMock).toHaveBeenCalledTimes(1);
    const [id, slug, body, idem] = approveMock.mock.calls[0]!;
    expect(id).toBe(777);
    expect(slug).toBe("acme");
    expect(body).toEqual({});
    expect(typeof idem).toBe("string");
    expect(idem).toMatch(UUID_RE);
  });


  it("AC-QREQ-A3: Deny calls denyQuotaRequest with a non-empty Reason and a uuid idempotency key", async () => {
    renderList([makeRow({ QuotaRequestId: 778 })]);
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Deny" }));
    });
    expect(denyMock).toHaveBeenCalledTimes(1);
    const [id, slug, body, idem] = denyMock.mock.calls[0]!;
    expect(id).toBe(778);
    expect(slug).toBe("acme");
    expect(typeof body.Reason).toBe("string");
    expect(body.Reason.length).toBeGreaterThan(0);
    expect(idem).toMatch(UUID_RE);
  });


  it("AC-QREQ-A4: non-Pending row exposes no action buttons in admin mode", () => {
    renderList([
      makeRow({ QuotaRequestId: 601, Status: "Approved" }),
      makeRow({ QuotaRequestId: 602, Status: "Denied" }),
      makeRow({ QuotaRequestId: 603, Status: "Cancelled" }),
    ]);
    expect(screen.queryByRole("button", { name: "Approve" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Deny" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Cancel" })).toBeNull();
  });
});
