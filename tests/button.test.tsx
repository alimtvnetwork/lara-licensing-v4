// spec 24 §17-component-button.md conformance: variant/intent grammar,
// loading behavior (aria-busy + spinner), disabled semantics, and the
// legacy variant shim so we do not break the 92 pre-existing call sites.

import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { Button } from "@/components/ui/button";

describe("Button (spec 24 §17)", () => {
  it("defaults to variant=solid intent=primary size=md and renders a native button", () => {
    render(<Button>Save</Button>);
    const btn = screen.getByRole("button", { name: "Save" });
    expect(btn.tagName).toBe("BUTTON");
    // v0.495.0: primary CTA renders a primary->accent gradient (not flat bg-primary).
    expect(btn.className).toMatch(/linear-gradient/);
    expect(btn.className).toMatch(/var\(--color-primary\)/);
    expect(btn.className).toMatch(/h-10/);
  });

  it("loading sets aria-busy + aria-disabled and renders a spinner without hiding the label", () => {
    render(<Button loading>Submitting</Button>);
    const btn = screen.getByRole("button", { name: /Submitting/ });
    expect(btn.getAttribute("aria-busy")).toBe("true");
    expect(btn.getAttribute("aria-disabled")).toBe("true");
    expect(btn.querySelector("svg")).not.toBeNull();
  });

  it("intent=destructive on outline uses the destructive border color", () => {
    render(
      <Button variant="outline" intent="destructive">
        Revoke
      </Button>,
    );
    expect(screen.getByRole("button").className).toMatch(/border-destructive/);
  });

  it("legacy variant='destructive' still resolves to the destructive fill (compat shim)", () => {
    render(<Button variant="destructive">Delete</Button>);
    expect(screen.getByRole("button").className).toMatch(/bg-destructive/);
  });

  it("disabled sets the HTML disabled attribute and blocks activation", () => {
    render(<Button disabled>Off</Button>);
    const btn = screen.getByRole("button", { name: "Off" }) as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
    expect(btn.getAttribute("aria-disabled")).toBe("true");
  });
});
