/**
 * Canonical Zod parser for the Lara API envelope shape:
 *   { Status, Attributes: { RequestId, ErrorId? }, Results: [] }
 *
 * Plan 11 step 26 extracts this parser out of `lara-api-contract.ts` /
 * `lara-api-response.ts` so that every downstream consumer (step 27
 * `isRetryable`, step 28 error-store normalizer, step 42 envelope
 * contract snapshot test) imports from ONE module. Previously the
 * schema definitions lived in `lara-api-contract.ts` while the
 * success/failure decoding logic lived in `lara-api-response.ts`,
 * which meant any snapshot lock or type-narrow helper had to pick one
 * of two files and future refactors could silently drift.
 *
 * This module is intentionally free of `Response` / `Headers` / fetch
 * concerns; it only decodes a parsed JSON body against the envelope
 * schemas. HTTP status, header extraction, and `LaraApiError`
 * construction remain in `lara-api-response.ts`.
 *
 * Backward compatibility: `lara-api-contract.ts` re-exports every
 * symbol from this module so pre-existing imports keep working.
 */

import { z } from "zod";

import { ApiErrorCodeType } from "./lara-api-error";

export const apiStatusSchema = z.object({
  IsSuccess: z.boolean(),
  Code: z.number().int(),
  Message: z.string(),
});

export const apiAttributesSchema = z.object({
  RequestId: z.string(),
  RequestedAt: z.string(),
  // Populated by backend/bootstrap/app.php line 90 for 5xx renders only.
  // Optional here so 4xx envelopes (which intentionally omit it) still
  // parse under the strict schema. Preserved end-to-end for operator
  // correlation with lara-diag logs.
  ErrorId: z.string().optional(),
  Category: z.string().optional(),
  OperationId: z.string().optional(),
});

export const apiFailureSchema = z.object({
  Status: apiStatusSchema,
  Attributes: apiAttributesSchema.extend({
    Error: z
      .object({
        ErrorCode: z.nativeEnum(ApiErrorCodeType),
        ErrorMessage: z.string(),
        // Backend `ApiEnvelope::failure` (line 66) attaches Details only
        // when non-empty. Kept fully permissive here because Details is
        // an open payload (field errors, upstream codes, etc.) that the
        // caller renders as-is per spec/21-app/12-error-taxonomy.md.
        Details: z.array(z.unknown()).optional(),
      })
      .passthrough(),
  }),
  Results: z.array(z.never()),
});

/**
 * Lenient variant used ONLY when the strict schema rejects an envelope solely
 * because ErrorCode is not (yet) in ApiErrorCodeType. Lets us log the raw
 * unknown code with full context per spec/21-app/12-error-taxonomy.md instead
 * of collapsing it to a generic "envelope mismatch". See F4 in
 * .lovable/pending-issues/issue-002-lib-runtime-spec-drift.md.
 */
export const apiFailureLenientSchema = z.object({
  Status: apiStatusSchema,
  Attributes: apiAttributesSchema.extend({
    Error: z
      .object({
        ErrorCode: z.string(),
        ErrorMessage: z.string(),
        Details: z.array(z.unknown()).optional(),
      })
      .passthrough(),
  }),
  Results: z.array(z.never()),
});

export function createApiSuccessSchema<T>(resultSchema: z.ZodType<T>) {
  return z.object({
    Status: apiStatusSchema,
    Attributes: apiAttributesSchema,
    Results: z.array(resultSchema),
  });
}

export type ApiFailure = z.infer<typeof apiFailureSchema>;
export type ApiFailureLenient = z.infer<typeof apiFailureLenientSchema>;

/**
 * Discriminated result of decoding a failure envelope. Callers in
 * `lara-api-response.ts` map this to a `LaraApiError` after attaching
 * HTTP status, headers, and rate-limit metadata. Kept here so step 42
 * (contract snapshot) and step 27 (`isRetryable`) can consume the
 * same shape without importing HTTP concerns.
 */
export type LaraFailureDecode =
  | { kind: "strict"; failure: ApiFailure }
  | { kind: "lenient"; failure: ApiFailureLenient }
  | { kind: "mismatch"; issues: z.ZodIssue[] };

export function decodeLaraFailure(body: unknown): LaraFailureDecode {
  const strict = apiFailureSchema.safeParse(body);
  if (strict.success) return { kind: "strict", failure: strict.data };
  const lenient = apiFailureLenientSchema.safeParse(body);
  if (lenient.success) return { kind: "lenient", failure: lenient.data };

  return { kind: "mismatch", issues: strict.error.issues };
}

export type LaraSuccessDecode<T> =
  | { kind: "success"; results: T[] }
  | { kind: "mismatch"; issues: z.ZodIssue[] };

export function decodeLaraSuccess<T>(body: unknown, schema: z.ZodType<T>): LaraSuccessDecode<T> {
  const parsed = createApiSuccessSchema(schema).safeParse(body);
  if (parsed.success) return { kind: "success", results: parsed.data.Results };

  return { kind: "mismatch", issues: parsed.error.issues };
}
