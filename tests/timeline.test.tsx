import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Timeline } from "@/components/ui/timeline";

describe("Timeline", () => {
  it("renders emptyState when entries is empty", () => {
    render(<Timeline entries={[]} emptyState={<p>No activity</p>} />);
    expect(screen.getByText("No activity")).toBeTruthy();
    expect(screen.queryByTestId("timeline")).toBeNull();
  });

  it("renders each entry with tone data attribute", () => {
    render(
      <Timeline
        entries={[
          { id: 1, title: "Issued", tone: "success", timestamp: "2026-07-19T00:00:00Z" },
          { id: 2, title: "Revoked", tone: "danger", description: "By admin" },
        ]}
      />,
    );
    const rows = screen.getAllByTestId("timeline-row");
    expect(rows).toHaveLength(2);
    expect(rows[0].getAttribute("data-tone")).toBe("success");
    expect(rows[1].getAttribute("data-tone")).toBe("danger");
    expect(screen.getByText("By admin")).toBeTruthy();
    expect(screen.getByText("2026-07-19T00:00:00Z").tagName).toBe("TIME");
  });

  it("defaults tone to neutral when omitted", () => {
    render(<Timeline entries={[{ id: "x", title: "Note" }]} />);
    expect(screen.getByTestId("timeline-row").getAttribute("data-tone")).toBe("neutral");
  });
});
