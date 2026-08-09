import { describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";

import { StatCard } from "@/components/ui/stat-card";

describe("<StatCard />", () => {
  it("renders label, value, and delta chip when ready", () => {
    render(
      <StatCard
        label="Active Licenses"
        value="1,204"
        delta={{ label: "+3.2%", direction: "up" }}
        hint="Last 24h"
      />,
    );
    expect(screen.getByText("Active Licenses")).toBeDefined();
    expect(screen.getByText("1,204")).toBeDefined();
    const delta = document.querySelector('[data-slot="delta"]');
    expect(delta?.getAttribute("data-direction")).toBe("up");
    expect(delta?.textContent).toContain("+3.2%");
    expect(screen.getByText("Last 24h")).toBeDefined();
    cleanup();
  });

  it("hides value + delta and shows skeleton when loading", () => {
    const { container } = render(
      <StatCard label="X" value="1" state="loading" delta={{ label: "+1", direction: "up" }} />,
    );
    expect(container.querySelector('[data-slot="value-skeleton"]')).not.toBeNull();
    expect(container.querySelector('[data-slot="delta"]')).toBeNull();
    cleanup();
  });

  it("surfaces error state instead of a silent zero", () => {
    render(<StatCard label="X" value="0" state="error" errorMessage="boom" />);
    expect(screen.getByRole("alert").textContent).toBe("boom");
    expect(screen.getByText("--")).toBeDefined();
    cleanup();
  });
});
