import { describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";

import { TopbarSearch } from "@/components/shell/TopbarSearch";
import {
  emitCommandPaletteOpen,
  subscribeCommandPaletteOpen,
} from "@/lib/command-palette-bus";

describe("command-palette bus", () => {
  it("emit calls all subscribed listeners", () => {
    const a = vi.fn();
    const b = vi.fn();
    const offA = subscribeCommandPaletteOpen(a);
    const offB = subscribeCommandPaletteOpen(b);
    emitCommandPaletteOpen();
    expect(a).toHaveBeenCalledOnce();
    expect(b).toHaveBeenCalledOnce();
    offA();
    emitCommandPaletteOpen();
    expect(a).toHaveBeenCalledOnce();
    expect(b).toHaveBeenCalledTimes(2);
    offB();
  });
});

describe("<TopbarSearch />", () => {
  it("click emits open to bus subscribers", () => {
    const listener = vi.fn();
    const off = subscribeCommandPaletteOpen(listener);
    render(<TopbarSearch />);
    act(() => {
      fireEvent.click(screen.getByRole("button", { name: /Open command palette/i }));
    });
    expect(listener).toHaveBeenCalledOnce();
    off();
    cleanup();
  });
});
