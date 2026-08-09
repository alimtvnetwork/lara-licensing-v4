import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";

/**
 * Locks Breadcrumbs + PageHeader semantics per
 * spec/24-app-ui-design-system/14-breadcrumbs-and-page-header.md §3, §4, §5
 * and AC-ADS-046 (exactly one H1 per route).
 */

vi.mock("@tanstack/react-router", () => ({
  Link: ({ to, children, ...rest }: { to: string; children: React.ReactNode }) => (
    <a href={to} {...rest}>{children}</a>
  ),
}));

import { Breadcrumbs } from "@/components/shell/Breadcrumbs";
import { PageHeader } from "@/components/shell/PageHeader";
import { StatusBadge } from "@/components/shell/StatusBadge";

afterEach(cleanup);

describe("Breadcrumbs", () => {
  it("marks the last segment as aria-current='page' and omits its link", () => {
    render(
      <Breadcrumbs
        segments={[
          { label: "Admin", to: "/admin" },
          { label: "Licenses", to: "/admin/licenses" },
          { label: "lic_01HX", identifier: true },
        ]}
      />,
    );
    const current = screen.getByText("lic_01HX").closest("li");
    expect(current?.getAttribute("aria-current")).toBe("page");
    expect(screen.getByRole("link", { name: "Admin" }).getAttribute("href")).toBe("/admin");
    expect(screen.queryByRole("link", { name: "lic_01HX" })).toBeNull();
  });

  it("renders nothing when segments are empty (no empty <nav> noise)", () => {
    const { container } = render(<Breadcrumbs segments={[]} />);
    expect(container.firstChild).toBeNull();
  });
});

describe("PageHeader", () => {
  it("renders exactly one H1 with optional inline status and identifier slots", () => {
    const { container } = render(
      <PageHeader
        title="License lic_01HX"
        breadcrumbs={[{ label: "Licenses", to: "/admin/licenses" }, { label: "lic_01HX" }]}
        statusBadge={<StatusBadge tone="success" label="Active" />}
        identifier={<span data-testid="ident-slot">ID</span>}
        description="Issued by reseller Acme."
      />,
    );
    expect(container.querySelectorAll("h1").length).toBe(1);
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("License lic_01HX");
    expect(screen.getByTestId("ident-slot")).not.toBeNull();
    expect(screen.getByRole("status").textContent).toContain("Active");
  });

  it("omits the description paragraph when unset (no empty <p>)", () => {
    const { container } = render(<PageHeader title="Users" />);
    expect(container.querySelector("p")).toBeNull();
  });
});
