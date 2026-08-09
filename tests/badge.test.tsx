// spec 24 §25-component-badge-status.md conformance for the Badge primitive.
// Plan 15 step 9 (v0.496.0): tone colors now live in `@utility chip[data-tone]`
// (styles.css), so tests assert the data-tone attribute + chip class rather
// than inlined color-mix className fragments.

import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { Badge } from "@/components/ui/badge";

describe("Badge (spec 24 §25)", () => {
  it("renders a non-interactive <span> with the chip utility and md geometry", () => {
    render(<Badge>Active</Badge>);
    const el = screen.getByText("Active");
    expect(el.tagName).toBe("SPAN");
    expect(el.className).toMatch(/\bchip\b/);
    expect(el.className).toMatch(/h-6/);
    expect(el.className).toMatch(/text-xs/);
  });

  it("intent=success routes to data-tone='success' (chip utility resolves the color)", () => {
    render(<Badge intent="success">Approved</Badge>);
    const el = screen.getByText("Approved");
    expect(el.getAttribute("data-tone")).toBe("success");
    expect(el.className).toMatch(/\bchip\b/);
  });

  it("intent=destructive routes to data-tone='destructive'; no silent neutral fallback", () => {
    render(<Badge intent="destructive">Revoked</Badge>);
    const el = screen.getByText("Revoked");
    expect(el.getAttribute("data-tone")).toBe("destructive");
  });

  it("intent=info maps to data-tone='primary' (shared color anchor per chip utility)", () => {
    render(<Badge intent="info">Info</Badge>);
    expect(screen.getByText("Info").getAttribute("data-tone")).toBe("primary");
  });

  it("intent=neutral omits data-tone so the chip default tokens apply", () => {
    render(<Badge intent="neutral">Neutral</Badge>);
    expect(screen.getByText("Neutral").getAttribute("data-tone")).toBeNull();
  });

  it("legacy variant='secondary' shim keeps visual parity for unmigrated call sites", () => {
    render(<Badge variant="secondary">Beta</Badge>);
    const cls = screen.getByText("Beta").className;
    expect(cls).toMatch(/var\(--secondary\)/);
  });
});
