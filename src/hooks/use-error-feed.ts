import { useState, useEffect, useSyncExternalStore, useCallback } from "react";
import {
  subscribeErrorStore,
  getErrorStoreSnapshot,
  type ErrorStoreEntry,
} from "../lib/error-store";

const LAST_SEEN_KEY = "lara.notifications.last-seen.v1";

export function useErrorFeed() {
  const entries = useSyncExternalStore(subscribeErrorStore, getErrorStoreSnapshot, () => []);

  const [lastSeenAt, setLastSeenAt] = useState<number>(0);

  useEffect(() => {
    try {
      const stored = sessionStorage.getItem(LAST_SEEN_KEY);
      if (stored) setLastSeenAt(parseInt(stored, 10));
    } catch {
      // Ignore
    }
  }, []);

  const markAsRead = useCallback(() => {
    const now = Date.now();
    setLastSeenAt(now);
    try {
      sessionStorage.setItem(LAST_SEEN_KEY, now.toString());
    } catch {
      // Ignore
    }
  }, []);

  const unreadCount = entries.filter((e) => e.at > lastSeenAt).length;

  return {
    entries,
    unreadCount,
    markAsRead,
  };
}
