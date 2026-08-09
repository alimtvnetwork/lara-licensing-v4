// Plan 06 step 80. Client-side quota preflight for the Inertia console.
//
// Mirrors `preflightLicenseQuota` in src/lib/lara-quota.ts (lines 452-475) and
// the same normative sources:
//   - spec/21-app/11-api-contracts/02-license-contracts.md §Reseller quota
//     decrement (steps 3, 4; AC-API-LIC-006)
//   - spec/21-app/41-reseller-quotas.md §2 (LicensesRemaining is derived,
//     never persisted)
//   - spec/21-app/12-error-taxonomy.md (QuotaCategoryUnauthorized 403,
//     QuotaExhausted 409)
//
// This is a UX preflight, NOT enforcement: the server stays authoritative
// (Reseller\QuotaRequestController::store and the license issue path both
// re-check). When the caller has no quota rows yet (empty props because the
// shard read returned nothing) the decision is `Unknown` and the wire trip
// proceeds so the server envelope is the observed outcome.
//
// Two intents exist because exhaustion means opposite things:
//   - "issue": consuming an allowance. Exhausted MUST block (409).
//   - "request": asking for MORE allowance. Exhausted is the very reason to
//     submit, so it never blocks; only an unprovisioned (category, tier)
//     tuple does, because the server rejects that tuple outright.

import { LaraApiError } from "./lara-api";

/** Closed-set codes this module can produce, from spec 12. */
export const QuotaPreflightCode = {
  QuotaCategoryUnauthorized: "QuotaCategoryUnauthorized",
  QuotaExhausted: "QuotaExhausted",
} as const;
export type QuotaPreflightCodeValue =
  (typeof QuotaPreflightCode)[keyof typeof QuotaPreflightCode];

export type QuotaPreflightIntent = "issue" | "request";

export interface QuotaPreflightRow {
  LicenseCategoryId: number;
  LicenseTierId: number;
  LicensesGranted: number;
  LicensesConsumed: number;
  /** Derived server-side; recomputed here when the prop omits it. */
  LicensesRemaining?: number | null;
}

export type QuotaPreflightDecision =
  | { Outcome: "Allowed"; Remaining: number }
  | { Outcome: "Unknown" }
  | {
      Outcome: "Blocked";
      Code: QuotaPreflightCodeValue;
      HttpStatus: 403 | 409;
      Message: string;
    }
  | { Outcome: "Warned"; Code: QuotaPreflightCodeValue; Message: string; Remaining: number };

/**
 * Preflight decision shaped exactly like the server envelope error the same
 * attempt would produce, so call sites never branch on "local vs remote".
 */
export class QuotaPreflightError extends LaraApiError {
  readonly httpStatus: 403 | 409;

  constructor(code: QuotaPreflightCodeValue, message: string, httpStatus: 403 | 409) {
    super(code, message, "local-preflight", "quota.preflight");
    this.name = "QuotaPreflightError";
    this.httpStatus = httpStatus;
  }
}

export function remainingFor(row: QuotaPreflightRow): number {
  if (typeof row.LicensesRemaining === "number") return row.LicensesRemaining;
  return row.LicensesGranted - row.LicensesConsumed;
}

export function findQuotaRow(
  quotas: ReadonlyArray<QuotaPreflightRow> | undefined | null,
  categoryId: number,
  tierId: number,
): QuotaPreflightRow | undefined {
  if (!quotas) return undefined;
  return quotas.find(
    (row) => row.LicenseCategoryId === categoryId && row.LicenseTierId === tierId,
  );
}

export function evaluateQuotaPreflight(
  quotas: ReadonlyArray<QuotaPreflightRow> | undefined | null,
  categoryId: number,
  tierId: number,
  intent: QuotaPreflightIntent = "issue",
): QuotaPreflightDecision {
  if (!quotas || quotas.length === 0) return { Outcome: "Unknown" };

  const row = findQuotaRow(quotas, categoryId, tierId);
  if (row === undefined) {
    return {
      Outcome: "Blocked",
      Code: QuotaPreflightCode.QuotaCategoryUnauthorized,
      HttpStatus: 403,
      Message: `No quota provisioned for category ${categoryId}, tier ${tierId}.`,
    };
  }

  const remaining = remainingFor(row);
  if (remaining <= 0) {
    if (intent === "request") {
      return {
        Outcome: "Warned",
        Code: QuotaPreflightCode.QuotaExhausted,
        Message: `Allowance for category ${categoryId}, tier ${tierId} is fully consumed.`,
        Remaining: remaining,
      };
    }
    return {
      Outcome: "Blocked",
      Code: QuotaPreflightCode.QuotaExhausted,
      HttpStatus: 409,
      Message: `Quota exhausted for category ${categoryId}, tier ${tierId}.`,
    };
  }

  return { Outcome: "Allowed", Remaining: remaining };
}

/**
 * Throwing wrapper for call sites that already have a LaraApiError catch
 * branch (mirrors the SPA signature). Absolute granted/consumed counts stay
 * out of the message per the no-leak clause of AC-ERR-006.
 */
export function assertQuotaPreflight(
  quotas: ReadonlyArray<QuotaPreflightRow> | undefined | null,
  categoryId: number,
  tierId: number,
  intent: QuotaPreflightIntent = "issue",
): QuotaPreflightDecision {
  const decision = evaluateQuotaPreflight(quotas, categoryId, tierId, intent);
  if (decision.Outcome === "Blocked") {
    throw new QuotaPreflightError(decision.Code, decision.Message, decision.HttpStatus);
  }
  return decision;
}
