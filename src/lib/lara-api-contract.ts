/**
 * Backward-compat re-export shim. The canonical envelope schemas and
 * decoders live in `./lara-envelope.ts` (Plan 11 step 26). This file
 * exists so pre-existing imports from `@/lib/lara-api-contract`
 * continue to resolve without a project-wide sweep.
 *
 * Do NOT add new schemas here. Extend `lara-envelope.ts` instead.
 */

export {
  apiAttributesSchema,
  apiFailureLenientSchema,
  apiFailureSchema,
  apiStatusSchema,
  createApiSuccessSchema,
  type ApiFailure,
  type ApiFailureLenient,
} from "./lara-envelope";
