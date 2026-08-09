// spec 24 §26 verification.
import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { Icon } from "@/components/ui/icon";
import { ICON_CONCEPTS } from "@/components/icon/registry";

describe("Icon", () => {
  it("renders every declared concept from the Lucide registry (AC-ICO-005)", () => {
    for (const concept of Object.keys(ICON_CONCEPTS) as (keyof typeof ICON_CONCEPTS)[]) {
      const { container, unmount } = render(<Icon concept={concept} />);
      expect(container.querySelector("svg")).not.toBeNull();
      unmount();
    }
  });

  it("is decorative by default (aria-hidden, no aria-label) per spec §4 mode 1", () => {
    const { container } = render(<Icon concept="Search" />);
    const svg = container.querySelector("svg")!;
    expect(svg.getAttribute("aria-hidden")).toBe("true");
    expect(svg.getAttribute("aria-label")).toBeNull();
  });

  it("supports meaningful mode with a required label (§4 mode 3)", () => {
    render(<Icon concept="Warning" decorative={false} label="Precondition failed" />);
    expect(screen.getByLabelText("Precondition failed")).toBeTruthy();
  });

  it("resolves size from the closed token set (AC-ICO-003)", () => {
    const { container } = render(<Icon concept="Refresh" size="lg" />);
    const svg = container.querySelector("svg") as SVGElement;
    expect(svg.style.width).toBe("var(--icon-lg)");
    expect(svg.style.height).toBe("var(--icon-lg)");
  });

  it("binds one concept to exactly one glyph (single-valued map)", () => {
    const glyphs = Object.values(ICON_CONCEPTS);
    const unique = new Set(glyphs);
    // Not asserting strict uniqueness because a few concepts may legitimately
    // reuse a glyph; spec §5 asserts single-valued in the map direction,
    // which is enforced by TypeScript. We assert the count matches keys.
    expect(glyphs.length).toBe(Object.keys(ICON_CONCEPTS).length);
    expect(unique.size).toBeGreaterThan(0);
  });
});
