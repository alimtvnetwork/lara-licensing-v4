// Plan 09 step 26. FilterBar primitive coverage.
import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import {
  FilterBar,
  FilterChipGroup,
  FilterText,
} from "@/components/ui/filter-bar";

describe("FilterBar", () => {
  it("submit mode fires onApply from the Apply button", () => {
    const onApply = vi.fn();
    const onClear = vi.fn();
    render(
      <FilterBar mode="submit" hasActiveFilters={false} onApply={onApply} onClear={onClear}>
        <FilterText id="q" label="Query" value="" onChange={() => undefined} />
      </FilterBar>,
    );
    fireEvent.click(screen.getByRole("button", { name: "Apply" }));
    expect(onApply).toHaveBeenCalledOnce();
    expect(onClear).not.toHaveBeenCalled();
  });

  it("Clear is disabled when hasActiveFilters is false", () => {
    render(
      <FilterBar mode="submit" hasActiveFilters={false} onApply={() => undefined} onClear={() => undefined}>
        <FilterText id="q" label="Query" value="" onChange={() => undefined} />
      </FilterBar>,
    );
    const clear = screen.getByRole("button", { name: "Clear" });
    expect(clear.hasAttribute("disabled")).toBe(true);
  });

  it("live mode omits the Apply button", () => {
    render(
      <FilterBar mode="live" hasActiveFilters onClear={() => undefined}>
        <FilterText id="q" label="Query" value="hi" onChange={() => undefined} />
      </FilterBar>,
    );
    expect(screen.queryByRole("button", { name: "Apply" })).toBeNull();
    expect(screen.getByRole("button", { name: "Clear" }).hasAttribute("disabled")).toBe(false);
  });

  it("FilterChipGroup fires onChange with the selected value", () => {
    const onChange = vi.fn();
    render(
      <FilterChipGroup
        name="status"
        label="Status"
        value="all"
        options={[
          { value: "all", label: "All" },
          { value: "active", label: "Active" },
        ]}
        onChange={onChange}
      />,
    );
    fireEvent.click(screen.getByText("Active"));
    expect(onChange).toHaveBeenCalledWith("active");
  });
});
