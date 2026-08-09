/**
 * Command Palette per spec/24-app-ui-design-system/31-search-and-command-palette.md
 * and 32-command-registry.md. Opens on `Mod+K` (see 57-keyboard-shortcut-registry.md §5).
 *
 * Root cause the v0.282.0 refit fixes: the palette rendered every command in
 * an ungrouped list and used the raw target path as the trailing hint,
 * which read like debug output instead of a keyboard-driven launcher. The
 * palette now groups by `CommandRow.group` (v1: "Navigation") per §32.3
 * and shows the target as a subdued mono-typed path caption below the
 * label. A permanent keyboard-hint footer (↑↓ / ↵ / Esc) reinforces the
 * spec's keyboard-first mandate. All permission gating still happens at
 * the source (`commandsForRole`), so hidden rows stay hidden.
 */
import { useNavigate } from "@tanstack/react-router";
import { useCallback, useEffect, useMemo, useState } from "react";

import { subscribeCommandPaletteOpen } from "@/lib/command-palette-bus";

import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@/components/ui/command";
import { useHotkey } from "@/hooks/use-hotkey";
import { useLaraShellRole } from "@/lib/lara-shell-role";
import { commandsForRole, type CommandRow, type CommandGroupType } from "@/lib/command-registry";
import { formatShortcut } from "@/lib/shortcut-registry";

const EmptyLabel = "No matching commands.";
const InputPlaceholder = "Search commands and pages...";
const FooterHintLabel = "Navigate";
const FooterSelectLabel = "Select";
const FooterCloseLabel = "Close";

interface CommandPaletteProps {
  /** Reseller id for `$resellerId` substitution in reseller commands. */
  resellerId?: string | null;
}

export function CommandPalette({ resellerId = null }: CommandPaletteProps) {
  const role = useLaraShellRole();
  const [open, setOpen] = useState<boolean>(false);
  const onToggle = useCallback(() => setOpen((prev) => !prev), []);
  const onOpen = useCallback(() => setOpen(true), []);
  useHotkey("OpenCommandPalette", onToggle);
  useEffect(() => subscribeCommandPaletteOpen(onOpen), [onOpen]);
  const rows = useMemo(
    () => (role === null ? [] : commandsForRole(role, resellerId)),
    [role, resellerId],
  );
  const grouped = useMemo(() => groupByCommandGroup(rows), [rows]);
  if (role === null) return null;

  return (
    <CommandDialog open={open} onOpenChange={setOpen} aria-label="Command palette">
      <CommandInput placeholder={InputPlaceholder} />
      <CommandList className="max-h-[420px] py-2">
        <CommandEmpty>{EmptyLabel}</CommandEmpty>
        {grouped.map((group, index) => (
          <PaletteGroup
            key={group.heading}
            group={group}
            showSeparator={index > 0}
            onSelect={() => setOpen(false)}
          />
        ))}
      </CommandList>
      <PaletteFooter />
    </CommandDialog>
  );
}

interface RenderedGroup {
  heading: CommandGroupType;
  rows: CommandRow[];
}

function groupByCommandGroup(rows: CommandRow[]): RenderedGroup[] {
  const buckets = new Map<CommandGroupType, CommandRow[]>();
  for (const row of rows) {
    const bucket = buckets.get(row.group) ?? [];
    bucket.push(row);
    buckets.set(row.group, bucket);
  }

  return Array.from(buckets, ([heading, groupRows]) => ({ heading, rows: groupRows }));
}

interface PaletteGroupProps {
  group: RenderedGroup;
  showSeparator: boolean;
  onSelect: () => void;
}

function PaletteGroup({ group, showSeparator, onSelect }: PaletteGroupProps) {
  return (
    <>
      {showSeparator ? <CommandSeparator /> : null}
      <CommandGroup heading={group.heading}>
        {group.rows.map((row) => (
          <PaletteRow key={row.commandId} row={row} onSelect={onSelect} />
        ))}
      </CommandGroup>
    </>
  );
}

interface PaletteRowProps {
  row: CommandRow;
  onSelect: () => void;
}

function PaletteRow({ row, onSelect }: PaletteRowProps) {
  const navigate = useNavigate();
  const Icon = row.icon;
  const onPick = () => {
    onSelect();
    void navigate({ to: row.target });
  };

  return (
    <CommandItem value={`${row.label} ${row.target}`} onSelect={onPick}>
      <Icon aria-hidden="true" className="mr-2 text-muted-foreground" />
      <span className="flex-1 truncate font-medium">{row.label}</span>
      <span
        className="ml-3 truncate text-[0.6875rem] text-muted-foreground/80"
        style={{ fontFamily: "var(--font-mono)" }}
      >
        {row.target}
      </span>
    </CommandItem>
  );
}

function PaletteFooter() {
  return (
    <div
      className="flex items-center justify-between gap-3 border-t border-border/60 bg-muted/30 px-3 py-2 text-[0.6875rem] text-muted-foreground"
      style={{ fontFamily: "var(--font-sans)" }}
    >
      <div className="flex items-center gap-3">
        <FooterHint keyLabel="↑↓">{FooterHintLabel}</FooterHint>
        <FooterHint keyLabel="↵">{FooterSelectLabel}</FooterHint>
        <FooterHint keyLabel="Esc">{FooterCloseLabel}</FooterHint>
      </div>
      <span style={{ fontFamily: "var(--font-mono)" }}>{formatShortcut("Mod+K")}</span>
    </div>
  );
}

function FooterHint({ keyLabel, children }: { keyLabel: string; children: string }) {
  return (
    <span className="flex items-center gap-1">
      <kbd
        className="rounded border border-border/60 bg-background px-1 py-0.5"
        style={{ fontFamily: "var(--font-mono)" }}
      >
        {keyLabel}
      </kbd>
      <span>{children}</span>
    </span>
  );
}

/** Public helper for the App-bar hint. */
export function commandPaletteHint(): string {
  return formatShortcut("Mod+K");
}
