<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Admin\AppUpdatePublishRequest;
use App\Http\Requests\Admin\AppUpdateUploadTicketRequest;
use App\Http\Requests\Admin\AppUpdateYankRequest;
use App\Models\AppUpdate;
use App\Models\AppUpdateAsset;
use App\Support\ApiEnvelope;
use App\Support\AssetSigner;
use App\Support\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;



/**
 * Plan 06 step 45 (publish write path, phase 1 of 3).
 *
 * Root cause this controller closes (one sentence): spec/21-app/
 * 17-self-update-endpoint.md v1.3.0 §"Publish state machine" line 284
 * requires `POST /Admin/AppUpdates/UploadTicket` to be the FIRST call
 * of every publish saga (it INSERTs the orphan `AppUpdateAssets` row
 * with `IsFinalized = 0` and mints the opaque `UploadToken` that steps
 * 2 and 3 consume), and until now that endpoint did not exist, so no
 * admin could publish any binary and the manifest read path shipped
 * in v0.236.0 had nothing to return.
 *
 * Contract:
 *  - Auth: `require.role:Admin|SuperAdmin` (spec §"Auth policy summary").
 *  - Body: {Product, Version, Platform, SizeBytes, Sha256} (spec §"Request").
 *  - Version monotonicity: reject `Version <= max(LatestVersion)` for
 *    `(Product, Channel=Stable)` with `UpdateVersionDowngradeBlocked`
 *    (spec §"Admin invariants" §5). Channel is pinned to Stable in v1.0
 *    (spec §"v1.0 rollout policy") so the check uses that literal.
 *  - Actor stamping: never trust `PublishedByUserId` from the client
 *    (spec §"Admin invariants" §3); asset row carries no
 *    `PublishedByUserId` (only `AppUpdates` does), so we stamp actor
 *    via audit only. Actor is `auth()->id()`.
 *  - Idempotency: honoured by `IdempotencyKeyMiddleware` on the
 *    `api/admin/appupdates` prefix per spec §"Admin invariants" §4.
 *  - Response 201: {UploadToken, UploadUrl, ExpiresAt} per spec §"Response".
 *
 * Storage receiver URL: `PUT /Api/App/UpdateAssetReceiver/{UploadToken}`
 * — hosted in-app rather than a signed cloud URL for v1.0. The token
 * itself is the bearer credential (64 hex chars, single-use, TTL-scoped).
 */
final class AppUpdateController
{
    private const TICKET_TTL_MINUTES = 60;
    private const CHANNEL_V1 = 'Stable';
    private const LOG_TICKET = 'admin.app_updates.upload_ticket';
    private const LIST_LIMIT = 100;

