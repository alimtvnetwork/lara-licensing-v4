import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

/**
 * Locks the behavioral contract that <LicenseIssueForm /> runs
 * runResellerPreflight BEFORE createLicense whenever a ResellerId is
 * provided, per spec/21-app/11-api-contracts/02-license-contracts.md
 * §Reseller quota decrement (AC-API-LIC-006), and MUST NOT fire the
 * network mutation when preflight throws QuotaExhausted (AC-ERR-006) or
 * QuotaCategoryUnauthorized (AC-ERR-007).
 *
 * Root cause the suite prevents regressing: the helper is unit-tested in
 * tests/lara-quota-preflight.test.ts, but nothing pins its wiring inside
 * the form. A refactor that moved runResellerPreflight after createLicense
 * would still pass the helper suite while re-introducing the exact 403/409
 * round-trip the preflight was written to avoid.
 */

vi.mock("@tanstack/react-router", () => ({
  useNavigate: () => vi.fn(),
}));

vi.mock("@/components/admin/serial-issue-form", () => ({
  SerialIssueForm: () => null,
}));

vi.mock("@/components/retry-after-banner", () => ({
  RetryAfterBanner: () => null,
}));

vi.mock("@/lib/lara-license", async () => {
  const actual = await vi.importActual<typeof import("@/lib/lara-license")>(
    "@/lib/lara-license",
  );
  return { ...actual, createLicense: vi.fn() };
});

import { createLicense } from "@/lib/lara-license";
import { LicenseIssueForm } from "@/components/admin/license-issue-form";
import type { ResellerQuota } from "@/lib/lara-quota";

const createMock = vi.mocked(createLicense);

function makeClient(quotas: ResellerQuota[]): QueryClient {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  // resellerQuotasQueryOptions queryFn returns ResellerQuota[] (parseLaraResponse
  // unwraps the envelope's Results array), so the cache seed is the array itself.
  client.setQueryData(["LaraApi", "Resellers", 42, "Quotas", 100], quotas);
  return client;
}

function renderForm(client: QueryClient) {
  return render(
    <QueryClientProvider client={client}>
      <LicenseIssueForm />
    </QueryClientProvider>,
  );
}

async function submitWithReseller(resellerId: string) {
  const resellerLabel = screen.getByText(/ResellerId/).closest("label");
  const resellerInput = resellerLabel!.querySelector("input")!;
  fireEvent.change(resellerInput, { target: { value: resellerId } });
  const productVersionLabel = screen.getByText(/ProductVersion/).closest("label");
  const productVersionInput = productVersionLabel!.querySelector("input")!;
  fireEvent.change(productVersionInput, { target: { value: "1.0.0" } });
  await act(async () => {
    fireEvent.submit(screen.getByRole("button", { name: /Issue license/i }).closest("form")!);
  });
}

beforeEach(() => createMock.mockReset());
afterEach(() => cleanup());

describe("<LicenseIssueForm /> reseller preflight", () => {
  it("blocks the network call and surfaces QuotaExhausted (AC-ERR-006) when the cached row is depleted", async () => {
    const client = makeClient([
      {
        ResellerId: 42,
        LicenseCategoryId: 1,
        LicenseTierId: 1,
        LicensesGranted: 10,
        LicensesConsumed: 10,
        LicensesRemaining: 0,
        PeriodStart: "2026-01-01T00:00:00.000Z",
      },
    ]);
    renderForm(client);
    await submitWithReseller("42");
    expect(createMock).not.toHaveBeenCalled();
    expect(screen.getByRole("alert").textContent).toContain("Quota exhausted");
  });

  it("blocks the network call and surfaces QuotaCategoryUnauthorized (AC-ERR-007) when no row matches the category", async () => {
    const client = makeClient([
      {
        ResellerId: 42,
        LicenseCategoryId: 3, // form defaults to category 1
        LicenseTierId: 1,
        LicensesGranted: 10,
        LicensesConsumed: 0,
        LicensesRemaining: 10,
        PeriodStart: "2026-01-01T00:00:00.000Z",
      },
    ]);
    renderForm(client);
    await submitWithReseller("42");
    expect(createMock).not.toHaveBeenCalled();
    expect(screen.getByRole("alert").textContent).toContain("no quota allocated");
  });

  it("fires createLicense when a matching cached row still has capacity", async () => {
    const client = makeClient([
      {
        ResellerId: 42,
        LicenseCategoryId: 1,
        LicenseTierId: 1,
        LicensesGranted: 10,
        LicensesConsumed: 4,
        LicensesRemaining: 6,
        PeriodStart: "2026-01-01T00:00:00.000Z",
      },
    ]);
    createMock.mockResolvedValueOnce({
      LicenseId: 999,
      IsActive: true,
      IsSingleUse: false,
      IssuedAt: "2026-01-02T00:00:00.000Z",
      ProductVersion: "1.0.0",
      LicenseCategoryId: 1,
    } as never);
    renderForm(client);
    await submitWithReseller("42");
    expect(createMock).toHaveBeenCalledTimes(1);
  });
});
