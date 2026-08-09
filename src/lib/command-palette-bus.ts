/**
 * Tiny pub/sub used to open the CommandPalette from surfaces that do not
 * own its state (TopbarSearch, empty-state CTAs, etc.). Plan 09 Step 20
 * needs this so `<TopbarSearch />` can trigger the same palette that
 * `Mod+K` opens without lifting palette state up into every shell.
 *
 * Contract: consumers call `subscribeCommandPaletteOpen(fn)` in an effect
 * and receive an unsubscribe. Emitters call `emitCommandPaletteOpen()`.
 * A single palette instance is expected per shell; multiple subscribers
 * are supported but all will toggle together, which is acceptable.
 */

type OpenListener = () => void;

const listeners = new Set<OpenListener>();

export function subscribeCommandPaletteOpen(listener: OpenListener): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

export function emitCommandPaletteOpen(): void {
  for (const listener of listeners) listener();
}