    /**
     * GET /Api/Admin/AppUpdates
     *
     * Lists published manifest rows (newest first) with per-platform asset
     * summaries so the Admin console can render a self-update dashboard.
     * Includes yanked rows (with IsYanked=1) so operators can audit the
     * full history; the public manifest read path already excludes them.
     */
        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="AppUpdateController index",
     *     tags={"AppUpdateController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(Request $request): JsonResponse
    {
        $product = trim((string) $request->query('Product', 'lara-cli'));
        $updates = AppUpdate::query()
            ->where('Product', $product)
            ->where('Channel', self::CHANNEL_V1)
            ->orderByDesc('PublishedAt')
            ->limit(self::LIST_LIMIT)
            ->get();
        $ids = $updates->pluck('AppUpdateId')->all();
        $assetsByUpdate = AppUpdateAsset::query()
            ->whereIn('AppUpdateId', $ids)
            ->where('IsFinalized', 1)
            ->get()
            ->groupBy('AppUpdateId');
        $results = $updates->map(function (AppUpdate $u) use ($assetsByUpdate): array {
            $assets = ($assetsByUpdate[$u->AppUpdateId] ?? collect())
                ->map(fn (AppUpdateAsset $a): array => [
                    'Platform' => (string) $a->Platform,
                    'SizeBytes' => (int) $a->SizeBytes,
                    'Sha256' => (string) $a->Sha256,
                    'HasSignature' => (string) ($a->SignatureStoragePath ?? '') !== '',
                ])->values()->all();

            return (new \App\Http\Resources\AppUpdateResource($u))
                ->additional(['Assets' => $assets])
                ->resolve();
        })->all();
        $requestId = (string) ($request->attributes->get('lara.request_id') ?? '');

        return ApiEnvelope::success($results, $requestId);
    }



        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="AppUpdateController uploadTicket",
     *     tags={"AppUpdateController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function uploadTicket(AppUpdateUploadTicketRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $this->assertClosedSets($payload);
        $this->assertVersionMonotonic($payload['Product'], $payload['Version']);
        $asset = $this->reserveAsset($payload);
        AuditWriter::write($request, 'UpdateAssetTicketReserved', 'AppUpdateAssets', (int) $asset->AppUpdateAssetId, [
            'Product' => $payload['Product'],
            'Version' => $payload['Version'],
            'Platform' => $payload['Platform'],
        ]);

        return $this->respond($request, $asset);
    }

    /** @param array{Product:string,Version:string,Platform:string,SizeBytes:int,Sha256:string} $p */
    private function assertClosedSets(array $p): void
    {
        $this->assertMember('Product', $p['Product'], (array) config('lara.self_update.products', []));
        $this->assertMember('Platform', $p['Platform'], (array) config('lara.self_update.platforms', []));
    }

    /** @param array<int,string> $set */
    private function assertMember(string $field, string $value, array $set): void
    {
        if (in_array($value, $set, true) === false) {
            throw ValidationException::validationFailed(
                sprintf('%s is not in the closed enum.', $field),
                [['Field' => $field, 'Rule' => 'ClosedSet']],
            );
        }
    }

    private function assertVersionMonotonic(string $product, string $version): void
    {
        $rows = AppUpdate::query()
            ->where('Product', $product)
            ->where('Channel', self::CHANNEL_V1)
            ->orderByDesc('PublishedAt')
            ->limit(50)
            ->pluck('Version')
            ->all();
        foreach ($rows as $existing) {
            if (version_compare((string) $existing, $version, '>=')) {
                throw DomainConflictException::custom('UpdateVersionDowngradeBlocked',
                    'Version must be strictly greater than every published version for (Product, Channel).',
                    [['Field' => 'Version', 'Rule' => 'Monotonic']],
                )
            }
        }
    }

    /** @param array{Product:string,Version:string,Platform:string,SizeBytes:int,Sha256:string} $p */
    private function reserveAsset(array $p): AppUpdateAsset
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = Carbon::now('UTC')->addMinutes(self::TICKET_TTL_MINUTES);

        return DB::connection('root')->transaction(function () use ($p, $token, $expiresAt): AppUpdateAsset {
            $this->deleteExpiredTicket($p);

            return AppUpdateAsset::create([
                'AppUpdateId' => null,
                'Product' => $p['Product'],
                'Version' => $p['Version'],
                'Platform' => $p['Platform'],
                'SizeBytes' => $p['SizeBytes'],
                'Sha256' => $p['Sha256'],
                'IsFinalized' => 0,
                'UploadToken' => $token,
                'UploadTicketExpiresAt' => $expiresAt,
            ]);
        });
    }

    /** @param array{Product:string,Version:string,Platform:string,SizeBytes:int,Sha256:string} $p */
    private function deleteExpiredTicket(array $p): void
    {
        // Spec §"Upload ticket expiry": expired orphan tickets are
        // reclaimed so a retry succeeds without a manual sweep.
        AppUpdateAsset::query()
            ->where('Product', $p['Product'])
            ->where('Version', $p['Version'])
            ->where('Platform', $p['Platform'])
            ->where('IsFinalized', 0)
            ->where('UploadTicketExpiresAt', '<', Carbon::now('UTC'))
            ->delete();
    }

    private function respond(Request $request, AppUpdateAsset $asset): JsonResponse
    {
        $result = [
            'UploadToken' => (string) $asset->UploadToken,
            'UploadUrl' => url(sprintf('/api/App/UpdateAssetReceiver/%s', $asset->UploadToken)),
            'ExpiresAt' => $asset->UploadTicketExpiresAt?->format('Y-m-d\TH:i:s\Z'),
        ];
        $requestId = (string) ($request->attributes->get('lara.request_id') ?? '');

        return ApiEnvelope::success([$result], $requestId, 201, 'Created');
    }

    /*
     |----------------------------------------------------------------
     | Phase 3a: `POST /Admin/AppUpdates` (finalize).
     |
     | Spec 17 v1.3.0 §"Publish state machine" line 293-294 requires a
     | single transaction that INSERTs the `AppUpdates` row and flips
     | every referenced `AppUpdateAssets` row from `IsFinalized=0` to
     | `IsFinalized=1` while asserting `Sha256 = <supplied>`. Any
     | mismatch aborts the whole transaction so no half-published
     | manifest ever exists (AC-SU-DB-002).
     |
     | Invariants enforced here:
     |  §5 version monotonicity (409 UpdateVersionDowngradeBlocked)
     |  §6 platform coverage (400 ValidationInputInvalid `PlatformCoverage`)
     |  §3 actor stamping via `auth()->id()`
     |  §9 audit inside the same DB transaction (AuditWriter runs
     |    before COMMIT; any failure past that point rolls back).
     */
        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="AppUpdateController publish",
     *     tags={"AppUpdateController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function publish(AppUpdatePublishRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $this->assertPublishClosedSets($payload);
        $this->assertVersionMonotonic($payload['Product'], $payload['Version']);
        $this->assertPlatformCoverage($payload['Assets']);
        $actorId = (int) $request->user()->getAuthIdentifier();
        $row = DB::connection('root')->transaction(function () use ($payload, $actorId, $request): AppUpdate {
            $update = $this->insertUpdate($payload, $actorId);
            $this->finalizeAssets($update, $payload['Assets']);
            AuditWriter::write($request, 'UpdatePublished', 'AppUpdates', (int) $update->AppUpdateId, [
                'Product' => $update->Product,
                'Channel' => $update->Channel,
                'Version' => $update->Version,
                'Platforms' => array_column($payload['Assets'], 'Platform'),
            ]);

            return $update;
        });
        $requestId = (string) ($request->attributes->get('lara.request_id') ?? '');

        return ApiEnvelope::success([$this->presentUpdate($row, $payload['Assets'])], $requestId, 201, 'Created');
    }

