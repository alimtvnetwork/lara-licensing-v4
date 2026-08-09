import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import { ApiErrorCodeType, LaraApiError } from "./lara-api-error";

/**
 * Self-update client per spec/21-app/17-self-update-endpoint.md.
 *
 * Contract highlights:
 * - Manifest is a JSON envelope (uses requestLaraApi).
 * - Asset GET returns raw bytes; caller MUST verify SHA-256 against `X-Sha256`
 *   response header and `Content-Length` against manifest `SizeBytes`.
 *   Any mismatch aborts the deploy: this module throws instead of returning.
 * - Never write the downloaded bytes to a durable location before verification.
 */

export enum PlatformType {
  WindowsAmd64 = "WindowsAmd64",
  LinuxAmd64 = "LinuxAmd64",
  DarwinArm64 = "DarwinArm64",
}

export enum ChannelType {
  Stable = "Stable",
  Beta = "Beta",
}

const SHA256_HEADER = "X-Sha256";
const REQUEST_ID_HEADER = "X-Request-Id";
const semverPattern = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/;
const sha256HexPattern = /^[0-9a-f]{64}$/;

export const manifestAssetSchema = z.object({
  Platform: z.nativeEnum(PlatformType),
  Url: z.string().min(1),
  SizeBytes: z.number().int().positive(),
  Sha256: z.string().regex(/^[0-9a-f]{64}$/),
  SignatureUrl: z.string().min(1).optional(),
});

export type ManifestAsset = z.infer<typeof manifestAssetSchema>;

export const updateManifestSchema = z.object({
  Product: z.string().min(1),
  Channel: z.nativeEnum(ChannelType),
  LatestVersion: z.string().regex(semverPattern),
  MinRequiredVersion: z.string().regex(semverPattern),
  PublishedAt: z.string().datetime(),
  Assets: z.array(manifestAssetSchema).min(1),
  ReleaseNotesUrl: z.string().url().optional(),
});

export type UpdateManifest = z.infer<typeof updateManifestSchema>;

export interface FetchUpdateManifestInput {
  product: string;
  channel: ChannelType;
  currentVersion: string;
  platform: PlatformType;
  signal?: AbortSignal;
}

function manifestQueryString(input: FetchUpdateManifestInput): string {
  const params = new URLSearchParams({
    Product: input.product,
    Channel: input.channel,
    CurrentVersion: input.currentVersion,
    Platform: input.platform,
  });

  return params.toString();
}

export async function fetchUpdateManifest(
  input: FetchUpdateManifestInput,
): Promise<UpdateManifest> {
  const path = `/App/UpdateManifest?${manifestQueryString(input)}`;
  const [manifest] = await requestLaraApi(path, updateManifestSchema, {
    method: HttpMethodType.Get,
    signal: input.signal,
  });

  return manifest;
}

export function updateManifestQueryOptions(input: FetchUpdateManifestInput) {
  return queryOptions({
    queryKey: [
      "lara",
      "update-manifest",
      input.product,
      input.channel,
      input.currentVersion,
      input.platform,
    ],
    queryFn: ({ signal }) => fetchUpdateManifest({ ...input, signal }),
    staleTime: 30_000,
  });
}

export function selectAssetForPlatform(
  manifest: UpdateManifest,
  platform: PlatformType,
): ManifestAsset {
  const asset = manifest.Assets.find((entry) => entry.Platform === platform);
  if (asset === undefined) {
    throw new LaraApiError(
      `Manifest ${manifest.LatestVersion} has no asset for ${platform}.`,
      ApiErrorCodeType.UpdateAssetNotFound,
      404,
    );
  }

  return asset;
}

export interface AssetProbe {
  sizeBytes: number;
  sha256: string;
  etag: string | undefined;
  requestId: string | undefined;
}

function requireHeader(response: Response, name: string): string {
  const value = response.headers.get(name);
  if (typeof value !== "string" || value.length === 0) {
    throw new LaraApiError(
      `Response is missing required header ${name}.`,
      ApiErrorCodeType.UpdateAssetVerificationFailed,
      response.status,
      response.headers.get(REQUEST_ID_HEADER) ?? undefined,
    );
  }

  return value;
}

/**
 * MUST-abort row A3 (spec/21-app/17-self-update-endpoint.md line 142): the
 * `X-Sha256` header MUST be exactly 64 lowercase hex chars. Anything else
 * fires abort with Reason="ShaHeaderMissing".
 */
function requireShaHeader(response: Response): string {
  const raw = requireHeader(response, SHA256_HEADER);
  const value = raw.toLowerCase();
  if (sha256HexPattern.test(value) === false) {
    throw new LaraApiError(
      `${SHA256_HEADER} header is not 64 lowercase hex chars (Reason=ShaHeaderMissing).`,
      ApiErrorCodeType.UpdateAssetVerificationFailed,
      response.status,
      response.headers.get(REQUEST_ID_HEADER) ?? undefined,
    );
  }

  return value;
}

/**
 * MUST-abort row A5 (spec line 144): plain http:// at any hop is a
 * non-recoverable abort. Any absolute URL used to fetch an asset MUST
 * be https://. Relative or malformed URLs are rejected the same way.
 */
function assertTlsUrl(url: string): void {
  if (!/^https:\/\//i.test(url)) {
    throw new LaraApiError(
      `Update asset URL is not TLS (Reason=InsecureTransport): ${url}`,
      ApiErrorCodeType.UpdateAssetVerificationFailed,
      0,
    );
  }
}

