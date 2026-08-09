/**
 * Preview fixture module contract (Plan 16 Step 34).
 *
 * Every resource-domain module under `src/lib/preview-fixtures/` MUST
 * default-export a `PreviewFixtureModule`. Steps 40..50 fill in the
 * `register()` bodies with real handlers via `registerPreviewHandler`.
 * Today the stubs register nothing so `findMissingPreviewHandlers()`
 * still reports every operationId as missing (INV-RM-04 stays loud).
 */

import type { OperationId } from "@/generated/api/operations";

export interface PreviewFixtureModule {
  /** Stable domain name; used by tests and coverage reports. */
  readonly name: string;
  /** Operations this module is responsible for (informational until Step 84). */
  readonly operations: readonly OperationId[];
  /** Register handlers with the preview-transport registry. No-op stub today. */
  register(): void;
}