    /** @param array{Product:string,Channel:string,Version:string,MinRequiredVersion:string,ReleaseNotesUrl:?string,Assets:array<int,array{Platform:string,Sha256:string,SizeBytes:int,UploadToken:string}>} $p */
    private function assertPublishClosedSets(array $p): void
    {
        $this->assertMember('Product', $p['Product'], (array) config('lara.self_update.products', []));
        $this->assertMember('Channel', $p['Channel'], (array) config('lara.self_update.channels', []));
        if ($p['Channel'] !== self::CHANNEL_V1) {
            throw InternalException::custom('UpdateChannelForbidden',
                'Only the Stable channel is publishable in v1.0.',
                [['Field' => 'Channel', 'Rule' => 'V1StableOnly']],
            )
        }
        foreach ($p['Assets'] as $i => $a) {
            $this->assertMember(sprintf('Assets[%d].Platform', $i), $a['Platform'], (array) config('lara.self_update.platforms', []));
        }
    }

    /** @param array<int,array{Platform:string,Sha256:string,SizeBytes:int,UploadToken:string}> $assets */
    private function assertPlatformCoverage(array $assets): void
    {
        $required = (array) config('lara.self_update.platforms', []);
        $supplied = array_values(array_unique(array_column($assets, 'Platform')));
        sort($required);
        sort($supplied);
        if ($required !== $supplied) {
            throw ValidationException::custom('ValidationInputInvalid',
                'Assets[] must cover every declared platform exactly once.',
                [['Field' => 'Assets', 'Rule' => 'PlatformCoverage']],
            )
        }
    }

    /** @param array{Product:string,Channel:string,Version:string,MinRequiredVersion:string,ReleaseNotesUrl:?string,Assets:array<int,array{Platform:string,Sha256:string,SizeBytes:int,UploadToken:string}>} $p */
    private function insertUpdate(array $p, int $actorId): AppUpdate
    {
        return AppUpdate::create([
            'Product' => $p['Product'],
            'Channel' => $p['Channel'],
            'Version' => $p['Version'],
            'MinRequiredVersion' => $p['MinRequiredVersion'],
            'ReleaseNotesUrl' => $p['ReleaseNotesUrl'],
            'PublishedAt' => Carbon::now('UTC'),
            'PublishedByUserId' => $actorId,
            'IsYanked' => 0,
        ]);
    }

    /** @param array<int,array{Platform:string,Sha256:string,SizeBytes:int,UploadToken:string}> $assets */
    private function finalizeAssets(AppUpdate $update, array $assets): void
    {
        $signer = app(AssetSigner::class);
        $signingEnabled = $signer->isConfigured();
        foreach ($assets as $a) {
            $row = AppUpdateAsset::query()
                ->where('UploadToken', $a['UploadToken'])
                ->where('Product', $update->Product)
                ->where('Version', $update->Version)
                ->where('Platform', $a['Platform'])
                ->where('IsFinalized', 0)
                ->lockForUpdate()
                ->first();
            $this->assertTicketFinalizable($row, $a);
            if ($signingEnabled) {
                // Plan 06 step 46. Ed25519 detached signature is produced
                // INSIDE the publish transaction so a signature-required
                // client never observes a finalised asset without a
                // paired .sig blob (spec §"Signature verification").
                $signed = $signer->signFile((string) $row->StoragePath);
                $row->SignatureStoragePath = $signed['SignatureStoragePath'];
                $row->SignatureSha256 = $signed['SignatureSha256'];
            }
            $row->AppUpdateId = $update->AppUpdateId;
            $row->IsFinalized = 1;
            $row->FinalizedAt = Carbon::now('UTC');
            $row->UploadToken = null;
            $row->UploadTicketExpiresAt = null;
            $row->save();
        }
    }


