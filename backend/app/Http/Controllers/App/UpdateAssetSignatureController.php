<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;


use App\Exceptions\LaraException;
use App\Models\AppUpdateAsset;
use App\Support\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Plan 06 step 46. `GET|HEAD /App/UpdateAsset/{Version}/{Platform}.sig`.
 *
 * Root cause this class closes (one sentence): the manifest response
 * emits `SignatureUrl: /App/UpdateAsset/{Version}/{Platform}.sig` per
 * spec/21-app/17-self-update-endpoint.md v1.3.0 §"Response 200", and
 * the CLI abort row A8 requires that URL to serve the raw 64-byte
 * Ed25519 detached signature so the client can verify against its
 * pinned publisher key before rename-first-deploy; until now the route
 * did not exist and every signature-required client would abort.
 *
 * Behaviour:
 *  - Only finalised, non-yanked rows with a persisted
 *    `SignatureStoragePath` are visible. Missing signature blob returns
 *    `UpdateSignatureUnavailable` (404) so a checksum-only client can
 *    downgrade cleanly while a signature-required client hits A8.
 *  - `X-Sha256` header carries `SignatureSha256` (over the signature
 *    bytes) so a transport-level checksum is available. `ETag` binds to
 *    the same value. Body is exactly 64 bytes on GET, empty on HEAD.
 */
final class UpdateAssetSignatureController
{
    private const HEADER_SHA256 = 'X-Sha256';
    private const DEFAULT_PRODUCT = 'lara-cli';

    public function __invoke(Request $request, string $version, string $platform): Response|BinaryFileResponse
    {
        $product = trim((string) $request->query('Product', self::DEFAULT_PRODUCT));
        $this->assertClosedPlatform($platform);
        $asset = $this->resolveSignedAsset($product, $version, $platform);
        $sigPath = (string) $asset->SignatureStoragePath;
        if ($sigPath === '' || !is_file($sigPath)) {
            throw NotFoundException::custom('UpdateSignatureUnavailable',
                'Signature blob is missing on disk for this finalised asset.',
                [['Field' => 'SignatureStoragePath', 'Rule' => 'FileMissing']],
            )
        }
        $size = (int) filesize($sigPath);
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
            'ETag' => sprintf('"%s"', (string) $asset->SignatureSha256),
            self::HEADER_SHA256 => (string) $asset->SignatureSha256,
            'Cache-Control' => sprintf('public, max-age=%d', (int) config('lara.self_update.manifest_cache_max_age_seconds', 30)),
        ];
        if (strtoupper($request->getMethod()) === 'HEAD') {
            return new Response('', 200, $headers);
        }
        AuditWriter::write($request, 'UpdateSignatureDownloaded', 'AppUpdateAssets', (int) $asset->AppUpdateAssetId, [
            'Product' => $asset->Product,
            'Version' => $asset->Version,
            'Platform' => $asset->Platform,
        ]);

        return response()->file($sigPath, $headers);
    }

    private function assertClosedPlatform(string $platform): void
    {
        $platforms = (array) config('lara.self_update.platforms', []);
        if (in_array($platform, $platforms, true) === false) {
            throw ValidationException::custom('ValidationInputInvalid',
                'Platform is not in the closed enum.',
                [['Field' => 'Platform', 'Rule' => 'PlatformEnum']],
            )
        }
    }

    private function resolveSignedAsset(string $product, string $version, string $platform): AppUpdateAsset
    {
        $asset = AppUpdateAsset::query()
            ->join('AppUpdates', 'AppUpdates.AppUpdateId', '=', 'AppUpdateAssets.AppUpdateId')
            ->where('AppUpdateAssets.Product', $product)
            ->where('AppUpdateAssets.Version', $version)
            ->where('AppUpdateAssets.Platform', $platform)
            ->where('AppUpdateAssets.IsFinalized', 1)
            ->where('AppUpdates.IsYanked', 0)
            ->whereNotNull('AppUpdateAssets.SignatureStoragePath')
            ->select('AppUpdateAssets.*')
            ->first();
        if (($asset instanceof AppUpdateAsset) === false) {
            throw NotFoundException::custom('UpdateSignatureUnavailable',
                'No signed asset row matches the requested (Product, Version, Platform).',
                [['Field' => 'Signature', 'Rule' => 'NotFound']],
            )
        }

        return $asset;
    }
}
