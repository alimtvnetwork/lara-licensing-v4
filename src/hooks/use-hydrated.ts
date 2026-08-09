/**
 * useHydrated (Plan 16 Step 14 support).
 *
 * Returns `false` on the server and on the first client render, then `true`
 * after the initial `useEffect` fires. Any code that would diverge between
 * SSR and CSR (localStorage reads, /version.json fetches, window-only APIs)
 * MUST be gated behind this hook per `spec/28-runtime-modes/02-mode-selection-precedence.md`
 * rule F-04 (zero hydration diff on `<RuntimeBanner>` and gated data reads).
 */

import { useEffect, useState } from "react";

export function useHydrated(): boolean {
  const [hydrated, setHydrated] = useState(false);
  useEffect(() => {
    setHydrated(true);
  }, []);

  return hydrated;
}
