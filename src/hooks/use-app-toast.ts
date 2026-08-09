import { toast } from "sonner";

import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

/**
 * `useAppToast()` returns a stable API for surfacing outcomes as Toasts per
 * spec 24 §23.2.6. Toast is BANNED for error codes that carry surface-blocking
 * or field-scoped semantics; the eligible set is small and closed:
 *
 *   Conflict-family      : LicenseConflict, PrefixConflict, ResellerConflict,
 *                          UserConflict, ResourceRoleAlreadyAssigned, IdempotencyConflict
 *   IdempotencyReplay    : (idempotency-key repeat with distinct body)
 *   AuthRefreshRaceLost  : transient auth race (spec/21-app/12-error-taxonomy.md)
 *   Transient/unknown    : ServerError, ServiceUnavailable, UnknownServerError
 *
 * Every other ErrorCode is a routing violation:
 * - `RateLimited` MUST render <RetryAfterBanner> (spec §23.4).
 * - `ValidationFailed`, `AuthzRoleDenied`, `AuthzRowScopeDenied`,
 *   `AuthzLastAdminProtected`, `AuthUnauthorized`, `AuthTokenExpired`,
 *   `AuthTokenInvalid`, `EnvironmentMismatch`, `QuotaExhausted`,
 *   `QuotaCategoryUnauthorized`, `FeatureUnknown`, `FeatureValueInvalid`,
 *   `PreconditionRequired`, `PreconditionFailed`, `*NotFound`,
 *   `AuthSaltRotationFailed` MUST route to Banner or inline Field.
 *
 * Violations throw in dev (Vite dev + tests) and log a `ToastRoutingViolation`
 * warning in prod, then downgrade to a warning toast so the caller is not
 * left without any surfacing. This is the "no silent swallow" contract from
 * spec/21-app/12-error-taxonomy.md.
 */
export const TOAST_ELIGIBLE_ERROR_CODES: ReadonlySet<ApiErrorCodeType> = new Set([
  ApiErrorCodeType.LicenseConflict,
  ApiErrorCodeType.PrefixConflict,
  ApiErrorCodeType.ResellerConflict,
  ApiErrorCodeType.UserConflict,
  ApiErrorCodeType.ResourceRoleAlreadyAssigned,
  ApiErrorCodeType.IdempotencyConflict,
  ApiErrorCodeType.AuthRefreshRaceLost,
  ApiErrorCodeType.ServerError,
  ApiErrorCodeType.ServiceUnavailable,
  ApiErrorCodeType.UnknownServerError,
]);

export type ToastVariant = "success" | "info" | "warning" | "error";

interface AppToastOptions {
  description?: string;
  requestId?: string;
  action?: { label: string; onClick: () => void };
  /** Override auto-dismiss ms; defaults per variant per spec §23.2.1. */
  durationMs?: number;
}

const durationByVariant: Record<ToastVariant, number> = {
  success: 4000,
  info: 6000,
  warning: 8000,
  error: 10000,
};

function isDev(): boolean {
  try {
    return Boolean((import.meta as unknown as { env?: { DEV?: boolean } }).env?.DEV);
  } catch {
    return false;
  }
}

function callToast(variant: ToastVariant, title: string, opts: AppToastOptions | undefined): void {
  const duration = opts?.durationMs ?? durationByVariant[variant];
  const description = describeWithRequestId(opts?.description, opts?.requestId);
  toast[variant](title, {
    description,
    duration,
    action: opts?.action ? { label: opts.action.label, onClick: opts.action.onClick } : undefined,
  });
}

function describeWithRequestId(description: string | undefined, requestId: string | undefined) {
  const isFailed = !requestId;
  if (isFailed) return description;
  const suffix = `Request ${requestId}`;

  return description ? `${description}\n${suffix}` : suffix;
}

function classifyError(err: LaraApiError): ToastVariant {
  if (err.errorCode === ApiErrorCodeType.LicenseConflict) return "warning";
  if (err.errorCode === ApiErrorCodeType.PrefixConflict) return "warning";
  if (err.errorCode === ApiErrorCodeType.ResellerConflict) return "warning";
  if (err.errorCode === ApiErrorCodeType.UserConflict) return "warning";
  if (err.errorCode === ApiErrorCodeType.ResourceRoleAlreadyAssigned) return "warning";
  if (err.errorCode === ApiErrorCodeType.IdempotencyConflict) return "warning";

  return "error";
}

export interface AppToastApi {
  success(title: string, opts?: AppToastOptions): void;
  info(title: string, opts?: AppToastOptions): void;
  warning(title: string, opts?: AppToastOptions): void;
  error(title: string, opts?: AppToastOptions): void;
  /**
   * Preferred entry point for surfacing a `LaraApiError` returned from a
   * mutation. Enforces the eligible-code allow-list per spec §23.2.6.
   * Non-eligible codes throw in dev / log + downgrade in prod so the caller
   * refactors to a Banner or inline surface instead of hiding the error.
   */
  fromApiError(err: unknown, fallbackTitle?: string): void;
}

export function useAppToast(): AppToastApi {
  return appToast;
}

export const appToast: AppToastApi = {
  success: (title, opts) => callToast("success", title, opts),
  info: (title, opts) => callToast("info", title, opts),
  warning: (title, opts) => callToast("warning", title, opts),
  error: (title, opts) => callToast("error", title, opts),
  fromApiError: (err, fallbackTitle = "Request failed") => {
    if (!(err instanceof LaraApiError)) {
      pushLaraApiError(new Error());
      callToast("error", fallbackTitle, {
        description: err instanceof Error ? err.message : String(err),
      });

      return;
    }
    if (TOAST_ELIGIBLE_ERROR_CODES.has(err.errorCode) === false) {
      const message =
        `ToastRoutingViolation: ErrorCode "${err.errorCode}" is not Toast-eligible. ` +
        `Route this error to a Banner or inline Field surface per spec 24 §23.2.6.`;
      console.warn("[app-toast]", message, { requestId: err.requestId });
      if (isDev()) throw new Error(message);
      callToast("warning", fallbackTitle, {
        description: err.message,
        requestId: err.requestId,
      });

      return;
    }
    const variant = classifyError(err);
    callToast(variant, fallbackTitle, {
      description: err.message,
      requestId: err.requestId,
    });
  },
};
