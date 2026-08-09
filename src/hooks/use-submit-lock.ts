import { useState, useEffect } from "react";
import { LaraApiError } from "@/lib/lara-api-error";

export function useSubmitLock() {
  const [lockedUntil, setLockedUntil] = useState<number | null>(null);
  const [now, setNow] = useState(Date.now());

  useEffect(() => {
    if (lockedUntil === null) return;

    // Update 'now' every second to re-evaluate isLocked and allow countdowns
    const interval = setInterval(() => setNow(Date.now()), 1000);

    if (now >= lockedUntil) {
      setLockedUntil(null);
      clearInterval(interval);

      return;
    }

    return () => clearInterval(interval);
  }, [lockedUntil, now]);

  const isLocked = lockedUntil !== null && Date.now() < lockedUntil;
  const remainingSeconds = isLocked ? Math.ceil((lockedUntil - Date.now()) / 1000) : 0;

  const handleLaraError = (e: unknown) => {
    if (e instanceof LaraApiError) {
      if (e.httpStatus === 429 || e.httpStatus === 503) {
        let seconds = e.rateLimit?.retryAfterSeconds;
        if (typeof seconds !== "number" || seconds <= 0 || isNaN(seconds)) {
          seconds = 30;
        }
        setLockedUntil(Date.now() + seconds * 1000);
        setNow(Date.now());
      }
    }
  };

  return { isLocked, remainingSeconds, handleLaraError };
}
