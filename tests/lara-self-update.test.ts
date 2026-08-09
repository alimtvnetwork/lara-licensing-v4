import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiErrorCodeType } from "@/lib/lara-api-error";
import {
  ChannelType,
  PlatformType,
  downloadUpdateAsset,
  type UpdateManifest,
} from "@/lib/lara-self-update";

// Fixture bytes and their known SHA-256 (computed with Node crypto).
const ASSET_BYTES = new TextEncoder().encode("lara-asset-bytes-v1");
const ASSET_SHA = "8742595dcad837562af2d8ee3125481834a83f28a80aeb5d51772c4ec7775022";
const ASSET_SIZE = 19;
const WRONG_SHA = "0".repeat(64);

function manifest(overrides?: Partial<UpdateManifest["Assets"][number]>): UpdateManifest {
  return {
    Product: "LaraLicensing",
    Channel: ChannelType.Stable,
    LatestVersion: "1.2.3",
    MinRequiredVersion: "1.0.0",
    PublishedAt: "2026-01-01T00:00:00Z",
    Assets: [
      {
        Platform: PlatformType.WindowsAmd64,
        Url: "https://cdn.test/win.zip",
        SizeBytes: ASSET_SIZE,
        Sha256: ASSET_SHA,
        ...overrides,
      },
    ],
  };
}

function assetResponse(bytes: Uint8Array, headerSha: string, status = 200): Response {
  return new Response(bytes, {
    status,
    headers: {
      "Content-Type": "application/octet-stream",
      "Content-Length": String(bytes.byteLength),
      "X-Sha256": headerSha,
      "X-Request-Id": "asset-req-1",
    },
  });
}

beforeEach(() => {
  vi.stubEnv("VITE_LARA_API_BASE_URL", "https://lara.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
});

describe("downloadUpdateAsset integrity", () => {
  it("returns verified bytes when header, manifest, and size all match the actual SHA-256", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => assetResponse(ASSET_BYTES, ASSET_SHA)));
    const out = await downloadUpdateAsset({
      manifest: manifest(),
      platform: PlatformType.WindowsAmd64,
    });
    expect(out.sha256).toBe(ASSET_SHA);
    expect(out.sizeBytes).toBe(ASSET_SIZE);
    expect(out.requestId).toBe("asset-req-1");
    expect(out.bytes.byteLength).toBe(ASSET_SIZE);
  });

  it("aborts with UpdateAssetVerificationFailed when X-Sha256 header disagrees with the actual body hash", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => assetResponse(ASSET_BYTES, WRONG_SHA)));
    await expect(
      downloadUpdateAsset({ manifest: manifest(), platform: PlatformType.WindowsAmd64 }),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.UpdateAssetVerificationFailed,
      requestId: "asset-req-1",
    });
  });

  it("aborts with UpdateAssetVerificationFailed when manifest Sha256 disagrees with the verified body hash", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => assetResponse(ASSET_BYTES, ASSET_SHA)));
    await expect(
      downloadUpdateAsset({
        manifest: manifest({ Sha256: WRONG_SHA }),
        platform: PlatformType.WindowsAmd64,
      }),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.UpdateAssetVerificationFailed,
      requestId: "asset-req-1",
    });
  });

  it("aborts when the declared manifest SizeBytes disagrees with the downloaded byte length", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => assetResponse(ASSET_BYTES, ASSET_SHA)));
    await expect(
      downloadUpdateAsset({
        manifest: manifest({ SizeBytes: ASSET_SIZE + 1 }),
        platform: PlatformType.WindowsAmd64,
      }),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.UpdateAssetVerificationFailed });
  });

  it("aborts with UpdateAssetVerificationFailed when the X-Sha256 response header is missing", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(ASSET_BYTES, {
            status: 200,
            headers: {
              "Content-Length": String(ASSET_SIZE),
              "X-Request-Id": "asset-req-1",
            },
          }),
      ),
    );
    await expect(
      downloadUpdateAsset({ manifest: manifest(), platform: PlatformType.WindowsAmd64 }),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.UpdateAssetVerificationFailed,
      requestId: "asset-req-1",
    });
  });

  it("A3: aborts with UpdateAssetVerificationFailed when X-Sha256 is not 64 lowercase hex chars", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => assetResponse(ASSET_BYTES, "NOT-HEX-64!!")));
    await expect(
      downloadUpdateAsset({ manifest: manifest(), platform: PlatformType.WindowsAmd64 }),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.UpdateAssetVerificationFailed,
      requestId: "asset-req-1",
    });
  });

  it("A4: aborts with UpdateDownloadFailed when asset HTTP status is not 200", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => assetResponse(ASSET_BYTES, ASSET_SHA, 502)),
    );
    await expect(
      downloadUpdateAsset({ manifest: manifest(), platform: PlatformType.WindowsAmd64 }),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.UpdateDownloadFailed,
      httpStatus: 502,
      requestId: "asset-req-1",
    });
  });

  it("A5: aborts with UpdateAssetVerificationFailed when VITE_LARA_API_BASE_URL is not https", async () => {
    vi.stubEnv("VITE_LARA_API_BASE_URL", "http://insecure.test");
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    await expect(
      downloadUpdateAsset({ manifest: manifest(), platform: PlatformType.WindowsAmd64 }),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.UpdateAssetVerificationFailed });
    expect(fetchMock).not.toHaveBeenCalled();
  });



  it("aborts with UpdateAssetNotFound when the manifest has no asset for the requested platform", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    await expect(
      downloadUpdateAsset({ manifest: manifest(), platform: PlatformType.LinuxAmd64 }),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.UpdateAssetNotFound });
    expect(fetchMock).not.toHaveBeenCalled();
  });
});


