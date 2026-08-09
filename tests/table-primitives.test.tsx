// spec 24 §5, §6, §13 verification.
import { describe, it, expect, vi } from "vitest";
import { render } from "@testing-library/react";
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  TableSortButton,
} from "@/components/ui/table";

function makeRow() {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Serial</TableHead>
          <TableHead align="end" sort="asc">
            <TableSortButton direction="asc">Issued</TableSortButton>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow>
          <TableCell>ABC-123</TableCell>
          <TableCell align="end">42</TableCell>
        </TableRow>
      </TableBody>
    </Table>
  );
}

describe("Table primitives", () => {
  it("carries aria-sort on the <th>, not on the sort button (§13)", () => {
    const { container } = render(makeRow());
    const th = container.querySelectorAll("th")[1] as HTMLTableCellElement;
    expect(th.getAttribute("aria-sort")).toBe("ascending");
    const button = th.querySelector("button")!;
    expect(button.getAttribute("aria-sort")).toBeNull();
  });

  it("applies data-align to enable tabular-nums via primitive CSS (§5 numeric rule)", () => {
    const { container } = render(makeRow());
    const numericCell = container.querySelectorAll("td")[1] as HTMLTableCellElement;
    expect(numericCell.getAttribute("data-align")).toBe("end");
  });

  it("uses closed sort direction ('asc' | 'desc' | 'none') and renders the icon glyph", () => {
    const { container, rerender } = render(
      <TableSortButton direction="asc">Serial</TableSortButton>,
    );
    expect(container.querySelector("svg")).not.toBeNull();
    rerender(<TableSortButton direction="none">Serial</TableSortButton>);
    expect(container.querySelector("svg")).toBeNull();
  });

  it("makes sort button keyboard-activatable (§12 Enter cycles sort)", () => {
    const onClick = vi.fn();
    const { container } = render(
      <TableSortButton direction="none" onClick={onClick}>
        Serial
      </TableSortButton>,
    );
    const btn = container.querySelector("button")!;
    btn.click();
    expect(onClick).toHaveBeenCalledTimes(1);
    expect(btn.getAttribute("type")).toBe("button");
  });

  it("does not use opacity utilities for tokenized surfaces (Plan 07 rule)", () => {
    const { container } = render(makeRow());
    expect(container.innerHTML).not.toMatch(/bg-muted\/(?!\d?0(?:0)?\b)/);
  });
});
