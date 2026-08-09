import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import {
  environmentIdSchema,
  parseEnvironmentId,
  type EnvironmentIdValue,
} from "./lara-environment";

export const serialCreateResultSchema = z.object({
  SerialId: z.number().int().positive(),
  LicenseId: z.number().int().positive(),
  SerialValue: z.string().min(1),
  CreatedAt: z.string().datetime(),
});

export type SerialCreateResult = z.infer<typeof serialCreateResultSchema>;

export const serialLookupSchema = serialCreateResultSchema.extend({
  IsRevoked: z.boolean(),
});

export type SerialLookup = z.infer<typeof serialLookupSchema>;

export const RandomLengthType = { Sixteen: 16, TwentyFour: 24, ThirtyTwo: 32 } as const;
export type RandomLengthValue = (typeof RandomLengthType)[keyof typeof RandomLengthType];

export const serialCreateSchema = z.object({
  PrefixId: z.number().int().positive().optional(),
  RandomLength: z.union([z.literal(16), z.literal(24), z.literal(32)]).optional(),
});

export type SerialCreateInput = z.infer<typeof serialCreateSchema>;

export async function createSerial(
  licenseId: number,
  input: SerialCreateInput,
  idempotencyKey?: string,
): Promise<SerialCreateResult> {
  const headers = idempotencyKey ? { "Idempotency-Key": idempotencyKey } : undefined;
  const [created] = await requestLaraApi(
    `/Licenses/${licenseId}/Serials`,
    serialCreateResultSchema,
    { method: HttpMethodType.Post, body: input, headers },
  );

  return created;
}

export async function lookupSerial(serialValue: string): Promise<SerialLookup> {
  const [found] = await requestLaraApi(
    `/Serials/${encodeURIComponent(serialValue)}`,
    serialLookupSchema,
  );

  return found;
}

/**
 * End-user runtime verify handshake per
 * spec/21-app/11-api-contracts/03-verification-contracts.md and the canonical
 * sequence in spec/21-app/diagrams/licensing-flow.mmd v1.2.0.
 *
 * Three POST endpoints, in strict order:
 *   1) POST /Verify/Serial  -> establishes the serial exists and is not revoked.
 *   2) POST /Verify/Hash    -> UPSERT bindings, mints a single-use VerifyKey.
 *   3) POST /Verify/Final   -> validates the VerifyKey, refreshes bindings,
 *      resolves Features (LicenseFeatures override TierFeatures per
 *      spec/21-app/45-license-features.md), and returns the authorization row.
 *
 * `EnvironmentId` on step 3 is the caller's server-derived environment; a
 * mismatch with `License.EnvironmentId` returns 409 `EnvironmentMismatch`
 * per spec/21-app/44-environments.md §3 (AC-LENV-004). The token pair in
 * `Details[0].Value` is opaque ("<Requested>/<Licensed>") and MUST NOT be
 * decoded by the client.
 *
 * Do NOT send `Idempotency-Key` on verify routes; the envelope hardening
 * rules in spec/21-app/08-idempotency-envelope-hardening.md explicitly
 * exclude verify from replay storage.
 */
export const verifySerialResultSchema = z.object({
  IsValid: z.boolean(),
  LicenseId: z.number().int().positive(),
  Category: z.string().min(1),
  ExpiresAt: z.string().datetime().optional(),
  IsSingleUse: z.boolean(),
  UserCount: z.number().int().nonnegative().optional(),
  MachineCount: z.number().int().nonnegative().optional(),
});
export type VerifySerialResult = z.infer<typeof verifySerialResultSchema>;

export const verifyHashResultSchema = z.object({
  VerifyKey: z.string().min(1),
  ExpiresAt: z.string().datetime(),
});
export type VerifyHashResult = z.infer<typeof verifyHashResultSchema>;

/**
 * Features map on `POST /Verify/Final`. Keys are `FeatureKey` values from
 * spec/21-app/45-license-features.md §2 (closed set), values are one of the
 * declared `ValueType` shapes. We accept `unknown` here because the strict
 * per-key shape check happens in feature-consuming call sites (see the
 * runtime resolver in `lara-features.ts`, to be added in Step 44).
 */
export const verifyFinalResultSchema = z.object({
  IsAuthorized: z.boolean(),
  LicenseId: z.number().int().positive(),
  LicenseTierId: z.number().int().positive(),
  EnvironmentId: environmentIdSchema,
  Features: z.record(z.string(), z.unknown()),
  AuthorizedAt: z.string().datetime(),
  ExpiresAt: z.string().datetime().optional(),
  MachineBindingId: z.number().int().positive().optional(),
  UserBindingId: z.number().int().positive().optional(),
});
export type VerifyFinalResult = z.infer<typeof verifyFinalResultSchema>;

export const machineFingerprintSchema = z.object({
  MachineId: z.string().min(1),
  Hostname: z.string().min(1).optional(),
  Os: z.string().min(1).optional(),
});
export type MachineFingerprint = z.infer<typeof machineFingerprintSchema>;

export async function verifySerial(serialValue: string): Promise<VerifySerialResult> {
  const [result] = await requestLaraApi("/Verify/Serial", verifySerialResultSchema, {
    method: HttpMethodType.Post,
    body: { SerialValue: serialValue },
  });

  return result;
}

export interface VerifyHashInput {
  serialValue: string;
  hashKey: string;
  machineFingerprint: MachineFingerprint;
  userIdentifier?: string;
}

export async function verifyHash(input: VerifyHashInput): Promise<VerifyHashResult> {
  const body = {
    SerialValue: input.serialValue,
    HashKey: input.hashKey,
    MachineFingerprint: input.machineFingerprint,
    UserIdentifier: input.userIdentifier,
  };
  const [result] = await requestLaraApi("/Verify/Hash", verifyHashResultSchema, {
    method: HttpMethodType.Post,
    body,
  });

  return result;
}

export interface VerifyFinalInput {
  serialValue: string;
  hashKey: string;
  verifyKey: string;
  environmentId: EnvironmentIdValue | number;
}

export async function verifyFinal(input: VerifyFinalInput): Promise<VerifyFinalResult> {
  // Guard: reject an out-of-set EnvironmentId at the entry point per
  // spec/21-app/44-environments.md §3 (AC-LENV-004). Failing here surfaces
  // the misconfiguration in caller logs instead of after a wire round-trip
  // returns the opaque `<Requested>/<Licensed>` marker.
  const environmentId = parseEnvironmentId(input.environmentId, "environmentId");
  const body = {
    SerialValue: input.serialValue,
    HashKey: input.hashKey,
    VerifyKey: input.verifyKey,
    EnvironmentId: environmentId,
  };
  const [result] = await requestLaraApi("/Verify/Final", verifyFinalResultSchema, {
    method: HttpMethodType.Post,
    body,
  });

  return result;
}
