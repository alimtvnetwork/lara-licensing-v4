import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";

/**
 * Plan 09 step 41-42. Transport for the admin self-update surface.
 *
 * Root cause this closes: the sidebar advertised `/admin/app-updates` as
 * status "C" (coming soon) but the full publish saga is already shipped
 * server-side (`Admin\AppUpdateController` uploadTicket/publish/yank +
 * public read paths under `/App/UpdateManifest`, `/App/UpdateAsset/*`).
 * Without a client-side transport and route, the only way to publish or
 * yank a release was `curl` + hand-crafted `Idempotency-Key` headers,
 * i.e. the exact manual workflow the backend was built to replace.
 *
 * This module wraps the four Admin endpoints with a Zod-locked contract:
 *   GET  /Api/Admin/AppUpdates                 -> list (newest first)
 *   POST /Api/Admin/AppUpdates/UploadTicket    -> reserve upload token
 *   POST /Api/Admin/AppUpdates                 -> finalize publish
 *   POST /Api/Admin/AppUpdates/{Version}/Yank  -> yank a version
 *
 * All mutations require `Idempotency-Key` per
 * spec/21-app/17-self-update-endpoint.md §"Admin invariants" §4.
 */

export const appUpdateAssetSchema = z.object({
  Platform: z.string().min(1),
  SizeBytes: z.number().int().nonnegative(),
  Sha256: z.string().min(1),
  HasSignature: z.boolean(),
});

export const appUpdateSchema = z.object({
  AppUpdateId: z.number().int().positive(),
  Product: z.string().min(1),
  Channel: z.string().min(1),
  Version: z.string().min(1),
  MinRequiredVersion: z.string().min(1),
  ReleaseNotesUrl: z.string().nullable(),
  PublishedAt: z.string().nullable(),
  PublishedByUserId: z.number().int().nonnegative(),
  IsYanked: z.boolean(),
  YankedAt: z.string().nullable(),
  Assets: z.array(appUpdateAssetSchema),
});

export type AppUpdate = z.infer<typeof appUpdateSchema>;
export type AppUpdateAsset = z.infer<typeof appUpdateAssetSchema>;

const APP_UPDATES_PATH = "/Admin/AppUpdates";
const DEFAULT_PRODUCT = "lara-cli";

export function appUpdatesQueryOptions(product: string = DEFAULT_PRODUCT) {
  const qs = new URLSearchParams({ Product: product }).toString();

  return queryOptions({
    queryKey: ["LaraApi", "Admin", "AppUpdates", product],
    queryFn: ({ signal }) =>
      requestLaraApi(`${APP_UPDATES_PATH}?${qs}`, appUpdateSchema, { signal }),
    retry: false,
    staleTime: 15_000,
  });
}

export const yankResultSchema = z.object({
  Product: z.string().min(1),
  Version: z.string().min(1),
  IsYanked: z.number().int(),
  YankedAt: z.string().nullable(),
});
export type YankResult = z.infer<typeof yankResultSchema>;

export interface YankRequest {
  Product: string;
  Version: string;
  IdempotencyKey: string;
}

export async function yankAppUpdate(request: YankRequest): Promise<YankResult> {
  const qs = new URLSearchParams({ Product: request.Product }).toString();
  const [result] = await requestLaraApi(
    `${APP_UPDATES_PATH}/${encodeURIComponent(request.Version)}/Yank?${qs}`,
    yankResultSchema,
    {
      method: HttpMethodType.Post,
      headers: { "Idempotency-Key": request.IdempotencyKey },
    },
  );

  return result;
}

/* ------------------------------------------------------------------
 * Plan 09 step: publish upload UI.
 *
 * The publish saga is three legs (spec 17 v1.3.0 §"Publish state machine"):
 *   1. POST /Admin/AppUpdates/UploadTicket per (Product, Version, Platform)
 *      -> {UploadToken, UploadUrl, ExpiresAt}
 *   2. PUT UploadUrl (raw bytes; UploadToken IS the bearer, no Sanctum)
 *   3. POST /Admin/AppUpdates to finalize with Assets[] carrying every
 *      UploadToken minted in step 1.
 * Idempotency-Key is required on legs 1 and 3.
 * ------------------------------------------------------------------ */

export const uploadTicketResultSchema = z.object({
  UploadToken: z.string().min(1),
  UploadUrl: z.string().min(1),
  ExpiresAt: z.string().nullable(),
});
export type UploadTicketResult = z.infer<typeof uploadTicketResultSchema>;

export interface UploadTicketRequest {
  Product: string;
  Version: string;
  Platform: string;
  SizeBytes: number;
  Sha256: string;
  IdempotencyKey: string;
}

export async function reserveUploadTicket(
  request: UploadTicketRequest,
): Promise<UploadTicketResult> {
  const [result] = await requestLaraApi(
    `${APP_UPDATES_PATH}/UploadTicket`,
    uploadTicketResultSchema,
    {
      method: HttpMethodType.Post,
      body: {
        Product: request.Product,
        Version: request.Version,
        Platform: request.Platform,
        SizeBytes: request.SizeBytes,
        Sha256: request.Sha256,
      },
      headers: { "Idempotency-Key": request.IdempotencyKey },
    },
  );

  return result;
}

export async function uploadAssetBytes(
  uploadUrl: string,
  bytes: ArrayBuffer,
  signal?: AbortSignal,
): Promise<void> {
  // eslint-disable-next-line no-restricted-globals -- signed-URL binary upload to storage; not a Lara envelope endpoint, so `laraFetch` (which enforces `{Status,Attributes,Results}` parsing) does not apply.
  const response = await fetch(uploadUrl, {
    method: "PUT",
    body: bytes,
    headers: { "Content-Type": "application/octet-stream" },
    signal,
  });
  const isFailed = !response.ok;
  if (isFailed) {
    const text = await response.text();
    throw new Error(`Asset upload failed (${response.status}): ${text.slice(0, 200)}`);
  }
}

const publishAssetResultSchema = z.object({
  Platform: z.string(),
  Url: z.string(),
  SizeBytes: z.number().int().nonnegative(),
  Sha256: z.string(),
  SignatureUrl: z.string().nullable(),
});

export const publishResultSchema = z.object({
  Product: z.string(),
  Channel: z.string(),
  LatestVersion: z.string(),
  MinRequiredVersion: z.string(),
  PublishedAt: z.string().nullable(),
  ReleaseNotesUrl: z.string().nullable(),
  Assets: z.array(publishAssetResultSchema),
});
export type PublishResult = z.infer<typeof publishResultSchema>;

export interface PublishAssetInput {
  Platform: string;
  Sha256: string;
  SizeBytes: number;
  UploadToken: string;
}

export interface PublishRequest {
  Product: string;
  Channel: string;
  Version: string;
  MinRequiredVersion: string;
  ReleaseNotesUrl: string | null;
  Assets: PublishAssetInput[];
  IdempotencyKey: string;
}

export async function publishAppUpdate(request: PublishRequest): Promise<PublishResult> {
  const [result] = await requestLaraApi(APP_UPDATES_PATH, publishResultSchema, {
    method: HttpMethodType.Post,
    body: {
      Product: request.Product,
      Channel: request.Channel,
      Version: request.Version,
      MinRequiredVersion: request.MinRequiredVersion,
      ReleaseNotesUrl: request.ReleaseNotesUrl,
      Assets: request.Assets,
    },
    headers: { "Idempotency-Key": request.IdempotencyKey },
  });

  return result;
}

export async function computeSha256Hex(bytes: ArrayBuffer): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", bytes);

  return Array.from(new Uint8Array(digest))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}