    /** @param array{Platform:string,Sha256:string,SizeBytes:int,UploadToken:string} $a */
    private function assertTicketFinalizable(?AppUpdateAsset $row, array $a): void
    {
        if (($row instanceof AppUpdateAsset) === false) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Upload ticket is unknown, expired, or already finalised.',
                [['Field' => 'UploadToken', 'Rule' => 'TicketNotOpen']],
            )
        }
        if (strtolower((string) $row->Sha256) !== $a['Sha256'] || (int) $row->SizeBytes !== $a['SizeBytes']) {
            throw DomainConflictException::custom('UpdateAssetVerificationFailed',
                'Supplied Sha256/SizeBytes does not match the ticket-declared values.',
                [['Field' => sprintf('Assets[Platform=%s]', $a['Platform']), 'Rule' => 'TicketMismatch']],
            )
        }
        if ((string) $row->StoragePath === '' || !is_file((string) $row->StoragePath)) {
            throw InternalException::custom('UpdateAssetUploadFailed',
                'Ticket bytes were not received before publish.',
                [['Field' => sprintf('Assets[Platform=%s]', $a['Platform']), 'Rule' => 'BytesMissing']],
            )
        }
    }

    /** @param array<int,array{Platform:string,Sha256:string,SizeBytes:int,UploadToken:string}> $assets */
    private function presentUpdate(AppUpdate $u, array $assets): array
    {
        $out = [];
        $signingEnabled = app(AssetSigner::class)->isConfigured();
        foreach ($assets as $a) {
            $out[] = [
                'Platform' => $a['Platform'],
                'Url' => sprintf('/App/UpdateAsset/%s/%s', $u->Version, $a['Platform']),
                'SizeBytes' => $a['SizeBytes'],
                'Sha256' => $a['Sha256'],
                'SignatureUrl' => $signingEnabled
                    ? sprintf('/App/UpdateAsset/%s/%s.sig', $u->Version, $a['Platform'])
                    : null,
            ];
        }

        return [
            'Product' => $u->Product,
            'Channel' => $u->Channel,
            'LatestVersion' => $u->Version,
            'MinRequiredVersion' => $u->MinRequiredVersion,
            'PublishedAt' => $u->PublishedAt?->format('Y-m-d\TH:i:s\Z'),
            'Assets' => $out,
            'ReleaseNotesUrl' => $u->ReleaseNotesUrl,
        ];
    }

    /*
     |----------------------------------------------------------------
     | Phase 3c: `POST /Admin/AppUpdates/{Version}/Yank`.
     |
     | Spec 17 v1.3.0 §"Yank flow" line 303 + §"Admin invariants" §10:
     | flip `IsYanked=1`, stamp `YankedAt`/`YankedByUserId`, reject when
     | already yanked (409 ValidationConflict), no un-yank endpoint.
     */
        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="AppUpdateController yank",
     *     tags={"AppUpdateController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function yank(AppUpdateYankRequest $request, string $version): JsonResponse
    {
        $product = $request->product();
        $version = $request->version();
        $actorId = (int) $request->user()->getAuthIdentifier();
        $row = DB::connection('root')->transaction(function () use ($product, $version, $actorId, $request): AppUpdate {
            $update = AppUpdate::query()
                ->where('Product', $product)
                ->where('Channel', self::CHANNEL_V1)
                ->where('Version', $version)
                ->lockForUpdate()
                ->first();
            $this->assertYankable($update);
            $update->IsYanked = 1;
            $update->YankedAt = Carbon::now('UTC');
            $update->YankedByUserId = $actorId;
            $update->save();
            AuditWriter::write($request, 'UpdateYanked', 'AppUpdates', (int) $update->AppUpdateId, [
                'Product' => $update->Product,
                'Channel' => $update->Channel,
                'Version' => $update->Version,
            ]);

            return $update;
        });
        $requestId = (string) ($request->attributes->get('lara.request_id') ?? '');

        return ApiEnvelope::success([[
            'Product' => $row->Product,
            'Version' => $row->Version,
            'IsYanked' => 1,
            'YankedAt' => $row->YankedAt?->format('Y-m-d\TH:i:s\Z'),
        ]], $requestId, 200, 'OK');
    }

    private function assertYankable(?AppUpdate $update): void
    {
        if (($update instanceof AppUpdate) === false) {
            throw NotFoundException::notFound('UpdateAssetNotFound',
                'No manifest row matches the requested (Product, Channel=Stable, Version).',
                [['Field' => 'Version', 'Rule' => 'NotFound']],
            );
        }
        if ((int) $update->IsYanked === 1) {
            throw DomainConflictException::custom('ValidationConflict',
                'Version is already yanked; yank is irreversible in v1.',
                [['Field' => 'IsYanked', 'Rule' => 'AlreadyYanked']],
            )
        }
    }
}

