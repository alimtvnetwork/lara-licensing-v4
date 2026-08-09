/**
 * Plan 11 step 44: GlobalErrorModal stories.
 *
 * Storybook-compatible CSF3 stories covering the five canonical failure
 * surfaces from spec/03-error-manage/:
 *
 *   1. generic 500 ServerError with ErrorId (AC-ERR-003)
 *   2. validation 400 with 3 field-level entries in `details`
 *   3. retryable 429 RateLimited with `Retry-After` metadata
 *   4. 403 AuthForbidden
 *   5. offline (network) surfaced as UnknownServerError with no requestId
 *
 * Each story seeds the shared error-store via `pushLaraApiError` before
 * rendering `<GlobalErrorModal />`, so the visual matches production
 * exactly. This file is placed under `src/components/errors/` per the
 * plan, while the live component still lives at
 * `src/components/global/GlobalErrorModal.tsx`.
 *
 * `@storybook/react` is not yet a project dependency, so we declare
 * local `Meta` / `StoryObj` shims. When Storybook is installed the
 * shims are shadowed by the real package types. Tsgo compiles this
 * file today; Vitest ignores `.stories.tsx` (see vitest config).
 *
 * Note (Plan 11 step 49): each story `render` is kept a single JSX
 * expression by extracting the store-seed closure to a module-level
 * function, so the ESLint `max-lines-per-function` gate (15 lines,
 * enforced by the aggregated error-contract workflow) stays green.
 */

import { useEffect, type ReactElement } from "react";

import { GlobalErrorModal } from "@/components/global/GlobalErrorModal";
import { LaraApiError, ApiErrorCodeType } from "@/lib/lara-api-error";
import { clearErrorStore, pushLaraApiError } from "@/lib/error-store";

type Meta<TComponent> = {
  title: string;
  component: TComponent;
  parameters?: Record<string, unknown>;
};
type StoryObj<TComponent> = {
  name?: string;
  render: () => ReturnType<Extract<TComponent, (...args: never) => unknown>> | ReactElement;
};

function SeededModal({ seed }: { readonly seed: () => void }): ReactElement {
  useEffect(() => {
    clearErrorStore();
    seed();

    return () => {
      clearErrorStore();
    };
  }, [seed]);

  return <GlobalErrorModal />;
}

const meta: Meta<typeof GlobalErrorModal> = {
  title: "errors/GlobalErrorModal",
  component: GlobalErrorModal,
  parameters: { layout: "centered" },
};
export default meta;

type Story = StoryObj<typeof GlobalErrorModal>;

function seedGeneric500(): void {
  pushLaraApiError(
    new LaraApiError(
      "Unexpected server error. Please try again.",
      ApiErrorCodeType.ServerError,
      500,
      "req_01HFATAL500",
      undefined,
      "b7a1e2c4-1f3d-4a5c-9e8b-8c0d1f2a3b4c",
    ),
  );
}

const VALIDATION_400_DETAILS = [
  { Field: "Email", Value: "not-an-email" },
  { Field: "Password" },
  { Field: "TermsAccepted", Value: false },
];

function seedValidation400(): void {
  pushLaraApiError(
    new LaraApiError(
      "Validation failed for 3 fields.",
      ApiErrorCodeType.ValidationFailed,
      400,
      "req_01HFVAL400",
      undefined,
      undefined,
      VALIDATION_400_DETAILS,
    ),
  );
}

function seedRateLimited429(): void {
  pushLaraApiError(
    new LaraApiError(
      "Too many requests. Retry in 30s.",
      ApiErrorCodeType.RateLimited,
      429,
      "req_01HFRL429",
      { retryAfterSeconds: 30, limit: 60, windowSeconds: 60 },
    ),
  );
}

function seedForbidden403(): void {
  pushLaraApiError(
    new LaraApiError(
      "You do not have permission to perform this action.",
      ApiErrorCodeType.AuthForbidden,
      403,
      "req_01HFFBD403",
    ),
  );
}

function seedOffline(): void {
  // Offline surfaces through laraFetch as UnknownServerError with no
  // requestId and no errorId, since the request never reached the origin.
  // This matches src/lib/lara-fetch.ts fallback shape.
  pushLaraApiError(
    new LaraApiError(
      "Network request failed. Check your connection.",
      ApiErrorCodeType.UnknownServerError,
      0,
    ),
  );
}

export const Generic500ServerError: Story = {
  name: "500 ServerError (with ErrorId)",
  render: () => <SeededModal seed={seedGeneric500} />,
};

export const Validation400ThreeFieldErrors: Story = {
  name: "400 ValidationFailed (3 field errors)",
  render: () => <SeededModal seed={seedValidation400} />,
};

export const Retryable429RateLimited: Story = {
  name: "429 RateLimited (Retry-After 30s)",
  render: () => <SeededModal seed={seedRateLimited429} />,
};

export const Forbidden403: Story = {
  name: "403 AuthForbidden",
  render: () => <SeededModal seed={seedForbidden403} />,
};

export const OfflineNetworkFailure: Story = {
  name: "Offline (network unreachable)",
  render: () => <SeededModal seed={seedOffline} />,
};