async function sendAssetRequest(
  method: HttpMethodType,
  version: string,
  platform: PlatformType,
  signal?: AbortSignal,
): Promise<Response> {
  const baseUrl = import.meta.env.VITE_LARA_API_BASE_URL;
  if (typeof baseUrl !== "string" || baseUrl.length === 0) {
    throw new LaraApiError(
      "VITE_LARA_API_BASE_URL is not configured.",
      ApiErrorCodeType.ServerError,
      0,
    );
  }
  const url = `${baseUrl.replace(/\/$/, "")}/App/UpdateAsset/${version}/${platform}`;
  assertTlsUrl(url);

  // eslint-disable-next-line no-restricted-globals -- binary asset download (`/App/UpdateAsset/*`) returns raw bytes with SHA/Content-Length headers, not a `{Status,Attributes,Results}` envelope; `assertAssetStatusOk` enforces the failure contract.
  return fetch(url, { method, signal });
}

/**
 * MUST-abort row A4 (spec line 143): asset HTTP status != 200 after
 * redirects. Throws `UpdateDownloadFailed` carrying HttpStatus + requestId.
 */
function assertAssetStatusOk(response: Response): void {
  if (response.status === 200) return;
  throw new LaraApiError(
    `Update asset download failed with HTTP ${response.status}.`,
    ApiErrorCodeType.UpdateDownloadFailed,
    response.status,
    response.headers.get(REQUEST_ID_HEADER) ?? undefined,
  );
}

export async function probeUpdateAsset(
  version: string,
  platform: PlatformType,
  signal?: AbortSignal,
): Promise<AssetProbe> {
  const response = await sendAssetRequest(HttpMethodType.Get, version, platform, signal);
  assertAssetStatusOk(response);
  // Use HEAD-equivalent via method; some servers do not support HEAD, so caller
  // may prefer full download. For a lightweight probe, callers can invoke
  // fetch directly with { method: 'HEAD' }; we expose GET for correctness.
  const length = Number(requireHeader(response, "Content-Length"));
  const sha256 = requireShaHeader(response);
  await response.body?.cancel();

  return {
    sizeBytes: length,
    sha256,
    etag: response.headers.get("ETag") ?? undefined,
    requestId: response.headers.get(REQUEST_ID_HEADER) ?? undefined,
  };
}

async function digestSha256(bytes: Uint8Array): Promise<string> {
  const copy = new Uint8Array(bytes.byteLength);
  copy.set(bytes);
  const buffer = await crypto.subtle.digest("SHA-256", copy.buffer);
  const view = new Uint8Array(buffer);
  let hex = "";
  for (const byte of view) hex += byte.toString(16).padStart(2, "0");

  return hex;
}

async function readAssetBody(response: Response): Promise<Uint8Array> {
  const buffer = await response.arrayBuffer();

  return new Uint8Array(buffer);
}

function verifyAssetIntegrity(params: {
  headerSha256: string;
  actualSha256: string;
  contentLength: number;
  expectedSize: number;
  requestId: string | undefined;
  status: number;
}): void {
  const headerMatch = params.headerSha256.toLowerCase() === params.actualSha256.toLowerCase();
  const sizeMatch = params.contentLength === params.expectedSize;
  if (headerMatch && sizeMatch) return;
  throw new LaraApiError(
    `Asset integrity check failed (expectedSha=${params.headerSha256}, actualSha=${params.actualSha256}, expectedSize=${params.expectedSize}, actualSize=${params.contentLength}).`,
    ApiErrorCodeType.UpdateAssetVerificationFailed,
    params.status,
    params.requestId,
  );
}

export interface DownloadedAsset {
  bytes: Uint8Array;
  sha256: string;
  sizeBytes: number;
  requestId: string | undefined;
}

export interface DownloadUpdateAssetInput {
  manifest: UpdateManifest;
  platform: PlatformType;
  signal?: AbortSignal;
}

/**
 * Downloads and verifies a single asset. Throws `LaraApiError` with
 * `UpdateAssetVerificationFailed` on any integrity mismatch: the caller MUST
 * NOT persist the bytes when this throws (spec 17 §Client-side verification).
 */
export async function downloadUpdateAsset(
  input: DownloadUpdateAssetInput,
): Promise<DownloadedAsset> {
  const asset = selectAssetForPlatform(input.manifest, input.platform);
  const response = await sendAssetRequest(
    HttpMethodType.Get,
    input.manifest.LatestVersion,
    input.platform,
    input.signal,
  );
  assertAssetStatusOk(response);
  const headerSha = requireShaHeader(response);
  const requestId = response.headers.get(REQUEST_ID_HEADER) ?? undefined;
  const bytes = await readAssetBody(response);
  const actualSha = await digestSha256(bytes);
  verifyAssetIntegrity({
    headerSha256: headerSha,
    actualSha256: actualSha,
    contentLength: bytes.byteLength,
    expectedSize: asset.SizeBytes,
    requestId,
    status: response.status,
  });
  if (actualSha.toLowerCase() !== asset.Sha256.toLowerCase()) {
    throw new LaraApiError(
      `Manifest sha256 (${asset.Sha256}) does not match downloaded asset (${actualSha}).`,
      ApiErrorCodeType.UpdateAssetVerificationFailed,
      response.status,
      requestId,
    );
  }

  return { bytes, sha256: actualSha, sizeBytes: bytes.byteLength, requestId };
}
