import { useEffect } from "react";

import { matchShortcut, shortcutById, type ShortcutIdType } from "@/lib/shortcut-registry";

/**
 * Global hotkey binder per
 * spec/24-app-ui-design-system/57-keyboard-shortcut-registry.md §3 (scope
 * hierarchy). Root cause of prior drift: components attached raw `keydown`
 * listeners with hard-coded combos, so `Escape` collided with dialogs and
 * `?` fired while typing in inputs.
 *
 * Rules enforced here (spec §3, §4):
 * 1. Global bindings pause while a modal Dialog / Popover is open (detected
 *    via `[data-state="open"]` on any `[role="dialog"]`).
 * 2. Single-key bindings (`?`, `Escape`) are ignored while focus is inside
 *    an editable control unless the shortcut explicitly opts in.
 */
export function useHotkey(
  id: ShortcutIdType,
  handler: (event: KeyboardEvent) => void,
  options: { enabled?: boolean; allowInEditable?: boolean } = {},
): void {
  const enabled = options.enabled ?? true;
  const allowInEditable = options.allowInEditable ?? false;
  useEffect(() => {
    const isFailed = !enabled;
    if (isFailed) return undefined;
    const row = shortcutById(id);
    const onKeyDown = (event: KeyboardEvent): void => {
      if (matchShortcut(row, event) === false) return;
      if (!allowInEditable && isEditableTarget(event.target)) return;
      if (isModalOpen() && row.id !== "CloseOverlay") return;
      event.preventDefault();
      handler(event);
    };
    window.addEventListener("keydown", onKeyDown);

    return () => window.removeEventListener("keydown", onKeyDown);
  }, [id, handler, enabled, allowInEditable]);
}

function isEditableTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  if (target.isContentEditable) return true;
  const tag = target.tagName;

  return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT";
}

function isModalOpen(): boolean {
  if (typeof document === "undefined") return false;

  return document.querySelector('[role="dialog"][data-state="open"]') !== null;
}
