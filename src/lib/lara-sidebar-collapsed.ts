import { useEffect, useState, useSyncExternalStore } from "react";

/**
 * Shared sidebar-collapsed state for the authenticated `AppShell` per
 * spec/24-app-ui-design-system/13-navigation-ia.md §9 (collapsed rail).
 *
 * Value is persisted to `localStorage` under the PascalCase key
 * `LaraSidebarCollapsed`. Reads happen inside `useEffect` (post-mount) so
 * SSR/hydration never observes a browser-only default. A tiny pub/sub keeps
 * the sidebar toggle and other consumers in sync without prop drilling.
 */
const STORAGE_KEY = "LaraSidebarCollapsed";

let currentValue = false;
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

function getSnapshot(): boolean {
  return currentValue;
}

function getServerSnapshot(): boolean {
  return false;
}

function writeValue(next: boolean): void {
  currentValue = next;
  try {
    window.localStorage.setItem(STORAGE_KEY, next ? "1" : "0");
  } catch (err) {
    console.warn("LaraSidebarCollapsed persist failed", err);
  }
  listeners.forEach((l) => l());
}

export function useSidebarCollapsed(): [boolean, () => void] {
  const value = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
  const [hydrated, setHydrated] = useState(false);
  useEffect(() => {
    if (hydrated) return;
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (raw === "1" && currentValue !== true) writeValue(true);
    } catch (err) {
      console.warn("LaraSidebarCollapsed read failed", err);
    }
    setHydrated(true);
  }, [hydrated]);
  const toggle = () => {
    writeValue(!currentValue);
  };

  return [value, toggle];
}
