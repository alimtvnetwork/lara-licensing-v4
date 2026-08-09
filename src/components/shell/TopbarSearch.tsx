import { Search } from "lucide-react";
import { useRef } from "react";

import { useHotkey } from "@/hooks/use-hotkey";
import { emitCommandPaletteOpen } from "@/lib/command-palette-bus";
import { formatShortcut } from "@/lib/shortcut-registry";

/**
 * Global topbar search proxy per Plan 09 Step 20 and Spec 24 §31.
 *
 * The real search surface is the CommandPalette. This component renders
 * a launcher-style button that:
 *   1. Focuses when `FocusGlobalSearch` (`Mod+/`) fires, per spec §5.
 *   2. Opens the palette on click, Enter, or Space via the shared bus.
 *
 * Purely presentational otherwise: no direct fetch, no state. Palette
 * ownership stays with `<CommandPalette />` mounted at the shell root.
 */
export function TopbarSearch() {
  const buttonRef = useRef<HTMLButtonElement | null>(null);
  useHotkey("FocusGlobalSearch", () => {
    buttonRef.current?.focus();
    emitCommandPaletteOpen();
  });

  return (
    <button
      ref={buttonRef}
      type="button"
      onClick={() => emitCommandPaletteOpen()}
      aria-label="Open command palette"
      className="focus-ring inline-flex h-9 w-full max-w-md items-center gap-2 rounded-full border border-input/70 bg-surface/60 px-3 text-left text-sm text-muted-foreground transition-colors hover:bg-surface hover:text-foreground"
      style={{ fontFamily: "var(--font-sans)" }}
      data-shell-region="topbar-search"
    >
      <Search aria-hidden="true" className="size-4 shrink-0" />
      <span className="flex-1 truncate">Search commands and pages...</span>
      <kbd
        className="rounded-full border border-border/60 bg-background/80 px-2 py-0.5 text-[0.6875rem] font-medium"
        style={{ fontFamily: "var(--font-mono)" }}
      >
        {formatShortcut("Mod+/")}
      </kbd>
    </button>
  );
}
