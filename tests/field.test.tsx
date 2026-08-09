import { describe, expect, it, afterEach } from "vitest";
import { render, screen, cleanup } from "@testing-library/react";
import { Field, FieldLabel, FieldControl } from "@/components/ui/field";
import { Input } from "@/components/ui/input";

describe("<Field />", () => {
  afterEach(() => cleanup());

  it("wires label htmlFor, aria-required, and aria-describedby to helper id when no error (AC-INP-003)", () => {
    render(
      <Field required helper="Must be a valid email address.">
        <FieldLabel>Email</FieldLabel>
        <FieldControl>
          <Input type="email" />
        </FieldControl>
      </Field>,
    );
    const input = screen.getByLabelText(/Email/) as HTMLInputElement;
    expect(input.getAttribute("aria-required")).toBe("true");
    expect(input.getAttribute("aria-invalid")).toBeNull();
    const helper = screen.getByText("Must be a valid email address.");
    expect(input.getAttribute("aria-describedby")).toBe(helper.id);
    expect(helper.id.endsWith("-helper")).toBe(true);
  });

  it("switches aria-describedby to error id and marks aria-invalid when error is present (AC-INP-003)", () => {
    render(
      <Field
        required
        helper="Must be a valid email address."
        error="Enter a valid email address, for example name@example.com."
      >
        <FieldLabel>Email</FieldLabel>
        <FieldControl>
          <Input type="email" defaultValue="not-an-email" />
        </FieldControl>
      </Field>,
    );
    const input = screen.getByLabelText(/Email/) as HTMLInputElement;
    expect(input.getAttribute("aria-invalid")).toBe("true");
    const alert = screen.getByRole("alert");
    expect(alert.textContent).toMatch(/Enter a valid email address/);
    expect(input.getAttribute("aria-describedby")).toBe(alert.id);
    // Helper MUST NOT render alongside error (spec 24 §18.3 bullet 3).
    expect(screen.queryByText("Must be a valid email address.")).toBeNull();
  });

  it("throws when FieldControl is used outside <Field>", () => {
    // Suppress React's error boundary console spam for the failing render.
    const spy = console.error;
    console.error = () => {};
    try {
      expect(() =>
        render(
          <FieldControl>
            <Input />
          </FieldControl>,
        ),
      ).toThrow(/must be rendered inside <Field>/);
    } finally {
      console.error = spy;
    }
  });
});
