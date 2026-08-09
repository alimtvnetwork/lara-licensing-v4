// Plan 09 steps 93 + 94 contract.
//
// Skeleton: variants pick the right silhouette class and `role=status` is
// present so screen readers announce hydration.
// EmptyState: preset picks the correct illustration, actions render, and
// the "search" preset is distinct from "box" (regression guard against
// silently sharing one asset).

import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { EmptyState } from "../src/components/ui/empty-state";
import { Skeleton, SkeletonList } from "../src/components/ui/skeleton";

describe("Skeleton", () => {
  it("defaults to the text variant and exposes role=status", () => {
    render(<Skeleton />);
    const el = screen.getByRole("status", { name: /loading/i });
    expect(el.className).toContain("h-3.5");
  });

  it("applies the stat variant silhouette", () => {
    render(<Skeleton variant="stat" data-testid="stat-skel" />);
    const el = screen.getByTestId("stat-skel");
    expect(el.className).toContain("h-24");
  });

  it("SkeletonList renders the requested row count", () => {
    render(<SkeletonList rows={4} />);
    const rows = screen.getAllByRole("status", { name: /loading/i });
    expect(rows.length).toBe(4);
  });
});

describe("EmptyState", () => {
  it("renders the box preset with headline and optional body", () => {
    render(<EmptyState headline="No licenses yet" body="Issue your first one" />);
    expect(screen.getByText("No licenses yet")).toBeTruthy();
    expect(screen.getByText("Issue your first one")).toBeTruthy();
    expect(screen.getByLabelText(/empty box illustration/i)).toBeTruthy();
  });

  it("switches illustration for the search preset", () => {
    render(<EmptyState preset="search" headline="No matches" />);
    expect(screen.getByLabelText(/empty search results illustration/i)).toBeTruthy();
    expect(screen.queryByLabelText(/empty box illustration/i)).toBeNull();
  });

  it("renders primary and secondary action slots when provided", () => {
    render(
      <EmptyState
        headline="No rows"
        primary={<button type="button">Add</button>}
        secondary={<button type="button">Import</button>}
      />,
    );
    expect(screen.getByRole("button", { name: "Add" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "Import" })).toBeTruthy();
  });
});
