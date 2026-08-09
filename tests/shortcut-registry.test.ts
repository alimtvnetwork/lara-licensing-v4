import { describe, it, expect } from "vitest";

import {
  GlobalShortcuts,
  formatShortcut,
  isMacPlatform,
  matchShortcut,
  shortcutById,
} from "@/lib/shortcut-registry";

function keyEvent(init: Partial<KeyboardEvent> & { key: string }): KeyboardEvent {
  return new KeyboardEvent("keydown", { ...init });
}

describe("shortcut-registry", () => {
  it("registers the closed global set from spec §5", () => {
    const ids = GlobalShortcuts.map((r) => r.id);
    expect(ids).toEqual([
      "OpenCommandPalette",
      "OpenShortcutsHelp",
      "CloseOverlay",
      "FocusGlobalSearch",
      "ToggleTheme",
      "SignOut",
    ]);
  });

  it("matches Mod+K on the current platform", () => {
    const row = shortcutById("OpenCommandPalette");
    const modKey = isMacPlatform() ? { metaKey: true } : { ctrlKey: true };
    expect(matchShortcut(row, keyEvent({ key: "k", ...modKey }))).toBe(true);
    expect(matchShortcut(row, keyEvent({ key: "k" }))).toBe(false);
  });

  it("renders Mod as Ctrl on non-mac platforms", () => {
    const rendered = formatShortcut("Mod+K");
    if (isMacPlatform()) expect(rendered).toContain("\u2318");
    else expect(rendered).toBe("Ctrl+K");
  });

  it("throws on unknown shortcut id", () => {
    // @ts-expect-error - runtime guard test
    expect(() => shortcutById("Nope")).toThrow();
  });
});
