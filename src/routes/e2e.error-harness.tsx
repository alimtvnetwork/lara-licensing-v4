/**
 * Plan 11 step 35: Playwright harness route.
 *
 * Root cause this route addresses: the Global Error Modal, Global Rate
 * Limit Banner, and Copy Error ID button are all driven by the client
 * `error-store` via `pushLaraApiError`. Reproducing a real 500 or 429
 * from the backend in a Playwright run requires a fully seeded shard,
 * a rate-limit bucket configured for the test IP, and network fault
 * injection, none of which are available in the local `bun run dev`
 * loop that CI's E2E job targets. This route pushes canonical
 * `LaraApiError` instances directly into the store so the specs can
 * assert the FE contract deterministically without coupling to
 * backend timing or availability.
 *
 * The harness is a page-level surface, not a mock of `laraFetch`; it
 * exercises the exact code path (`pushLaraApiError`) that `laraFetch`
 * uses in production, so the specs prove the end-to-end wiring, not
 * a stub. The route is intentionally unlinked from any nav so it
 * never appears in the product UI.
 */

import { createFileRoute } from "@tanstack/react-router";

import { pushLaraApiError } from "@/lib/error-store";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

const FIXED_REQUEST_ID = "req-e2e-000000000001";
const FIXED_ERROR_ID = "3c6f9b6a-2b1e-4c2a-9e3d-4a2f1b5c6d7e";
const RATE_LIMIT_RETRY_SECONDS = 5;

function pushFatal() {
  // Modal ownership: `NoRetry` / `FatalClear` per classifyRetryPolicy
  // (src/lib/lara-retry.ts). Server 5xx map to ExpBackoff (toast surface),
  // so we exercise the modal path with a canonical NoRetry code that
  // still carries an ErrorId + RequestId envelope for the copy button.
  pushLaraApiError(
    new LaraApiError(
      "You are not allowed to perform this action.",
      ApiErrorCodeType.AuthForbidden,
      403,
      FIXED_REQUEST_ID,
      undefined,
      FIXED_ERROR_ID,
      undefined,
    ),
  );
}

function push429() {
  pushLaraApiError(
    new LaraApiError(
      "Too many requests.",
      ApiErrorCodeType.RateLimited,
      429,
      FIXED_REQUEST_ID,
      { retryAfterSeconds: RATE_LIMIT_RETRY_SECONDS, bucket: "e2e-bucket" },
      undefined,
      undefined,
    ),
  );
}

function RouteComponent() {
  return (
    <main className="mx-auto max-w-md p-6 space-y-3">
      <h1 className="text-lg font-semibold">E2E Error Harness</h1>
      <p className="text-sm text-muted-foreground">
        Playwright-only surface. Buttons dispatch synthetic errors into the canonical error-store.
      </p>
      <div className="flex flex-col gap-2">
        <button
          type="button"
          onClick={pushFatal}
          data-testid="e2e-trigger-fatal"
          className="rounded-md border px-3 py-2 text-sm"
        >
          Trigger fatal modal
        </button>
        <button
          type="button"
          onClick={push429}
          data-testid="e2e-trigger-429"
          className="rounded-md border px-3 py-2 text-sm"
        >
          Trigger 429 (rate-limit banner)
        </button>
      </div>
    </main>
  );
}

export const Route = createFileRoute("/e2e/error-harness")({
  component: RouteComponent,
  head: () => ({
    meta: [{ title: "E2E Error Harness" }],
  }),
});
