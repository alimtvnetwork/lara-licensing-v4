<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use App\Models\AppUpdateAsset;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Plan 06 step 45 (publish write path, phase 2 of 3).
 *
 * Root cause (one sentence): spec/21-app/17-self-update-endpoint.md
 * v1.3.0 §"Publish state machine" line 289-291 requires an HTTP PUT
 * that streams bytes for one platform asset and verifies `X-Sha256`
 * against the value pinned in the UploadTicket row before allowing
 * finalisation, and without this endpoint the token minted by
 * `AppUpdateController::uploadTicket` is dead on arrival.
 *
 * Contract:
 *  - Auth: bearer is the opaque `UploadToken` embedded in the path
 *    (path-scoped credential, single-use); no Sanctum/session gate.
 *  - Header `X-Sha256`: MUST equal `AppUpdateAssets.Sha256`. Mismatch
 *    -> `UpdateAssetVerificationFailed` (409) per spec §"Response".
 *  - Body: raw octet stream of exactly `SizeBytes` bytes. Size mismatch
 *    -> `UpdateAssetUploadFailed` (502) with `Rule: SizeMismatch`.
 *  - Storage: written to `<asset_storage_root>/<Product>/<Version>/<Platform>.bin`.
 *    Directory created on demand. Overwrites are safe (the asset row
 *    is unique per triple and expired tickets are reclaimed).
 *  - Ticket expiry: `UploadTicketExpiresAt < NOW()` ->
 *    `UpdateAssetUploadFailed` (502) with `Rule: TicketExpired`.
 *  - Idempotency: PUT is idempotent by definition; repeat calls with
 *    the same bytes+hash overwrite and succeed. Middleware bypasses
 *    this route because token-scoped bearer credentials rotate per
 *    ticket, so no `Idempotency-Key` header is required.
 */
final class UpdateAssetReceiverController
{
    private const HEADER_SHA256 = 'X-Sha256';
    private const TOKEN_REGEX = '/^[a-f0-9]{64}$/';
    private const LOG_RECEIVE = 'app.update_asset.receive';

    public function __invoke(Request $request, string $uploadToken): JsonResponse
    {
        $asset = $this->authenticate($uploadToken);
        $this->assertHeaderHashMatches($request, $asset);
        $bytes = $this->readBody($request, $asset);
        $this->assertBodyHashMatches($bytes, $asset);
        $path = $this->persist($bytes, $asset);
        $asset->StoragePath = $path;
        $asset->save();
        Log::info(self::LOG_RECEIVE, [
            'AppUpdateAssetId' => $asset->AppUpdateAssetId,
            'Product' => $asset->Product,
            'Version' => $asset->Version,
            'Platform' => $asset->Platform,
            'SizeBytes' => strlen($bytes),
        ]);
        $requestId = (string) ($request->attributes->get('lara.request_id') ?? '');

        return ApiEnvelope::success([[
            'Received' => true,
            'Sha256' => $asset->Sha256,
            'SizeBytes' => (int) $asset->SizeBytes,
        ]], $requestId, 200, 'OK');
    }

    private function authenticate(string $token): AppUpdateAsset
    {
        if (preg_match(self::TOKEN_REGEX, $token) !== 1) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Upload token format is invalid.',
                [['Field' => 'UploadToken', 'Rule' => 'FormatInvalid']],
            )
        }
        $asset = AppUpdateAsset::query()->where('UploadToken', $token)->first();
        if (($asset instanceof AppUpdateAsset) === false) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Upload token is not recognised.',
                [['Field' => 'UploadToken', 'Rule' => 'Unknown']],
            )
        }
        if ((int) $asset->IsFinalized === 1) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Upload token has already been consumed by a finalised asset.',
                [['Field' => 'UploadToken', 'Rule' => 'AlreadyFinalized']],
            )
        }
        $expiresAt = $asset->UploadTicketExpiresAt;
        if ($expiresAt instanceof Carbon && $expiresAt->isPast()) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Upload token has expired; request a new ticket.',
                [['Field' => 'UploadToken', 'Rule' => 'TicketExpired']],
            )
        }

        return $asset;
    }

    private function assertHeaderHashMatches(Request $request, AppUpdateAsset $asset): void
    {
        $header = strtolower(trim((string) $request->headers->get(self::HEADER_SHA256, '')));
        if ($header === '' || $header !== $asset->Sha256) {
            throw DomainConflictException::custom('UpdateAssetVerificationFailed',
                'X-Sha256 header does not match the ticket-declared Sha256.',
                [['Field' => 'X-Sha256', 'Rule' => 'HashMismatch']],
            )
        }
    }

    private function readBody(Request $request, AppUpdateAsset $asset): string
    {
        $bytes = (string) $request->getContent();
        if (strlen($bytes) !== (int) $asset->SizeBytes) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Body byte length does not match the ticket-declared SizeBytes.',
                [['Field' => 'Body', 'Rule' => 'SizeMismatch']],
            )
        }

        return $bytes;
    }

    private function assertBodyHashMatches(string $bytes, AppUpdateAsset $asset): void
    {
        $computed = hash('sha256', $bytes);
        if ($computed !== $asset->Sha256) {
            throw DomainConflictException::custom('UpdateAssetVerificationFailed',
                'Computed body SHA-256 does not match the ticket-declared hash.',
                [['Field' => 'Body', 'Rule' => 'ComputedHashMismatch']],
            )
        }
    }

    private function persist(string $bytes, AppUpdateAsset $asset): string
    {
        $root = (string) config('lara.self_update.asset_storage_root');
        $dir = sprintf('%s/%s/%s', rtrim($root, '/'), (string) $asset->Product, (string) $asset->Version);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Storage directory could not be created.',
                [['Field' => 'Storage', 'Rule' => 'MkdirFailed']],
            )
        }
        $path = sprintf('%s/%s.bin', $dir, (string) $asset->Platform);
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Storage write failed.',
                [['Field' => 'Storage', 'Rule' => 'WriteFailed']],
            )
        }

        return $path;
    }
}
