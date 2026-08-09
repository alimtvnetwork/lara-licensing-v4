/**
 * Locks Checkbox / RadioGroup / Switch refit per
 * spec/24-app-ui-design-system/20-component-choice.md §3-4, §7.
 *
 * Scope: geometry class contract, aria-invalid destructive shift, focus
 * ring parity, and Switch aria-busy semantics. Idempotency-Key wiring
 * (AC-CHC-002/003) belongs in the mutation-call tests where the fake
 * client lives; this file locks the primitive contract only.
 */
import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render } from "@testing-library/react";

import { Checkbox } from "@/components/ui/checkbox";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Switch } from "@/components/ui/switch";

afterEach(cleanup);

describe("Choice primitives (spec 24 §20)", () => {
  it("Checkbox renders 20x20 with radius-sm and focus ring tokens", () => {
    const { container } = render(<Checkbox aria-label="agree" />);
    const el = container.querySelector('[role="checkbox"]');
    expect(el).not.toBeNull();
    const cls = el?.className ?? "";
    expect(cls).toMatch(/h-5/);
    expect(cls).toMatch(/w-5/);
    expect(cls).toMatch(/rounded-\[var\(--radius-sm\)\]/);
    expect(cls).toMatch(/ring-\[var\(--ring\)\]/);
  });

  it("Checkbox aria-invalid shifts border to destructive token", () => {
    const { container } = render(<Checkbox aria-label="x" aria-invalid />);
    const el = container.querySelector('[role="checkbox"]');
    expect(el?.className).toMatch(/aria-\[invalid=true\]:border-\[var\(--destructive\)\]/);
  });

  it("RadioGroupItem renders 20x20 circular with primary check indicator", () => {
    const { container } = render(
      <RadioGroup defaultValue="a">
        <RadioGroupItem value="a" aria-label="a" />
        <RadioGroupItem value="b" aria-label="b" />
      </RadioGroup>,
    );
    const items = container.querySelectorAll('[role="radio"]');
    expect(items.length).toBe(2);
    const cls = items[0]?.className ?? "";
    expect(cls).toMatch(/h-5/);
    expect(cls).toMatch(/rounded-full/);
    expect(cls).toMatch(/ring-\[var\(--ring\)\]/);
  });

  it("Switch renders 32x20 track with primary checked fill", () => {
    const { container } = render(<Switch aria-label="notif" />);
    const el = container.querySelector('[role="switch"]');
    expect(el).not.toBeNull();
    const cls = el?.className ?? "";
    expect(cls).toMatch(/h-5/);
    expect(cls).toMatch(/w-8/);
    expect(cls).toMatch(/data-\[state=checked\]:bg-\[var\(--primary\)\]/);
  });

  it("Switch aria-busy stamps muted-outline track (optimistic mutation state)", () => {
    const { container } = render(<Switch aria-label="notif" aria-busy="true" />);
    const el = container.querySelector('[role="switch"]');
    expect(el?.className).toMatch(/aria-\[busy=true\]:border-\[var\(--muted\)\]/);
  });
});
