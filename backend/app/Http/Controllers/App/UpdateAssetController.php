<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Models\AppUpdateAsset;
use App\Services\SelfUpdateService;
use App\Support\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plan 06 step 45 (publish write path, phase 3b of 3).
 *
 * Root cause this class closes (one sentence): spec/21-app/17-self-update-endpoint.md
 * v1.3.0 §"HEAD /App/UpdateAsset/{Version}/{Platform}" and the paired
 * `GET` variant define the byte-serving endpoint the CLI streams from
 * after the manifest read, and without it the manifest points at a
 * dead URL and the self-update flow cannot complete.
 *
 * Contract:
 *  - Auth: anonymous for Stable channel (v1.0 pin) per spec §"Auth policy summary".
 *  - Path: `{Version}` (semver) + `{Platform}` (closed enum).
 *  - Query: `Product` (defaults to `lara-cli` per config).
 *  - Filter: only finalised (`IsFinalized=1`) and non-yanked (`IsYanked=0`)
 *    rows are visible per spec §"Publish state machine" §"Rule".
 *  - Headers: `Content-Length`, `ETag: "<Sha256>"`, `X-Sha256: <Sha256>`,
 *    `Cache-Control: public, max-age=<cfg>` per spec §"HEAD/GET" table row.
 *  - HEAD returns identical headers with an empty body.
 *  - Missing / yanked / non-finalised row: `UpdateAssetNotFound` (404).
 */
final class UpdateAssetController
{
    private const HEADER_SHA256 = 'X-Sha256';
    private const DEFAULT_PRODUCT = 'lara-cli';
    private const LOG_DOWNLOAD = 'app.update_asset.download';

    public function __construct(private readonly SelfUpdateService $service)
    {
    }

    public function __invoke(Request $request, string $version, string $platform): Response|BinaryFileResponse|StreamedResponse
    {
        $product = trim((string) $request->query('Product', self::DEFAULT_PRODUCT));
        $this->assertClosedPlatform($platform);
        $asset = $this->resolveAsset($product, $version, $platform);
        $storagePath = (string) $asset->StoragePath;
        if ($storagePath === '' || !is_file($storagePath)) {
            throw NotFoundException::notFound('UpdateAssetNotFound',
                'Asset row is finalised but the underlying binary is missing on disk.',
                [['Field' => 'StoragePath', 'Rule' => 'FileMissing']],
            );
        }
        $headers = $this->headersFor($asset);
        if (strtoupper($request->getMethod()) === 'HEAD') {
            return new Response('', 200, $headers);
        }
        AuditWriter::write($request, 'UpdateDownloaded', 'AppUpdateAssets', (int) $asset->AppUpdateAssetId, [
            'Product' => $asset->Product,
            'Version' => $asset->Version,
            'Platform' => $asset->Platform,
        ]);

        return response()->file($storagePath, $headers);
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

    private function resolveAsset(string $product, string $version, string $platform): AppUpdateAsset
    {
        $asset = AppUpdateAsset::query()
            ->join('AppUpdates', 'AppUpdates.AppUpdateId', '=', 'AppUpdateAssets.AppUpdateId')
            ->where('AppUpdateAssets.Product', $product)
            ->where('AppUpdateAssets.Version', $version)
            ->where('AppUpdateAssets.Platform', $platform)
            ->where('AppUpdateAssets.IsFinalized', 1)
            ->where('AppUpdates.IsYanked', 0)
            ->select('AppUpdateAssets.*')
            ->first();
        if (($asset instanceof AppUpdateAsset) === false) {
            throw NotFoundException::notFound('UpdateAssetNotFound',
                'No finalised, non-yanked asset matches the requested (Product, Version, Platform).',
                [['Field' => 'Asset', 'Rule' => 'NotFound']],
            );
        }

        return $asset;
    }

    /** @return array<string,string> */
    private function headersFor(AppUpdateAsset $asset): array
    {
        $maxAge = (int) config('lara.self_update.manifest_cache_max_age_seconds', 30);

        return [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) (int) $asset->SizeBytes,
            'ETag' => sprintf('"%s"', (string) $asset->Sha256),
            self::HEADER_SHA256 => (string) $asset->Sha256,
            'Cache-Control' => sprintf('public, max-age=%d', $maxAge),
        ];
    }
}
