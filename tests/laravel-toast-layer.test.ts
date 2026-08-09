import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";

/**
 * Plan 06 step 73: the Inertia console's mutating components all call `toast`
 * from sonner. Before this, no <Toaster /> existed in backend/resources/js, so
 * every success/error announcement rendered nowhere. This guards the mount.
 */
const app = readFileSync("backend/resources/js/app.tsx", "utf8");
const toaster = readFileSync("backend/resources/js/Components/ui/Toaster.tsx", "utf8");

describe("laravel console toast layer", () => {
  it("mounts the Toaster at the Inertia root", () => {
    expect(app).toContain("@/Components/ui/Toaster");
    expect(app).toContain("<Toaster />");
  });

  it("uses sonner as the transport with the spec 24 geometry", () => {
    expect(toaster).toContain('from "sonner"');
    expect(toaster).toContain('position="top-right"');
    expect(toaster).toContain("visibleToasts={3}");
  });

  it("carries a per-intent accent for every closed-set variant", () => {
    for (const intent of ["success", "info", "warning", "error"]) {
      expect(toaster).toContain(`${intent}:`);
    }
  });
});
