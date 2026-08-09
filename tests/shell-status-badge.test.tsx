import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";

/**
 * Locks StatusBadge contract per
 * spec/24-app-ui-design-system/14-breadcrumbs-and-page-header.md §4.2 and
 * AC-ADS-005: icon MUST accompany color, tone is exposed as a data
 * attribute (for scroll-locked audits), and label renders as text so
 * screen readers announce state without relying on color.
 */

import { StatusBadge } from "@/components/shell/StatusBadge";

afterEach(cleanup);

describe("StatusBadge", () => {
  it("renders label, status role, and tone data attribute", () => {
    render(<StatusBadge tone="success" label="Active" />);
    const el = screen.getByRole("status");
    expect(el.textContent).toContain("Active");
    expect(el.getAttribute("data-tone")).toBe("success");
  });

  it("always renders an accompanying icon so color is not the sole signal", () => {
    const { container } = render(<StatusBadge tone="destructive" label="Revoked" />);
    const svg = container.querySelector("svg[aria-hidden='true']");
    expect(svg).not.toBeNull();
  });

  it("renders trailing children in a tabular slot", () => {
    render(<StatusBadge tone="warning" label="Pending">7</StatusBadge>);
    const el = screen.getByRole("status");
    expect(el.textContent).toContain("7");
    expect(el.querySelector(".tabular")?.textContent).toBe("7");
  });
});
