/**
 * Locks the Banner primitive contract from spec 24 §23.3:
 * - Variant-driven role/aria-live (info/success = status+polite,
 *   warning/error = alert+assertive).
 * - Error/success Banners are non-dismissible (AC-BAN-001).
 * - <BannerActions> caps at 2 children.
 * - Token surfaces (radius-md, color-mix background).
 */
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";

import { Banner, BannerActions, BannerDescription, BannerTitle } from "@/components/ui/banner";

afterEach(cleanup);

describe("Banner primitive (spec 24 §23.3)", () => {
  it("info uses status/polite and is not dismissible by default", () => {
    render(
      <Banner intent="info">
        <BannerTitle>Dry-run mode</BannerTitle>
      </Banner>,
    );
    const banner = screen.getByRole("status");
    expect(banner.getAttribute("aria-live")).toBe("polite");
    expect(screen.queryByRole("button", { name: /dismiss/i })).toBeNull();
  });

  it("warning uses alert/assertive and MAY be dismissible", () => {
    const onDismiss = vi.fn();
    render(
      <Banner intent="warning" dismissible onDismiss={onDismiss}>
        <BannerTitle>Rate limit near</BannerTitle>
      </Banner>,
    );
    const banner = screen.getByRole("alert");
    expect(banner.getAttribute("aria-live")).toBe("assertive");
    screen.getByRole("button", { name: /dismiss/i }).click();
    expect(onDismiss).toHaveBeenCalledOnce();
  });

  it("error is non-dismissible (AC-BAN-001): setting dismissible throws", () => {
    // Suppress React's internal error logging for this expected throw.
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    expect(() =>
      render(
        <Banner intent="error" dismissible>
          <BannerTitle>Blocked</BannerTitle>
        </Banner>,
      ),
    ).toThrow(/MUST NOT be dismissible/);
    spy.mockRestore();
  });

  it("success is non-dismissible (AC-BAN-001): setting dismissible throws", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    expect(() =>
      render(
        <Banner intent="success" dismissible>
          <BannerTitle>Saved</BannerTitle>
        </Banner>,
      ),
    ).toThrow(/MUST NOT be dismissible/);
    spy.mockRestore();
  });

  it("carries token-driven radius-md and data-banner-intent attribute", () => {
    render(
      <Banner intent="error">
        <BannerTitle>Blocked</BannerTitle>
        <BannerDescription>Retry later</BannerDescription>
      </Banner>,
    );
    const banner = screen.getByRole("alert");
    expect(banner.getAttribute("data-banner-intent")).toBe("error");
    expect(banner.className).toMatch(/rounded-\[var\(--radius-md\)\]/);
  });

  it("BannerActions caps at 2 children (spec §23.3.3)", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    expect(() =>
      render(
        <Banner intent="warning">
          <BannerTitle>Choose</BannerTitle>
          <BannerActions>
            <button>One</button>
            <button>Two</button>
            <button>Three</button>
          </BannerActions>
        </Banner>,
      ),
    ).toThrow(/caps action Buttons at 2/);
    spy.mockRestore();
  });
});
