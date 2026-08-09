/**
 * Locks the refit shells for Select trigger, Dialog content, AlertDialog
 * content, and Sheet content per spec 24 §19 and §21. Scope is the class
 * contract that survives Tailwind's compile (geometry, focus ring
 * tokens, radius, sticky header/footer wiring, absence of the X close
 * button on AlertDialog per AC-DLG-013). Behavioral rules (focus trap,
 * two-Escape, unsaved-changes guard) belong in Playwright a11y suites.
 */
import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

afterEach(cleanup);

describe("Select refit (spec 24 §19)", () => {
  it("trigger renders with 40px height, radius-md, and focus ring tokens", () => {
    const { container } = render(
      <Select>
        <SelectTrigger aria-label="Category">
          <SelectValue placeholder="Select a category..." />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="1">Daily</SelectItem>
        </SelectContent>
      </Select>,
    );
    const trigger = container.querySelector('[role="combobox"]');
    expect(trigger).not.toBeNull();
    const cls = trigger?.className ?? "";
    expect(cls).toMatch(/h-10/);
    expect(cls).toMatch(/rounded-\[var\(--radius-md\)\]/);
    expect(cls).toMatch(/ring-\[var\(--ring\)\]/);
    expect(cls).toMatch(/aria-\[invalid=true\]:border-\[var\(--destructive\)\]/);
  });

  it("trigger renders the ChevronsUpDown glyph (never a plain ChevronDown)", () => {
    const { container } = render(
      <Select>
        <SelectTrigger aria-label="Env"><SelectValue placeholder="..." /></SelectTrigger>
      </Select>,
    );
    // Radix wraps the icon slot in a span[aria-hidden]; the Lucide svg has our chevrons-up-down class.
    const svg = container.querySelector('svg.lucide-chevrons-up-down');
    expect(svg).not.toBeNull();
  });
});

describe("Dialog refit (spec 24 §21)", () => {
  it("md content has token-driven radius, background, and sticky header/footer wiring", () => {
    render(
      <Dialog defaultOpen>
        <DialogContent size="md">
          <DialogHeader>
            <DialogTitle>Title</DialogTitle>
            <DialogDescription>Desc</DialogDescription>
          </DialogHeader>
          <DialogBody>Body</DialogBody>
          <DialogFooter>Footer</DialogFooter>
        </DialogContent>
      </Dialog>,
    );
    const content = screen.getByRole("dialog");
    const cls = content.className;
    expect(cls).toMatch(/rounded-\[var\(--radius-lg\)\]/);
    expect(cls).toMatch(/bg-\[var\(--card\)\]/);
    expect(cls).toMatch(/max-w-\[min\(560px/);
    // Sticky header/footer: check the section wrappers by text.
    expect(screen.getByText("Title").parentElement?.className).toMatch(/sticky/);
    expect(screen.getByText("Footer").className).toMatch(/sticky/);
  });

  it("renders the X close button by default and hides it when showClose=false", () => {
    const { rerender } = render(
      <Dialog defaultOpen>
        <DialogContent><DialogTitle>t</DialogTitle></DialogContent>
      </Dialog>,
    );
    expect(screen.queryByRole("button", { name: /close/i })).not.toBeNull();
    rerender(
      <Dialog defaultOpen>
        <DialogContent showClose={false}><DialogTitle>t</DialogTitle></DialogContent>
      </Dialog>,
    );
    expect(screen.queryByRole("button", { name: /close/i })).toBeNull();
  });
});

describe("AlertDialog refit (spec 24 §21.5)", () => {
  it("has no X close button (AC-DLG-013) and pins container to 400px max", () => {
    render(
      <AlertDialog defaultOpen>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revoke license</AlertDialogTitle>
            <AlertDialogDescription>Consequences</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction>Revoke</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>,
    );
    const content = screen.getByRole("alertdialog");
    expect(content.className).toMatch(/max-w-\[min\(400px/);
    // No close button anywhere in the tree; Cancel + Revoke only.
    expect(screen.queryByRole("button", { name: /^close$/i })).toBeNull();
    // Destructive Action inherits destructive intent classes from buttonVariants.
    const action = screen.getByRole("button", { name: "Revoke" });
    expect(action.className).toMatch(/bg-destructive|destructive/);
  });
});

describe("Sheet refit (spec 24 §21.6)", () => {
  it("right side (default) applies inline-start radius only and 40vw clamp", () => {
    render(
      <Sheet defaultOpen>
        <SheetContent>
          <SheetHeader><SheetTitle>Edit reseller</SheetTitle><SheetDescription>...</SheetDescription></SheetHeader>
          <SheetBody>body</SheetBody>
          <SheetFooter>footer</SheetFooter>
        </SheetContent>
      </Sheet>,
    );
    const content = screen.getByRole("dialog");
    expect(content.className).toMatch(/rounded-l-\[var\(--radius-lg\)\]/);
    expect(content.className).toMatch(/w-\[clamp\(360px,40vw,640px\)\]/);
  });
});
