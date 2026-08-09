import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";

/**
 * Client bindings for the Admin impersonation contract defined in
 * spec/21-app/46-impersonation.md. This module owns the wire schemas,
 * the two mutations (start / end), and the browser-side "active
 * impersonation" record that <ImpersonationBanner /> reads to render
 * on every _authenticated route per AC-IMP-008.
 *
 * Do NOT extend the persisted record with tokens; access/refresh tokens
 * live only in lara-api-session. This record carries the operator's
 * identity, the target's identity, and the non-extendable ExpiresAt.
 */

const ACTIVE_STORAGE_KEY = "LicensingPortal.ActiveImpersonation";

export const impersonationStartRequestSchema = z.object({
  Reason: z.string().trim().min(8).max(500),
});
export type ImpersonationStartRequest = z.infer<typeof impersonationStartRequestSchema>;

export const impersonationSessionEnvelopeSchema = z.object({
  SessionId: z.string().uuid(),
  ImpersonatorUserId: z.number().int().positive(),
  TargetUserId: z.number().int().positive(),
  Kind: z.literal("Impersonation"),
  ExpiresAt: z.string().datetime(),
});
export type ImpersonationSessionEnvelope = z.infer<typeof impersonationSessionEnvelopeSchema>;

export const impersonationEndReasonSchema = z.enum(["OperatorEnded", "AdminForced"]);
export type ImpersonationEndReason = z.infer<typeof impersonationEndReasonSchema>;

export const impersonationEndResponseSchema = z.object({
  SessionId: z.string().uuid(),
  EndedAt: z.string().datetime(),
  EndReason: z.enum(["OperatorEnded", "Timeout", "AdminForced"]),
});
export type ImpersonationEndResponse = z.infer<typeof impersonationEndResponseSchema>;

export async function startImpersonation(
  targetUserId: number,
  input: ImpersonationStartRequest,
  idempotencyKey: string,
): Promise<ImpersonationSessionEnvelope> {
  const [envelope] = await requestLaraApi(
    `/Users/${targetUserId}/Impersonate`,
    impersonationSessionEnvelopeSchema,
    {
      method: HttpMethodType.Post,
      body: impersonationStartRequestSchema.parse(input),
      headers: { "Idempotency-Key": idempotencyKey },
    },
  );
  saveActiveImpersonation(envelope);

  return envelope;
}

export async function endImpersonation(
  endReason: ImpersonationEndReason,
  idempotencyKey: string,
): Promise<ImpersonationEndResponse> {
  const [result] = await requestLaraApi("/Impersonation/End", impersonationEndResponseSchema, {
    method: HttpMethodType.Post,
    body: { EndReason: impersonationEndReasonSchema.parse(endReason) },
    headers: { "Idempotency-Key": idempotencyKey },
  });
  clearActiveImpersonation();

  return result;
}

function hasLocalStorage(): boolean {
  return typeof window === "object";
}

export function saveActiveImpersonation(envelope: ImpersonationSessionEnvelope): void {
  if (hasLocalStorage() === false) return;
  window.localStorage.setItem(ACTIVE_STORAGE_KEY, JSON.stringify(envelope));
}

export function clearActiveImpersonation(): void {
  if (hasLocalStorage() === false) return;
  window.localStorage.removeItem(ACTIVE_STORAGE_KEY);
}

export function readActiveImpersonation(): ImpersonationSessionEnvelope | undefined {
  if (hasLocalStorage() === false) return undefined;
  const raw = window.localStorage.getItem(ACTIVE_STORAGE_KEY);
  if (typeof raw !== "string") return undefined;
  const parsed = impersonationSessionEnvelopeSchema.safeParse(safeJsonParse(raw));
  const isFailed = !parsed.success;
  if (isFailed) {
    console.warn("Impersonation record corrupt; clearing", { issues: parsed.error.issues });
    clearActiveImpersonation();

    return undefined;
  }

  return parsed.data;
}

function safeJsonParse(raw: string): unknown {
  try {
    return JSON.parse(raw);
  } catch (error) {
    console.warn("Impersonation record JSON parse failed", { error });

    return undefined;
  }
}
