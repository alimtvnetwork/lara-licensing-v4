/**
 * Plan 16 step 67. Real-backend type barrel.
 *
 * Root cause this file addresses (one sentence): future route migrations
 * (admin.serials, admin.audit, admin.licenses) need a stable, type-only
 * import surface that names the exact shapes the Laravel BE returns, so a
 * migration diff shows a single import swap from `./schema` to
 * `./real-be-schema` and does not depend on regenerating
 * `schema.d.ts` first.
 *
 * Design rule: this module MUST NOT define new shapes. Every type here is
 * `z.infer<>` of a schema in `src/lib/lara-*.ts`, which is itself the
 * canonical validator against real BE responses. If a shape needs to
 * change, edit the Zod schema and let the inferred type follow. Adding a
 * hand-written type here is a review-blocking mistake because it can drift
 * from the runtime validator.
 *
 * Non-goal: this does NOT replace `./schema.d.ts` or `./operations.ts`.
 * Those still drive the preview transport in `src/lib/api-client.ts` and
 * the routes currently on `useApi` (`admin.runtime.tsx`, quarantined
 * `admin.quotas.tsx`). Migration to real BE happens per-route.
 *
 * Consumers:
 *   - Route files migrating to `requestLaraApi` may import types from
 *     here for local `useState<...>` / prop typing.
 *   - `tests/schema-vs-runtime-parity.test.ts` (step 66) references these
 *     shapes to detect regressions.
 */

export type {
  License,
  LicenseCreateInput,
  LicenseUpdateInput,
  LicenseDeleteResult,
  LicenseWithEtag,
  LicenseCategoryIdValue,
  LicenseTierIdValue,
  LicenseRestoreSkippedReason,
} from "@/lib/lara-license";

export type {
  ResellerQuota,
  QuotaLedgerRow,
  QuotaRequest,
  QuotaAdjustmentResult,
  QuotaRequestStatusValue,
  QuotaLedgerActionValue,
  QuotaAdjustInput,
  QuotaRequestSubmitInput,
  QuotaRequestApproveInput,
  QuotaRequestDenyInput,
  AdminQuotaRequestRow,
} from "@/lib/lara-quota";

export type {
  SerialCreateResult,
  SerialLookup,
  SerialCreateInput,
  RandomLengthValue,
} from "@/lib/lara-serial";

export type { AuditLog, AuditLogFilters } from "@/lib/lara-audit";
