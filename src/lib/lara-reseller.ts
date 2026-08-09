import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";

const RESELLER_LIMIT = 100;

export const resellerSchema = z.object({
  ResellerId: z.number().int().positive(),
  ResellerName: z.string().min(1),
  ResellerSlug: z.string().min(1),
  ContactEmail: z.string().email(),
  IsActive: z.boolean(),
  CreatedAt: z.string().datetime(),
  UpdatedAt: z.string().datetime(),
});

export type Reseller = z.infer<typeof resellerSchema>;

export const resellersQueryOptions = queryOptions({
  queryKey: ["LaraApi", "Resellers", RESELLER_LIMIT],
  queryFn: ({ signal }) =>
    requestLaraApi(`/Resellers?Limit=${RESELLER_LIMIT}`, resellerSchema, { signal }),
  retry: false,
});

export const resellerCreateSchema = z.object({
  ResellerName: z.string().trim().min(1).max(200),
  ContactEmail: z.string().trim().email().max(320),
  IsActive: z.boolean(),
});

export type ResellerCreateInput = z.infer<typeof resellerCreateSchema>;

/**
 * Reseller mutations require Idempotency-Key per
 * spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md.
 */
export async function createReseller(
  input: ResellerCreateInput,
  idempotencyKey: string,
): Promise<Reseller> {
  const [created] = await requestLaraApi("/Resellers", resellerSchema, {
    method: HttpMethodType.Post,
    body: input,
    headers: { "Idempotency-Key": idempotencyKey },
  });

  return created;
}

export const resellerUpdateSchema = resellerCreateSchema.partial();
export type ResellerUpdateInput = z.infer<typeof resellerUpdateSchema>;

export function resellerQueryOptions(resellerId: number) {
  return queryOptions({
    queryKey: ["LaraApi", "Resellers", "Detail", resellerId],
    queryFn: ({ signal }) => requestLaraApi(`/Resellers/${resellerId}`, resellerSchema, { signal }),
    retry: false,
  });
}

export async function updateReseller(
  resellerId: number,
  input: ResellerUpdateInput,
  idempotencyKey: string,
): Promise<Reseller> {
  const [updated] = await requestLaraApi(`/Resellers/${resellerId}`, resellerSchema, {
    method: HttpMethodType.Patch,
    body: input,
    headers: { "Idempotency-Key": idempotencyKey },
  });

  return updated;
}

export async function deleteReseller(resellerId: number, idempotencyKey: string): Promise<void> {
  await requestLaraApi(`/Resellers/${resellerId}`, z.unknown(), {
    method: HttpMethodType.Delete,
    headers: { "Idempotency-Key": idempotencyKey },
  });
}
