import { useEffect, useRef } from "react";
import { toast } from "sonner";

import { LaraApiError } from "./lara-api-error";
import { formatLaraApiError } from "./lara-api-error";

/**
 * Surfaces a LaraApiError as a Sonner toast the moment a mutation transitions
 * from ok -> error. Guarantees `X-Request-Id` is copy-visible per
 * spec/21-app/20-observability.md. Keyed on error identity to avoid re-firing
 * on re-renders. Silent-failure ban: unknown errors are still toasted.
 */
export function useLaraErrorToast(error: unknown, title = "Request failed"): void {
  const lastRef = useRef<unknown>(null);
  useEffect(() => {
    if (!error || error === lastRef.current) return;
    lastRef.current = error;
    const description = formatLaraApiError(error);
    console.error(`[lara-toast] ${title}`, {
      description,
      requestId: error instanceof LaraApiError ? error.requestId : undefined,
    });
    toast.error(title, { description });
  }, [error, title]);
}
