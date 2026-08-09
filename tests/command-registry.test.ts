import { describe, it, expect } from "vitest";

import { commandsForRole } from "@/lib/command-registry";

describe("command-registry", () => {
  it("emits Admin nav commands with resolved routes", () => {
    const rows = commandsForRole("Admin", null);
    expect(rows.length).toBeGreaterThan(0);
    for (const row of rows) {
      expect(row.kind).toBe("Navigate");
      expect(row.group).toBe("Navigation");
      expect(row.commandId.startsWith("Nav.Admin.")).toBe(true);
      expect(row.target.startsWith("/")).toBe(true);
      expect(row.target.includes("$")).toBe(false);
    }
  });

  it("omits reseller placeholder routes when no resellerId is provided", () => {
    const rows = commandsForRole("Reseller", null);
    for (const row of rows) expect(row.target.includes("$")).toBe(false);
  });

  it("substitutes $resellerId when provided", () => {
    const rows = commandsForRole("Reseller", "r-1");
    const licenses = rows.find((r) => r.label === "Licenses");
    expect(licenses?.target).toBe("/reseller/r-1/licenses");
  });

  it("hides deferred (status D) sidebar items from the Palette", () => {
    const rows = commandsForRole("Admin", null);
    expect(rows.find((r) => r.label === "Overview")).toBeUndefined();
  });
});
