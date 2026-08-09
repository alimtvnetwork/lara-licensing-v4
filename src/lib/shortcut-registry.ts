/**
 * Keyboard shortcut registry per
 * spec/24-app-ui-design-system/57-keyboard-shortcut-registry.md §5 (global set).
 *
 * Root cause of prior drift: shortcuts were bound ad-hoc in components, so
 * `Mod+K` / `?` / `Escape` had no single source of truth and no platform
 * `Mod` rendering. This module pins the closed global set and exposes a
 * strongly-typed `matchShortcut()` used by `useHotkey`.
 *
 * Out of scope for now: surface-scope bindings (§6). Those land alongside
 * the DataTable + Detail refits (plan 07 steps 20b/26+).
 */

export type ShortcutIdType =
  | "OpenCommandPalette"
  | "OpenShortcutsHelp"
  | "CloseOverlay"
  | "FocusGlobalSearch"
  | "ToggleTheme"
  | "SignOut";

export interface ShortcutRow {
  id: ShortcutIdType;
  /** Written form, spec §4. `Mod` renders per platform in `formatShortcut`. */
  combo: string;
  label: string;
}

export const GlobalShortcuts: readonly ShortcutRow[] = [
  { id: "OpenCommandPalette", combo: "Mod+K", label: "Search commands and pages" },
  { id: "OpenShortcutsHelp", combo: "?", label: "Show keyboard shortcuts" },
  { id: "CloseOverlay", combo: "Escape", label: "Close dialog or menu" },
  { id: "FocusGlobalSearch", combo: "Mod+/", label: "Focus search" },
  { id: "ToggleTheme", combo: "Alt+Shift+D", label: "Toggle theme" },
  { id: "SignOut", combo: "Alt+Shift+S", label: "Sign out" },
] as const;

export function isMacPlatform(): boolean {
  if (typeof navigator === "undefined") return false;
  const platform = navigator.platform ?? "";

  return /Mac|iPhone|iPad/i.test(platform);
}

/** Render a written combo (`Mod+K`) with platform glyphs for display in UI. */
export function formatShortcut(combo: string): string {
  const mac = isMacPlatform();

  return combo
    .split("+")
    .map((part) => renderChord(part, mac))
    .join(mac ? "" : "+");
}

function renderChord(part: string, mac: boolean): string {
  if (part === "Mod") return mac ? "\u2318" : "Ctrl";
  if (part === "Alt") return mac ? "\u2325" : "Alt";
  if (part === "Shift") return mac ? "\u21E7" : "Shift";
  if (part === "Escape") return "Esc";

  return part;
}

export function matchShortcut(row: ShortcutRow, event: KeyboardEvent): boolean {
  const parts = row.combo.split("+");
  const wantMod = parts.includes("Mod");
  const wantAlt = parts.includes("Alt");
  const wantShift = parts.includes("Shift");
  const key = parts[parts.length - 1];
  const mac = isMacPlatform();
  const modOk = wantMod === (mac ? event.metaKey : event.ctrlKey);
  const altOk = wantAlt === event.altKey;
  const shiftOk = wantShift === event.shiftKey;
  const keyOk = keyMatches(key, event);

  return modOk && altOk && shiftOk && keyOk;
}

function keyMatches(key: string, event: KeyboardEvent): boolean {
  if (key.length === 1) return event.key.toLowerCase() === key.toLowerCase();

  return event.key === key;
}

export function shortcutById(id: ShortcutIdType): ShortcutRow {
  const row = GlobalShortcuts.find((r) => r.id === id);
  if (row === undefined) throw new Error(`Unknown shortcut id: ${id}`);

  return row;
}
