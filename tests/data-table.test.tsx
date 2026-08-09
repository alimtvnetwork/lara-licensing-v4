import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, within } from "@testing-library/react";
import { DataTable, type LaraColumn } from "@/components/ui/data-table";

interface Row {
  Id: string;
  Label: string;
  Count: number;
}

const rows: Row[] = [
  { Id: "1", Label: "Alpha", Count: 10 },
  { Id: "2", Label: "Beta", Count: 20 },
];

const columns: LaraColumn<Row>[] = [
  { field: "Label", header: "Label", sortable: true, render: (r) => r.Label },
  {
    field: "Count",
    header: "Count",
    align: "end",
    sortable: true,
    render: (r) => r.Count,
  },
];

function renderTable(overrides: Partial<React.ComponentProps<typeof DataTable<Row>>> = {}) {
  const props = {
    rows,
    columns,
    rowKey: (r: Row) => r.Id,
    page: 1,
    pageSize: 25,
    total: 42,
    onPageChange: vi.fn(),
    onSortChange: vi.fn(),
    countNoun: "license" as const,
    ...overrides,
  } as React.ComponentProps<typeof DataTable<Row>>;
  return { ...render(<DataTable<Row> {...props} />), props };
}

describe("DataTable", () => {
  it("renders rows and numeric alignment", () => {
    renderTable();
    expect(screen.getByText("Alpha")).toBeTruthy();
    const countCells = screen.getAllByRole("cell").filter((c) =>
      c.getAttribute("data-align") === "end",
    );
    expect(countCells.length).toBeGreaterThan(0);
  });

  it("shows empty slot when idle with zero rows", () => {
    renderTable({ rows: [], total: 0, emptySlot: "No records." });
    expect(screen.getByText("No records.")).toBeTruthy();
  });

  it("shows error slot when status=error", () => {
    renderTable({ status: "error", errorSlot: "Boom." });
    expect(screen.getByText("Boom.")).toBeTruthy();
  });

  it("marks tbody aria-busy while loading", () => {
    const { container } = renderTable({ status: "loading" });
    expect(container.querySelector("tbody")?.getAttribute("aria-busy")).toBe("true");
  });

  it("cycles sort none->asc->desc->none on repeated clicks", () => {
    const onSortChange = vi.fn();
    const { rerender } = renderTable({ onSortChange });
    fireEvent.click(screen.getByRole("button", { name: /sort by label/i }));
    expect(onSortChange).toHaveBeenLastCalledWith({ field: "Label", direction: "asc" });
    rerender(
      <DataTable<Row>
        rows={rows}
        columns={columns}
        rowKey={(r) => r.Id}
        page={1}
        pageSize={25}
        total={42}
        countNoun="license"
        onPageChange={vi.fn()}
        onSortChange={onSortChange}
        sort={{ field: "Label", direction: "asc" }}
      />,
    );
    fireEvent.click(screen.getByRole("button", { name: /sort by label/i }));
    expect(onSortChange).toHaveBeenLastCalledWith({ field: "Label", direction: "desc" });
  });

  it("stamps aria-sort on the <th>, not the button", () => {
    renderTable({ sort: { field: "Label", direction: "asc" } });
    const th = screen.getByRole("columnheader", { name: /label/i });
    expect(th.getAttribute("aria-sort")).toBe("ascending");
    const btn = within(th).getByRole("button", { name: /sort by label/i });
    expect(btn.getAttribute("aria-sort")).toBeNull();
  });

  it("renders 1 to 25 of 42 licenses in the footer", () => {
    renderTable();
    const footer = screen.getByTestId("data-table-footer");
    expect(footer.textContent).toContain("1 to 25 of 42 licenses");
  });

  it("disables Prev on page 1 and Next on last page", () => {
    const { rerender } = renderTable({ page: 1, pageSize: 25, total: 42 });
    expect(
      (screen.getByRole("button", { name: /previous page/i }) as HTMLButtonElement).disabled,
    ).toBe(true);
    rerender(
      <DataTable<Row>
        rows={rows}
        columns={columns}
        rowKey={(r) => r.Id}
        page={2}
        pageSize={25}
        total={42}
        countNoun="license"
        onPageChange={vi.fn()}
      />,
    );
    expect(
      (screen.getByRole("button", { name: /next page/i }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });

  it("invokes onPageChange with page delta", () => {
    const onPageChange = vi.fn();
    renderTable({ page: 2, pageSize: 25, total: 100, onPageChange });
    fireEvent.click(screen.getByRole("button", { name: /previous page/i }));
    fireEvent.click(screen.getByRole("button", { name: /next page/i }));
    expect(onPageChange).toHaveBeenNthCalledWith(1, 1);
    expect(onPageChange).toHaveBeenNthCalledWith(2, 3);
  });
});
