// spec 24 §18-component-input.md conformance for Input + Textarea primitives.

import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

describe("Input (spec 24 §18)", () => {
  it("renders a 40px-tall input with radius-md, ring token focus, and native disabled", () => {
    render(<Input aria-label="License code" />);
    const el = screen.getByLabelText("License code");
    expect(el.tagName).toBe("INPUT");
    expect(el.className).toMatch(/h-10/);
    expect(el.className).toMatch(/rounded-\[var\(--radius-md\)\]/);
    // v0.497.0: focus signal is a `--ring-focus-strong` box-shadow, not the 2px ring pair.
    expect(el.className).toMatch(/focus-visible:shadow-\[var\(--ring-focus-strong\)\]/);
  });

  it("aria-invalid=true selects the destructive border style (no client-fabricated ring)", () => {
    render(<Input aria-label="Email" aria-invalid />);
    const el = screen.getByLabelText("Email");
    expect(el.getAttribute("aria-invalid")).toBe("true");
    expect(el.className).toMatch(/aria-\[invalid=true\]:border-\[var\(--destructive\)\]/);
  });

  it("readOnly stamps aria-readonly=true and muted styling; disabled is not implied", () => {
    render(<Input aria-label="Serial" readOnly defaultValue="LARA-XXX" />);
    const el = screen.getByLabelText("Serial") as HTMLInputElement;
    expect(el.getAttribute("aria-readonly")).toBe("true");
    expect(el.readOnly).toBe(true);
    expect(el.disabled).toBe(false);
    expect(el.className).toMatch(/read-only:bg-\[var\(--muted\)\]/);
  });

  it("type=password stamps data-input-type=password so the mono variant applies (spec §8)", () => {
    render(<Input aria-label="Password" type="password" />);
    const el = screen.getByLabelText("Password");
    expect(el.getAttribute("data-input-type")).toBe("password");
    expect(el.className).toMatch(/data-\[input-type=password\]:font-mono/);
  });
});

describe("Textarea (spec 24 §18 §11)", () => {
  it("uses min 80 / max 240 block-size and resize-y only (AC-INP-009 bans both/horizontal)", () => {
    render(<Textarea aria-label="Notes" />);
    const el = screen.getByLabelText("Notes");
    expect(el.tagName).toBe("TEXTAREA");
    expect(el.className).toMatch(/min-h-\[80px\]/);
    expect(el.className).toMatch(/max-h-\[240px\]/);
    expect(el.className).toMatch(/resize-y/);
    expect(el.className).not.toMatch(/resize-both|resize-x\b/);
  });

  it("aria-invalid=true reaches the destructive border on Textarea as well", () => {
    render(<Textarea aria-label="Notes" aria-invalid />);
    const el = screen.getByLabelText("Notes");
    expect(el.className).toMatch(/aria-\[invalid=true\]:border-\[var\(--destructive\)\]/);
  });
});
