<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\InternalException;


use App\Domain\BR\BrFlagId;
use App\Exceptions\LaraException;
use App\Http\Requests\Admin\BrImportRequest;
use App\Services\BR\BrFeatureFlagService;
use App\Services\BR\BrImportPreflight;
use App\Services\BR\BrImportService;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 29 (v0.678.0). `POST /Api/Admin/Backup/Imports` shadow
 * endpoint. Now handles BOTH `verifyOnly` (200 + preflight report,
 * shipped v0.676.0) and `verifyAndApply` (202 + Location, added here).
 *
 * Contract: spec/26-backup-restore/12-endpoint-import.md v1.0.0.
 *  - Middleware chain (from routes/api.php): auth:sanctum,
 *    session.active, require.role:Admin|SuperAdmin,
 *    IdempotencyKeyMiddleware (prefix `api/admin/backup/imports`).
 *  - Feature-flag gate: `BrFlagId::ImportEnabled` (`off` -> 503
 *    `FeatureNotAvailable`) plus kill-switch.
 *  - `verifyOnly` returns 200 with the BrImportPreflight report;
 *    never enqueues, never writes (INV-BR-RS-1, INV-BR-EP-IM-1).
 *  - `verifyAndApply` runs preflight synchronously (early rejection
 *    of bad archives), then enqueues one BackupJobs row via
 *    `BrImportService::enqueue` inside one root tx (INV-BR-EP-IM-3),
 *    returning 202 + `Location: /Api/Admin/Backup/Jobs/{JobId}`.
 *
 * Function bodies capped at 15 lines. No magic strings: header names,
 * capability, log keys, Location prefix are `private const`.
 */
final class BrImportController
{
    private const HEADER_IDEMPOTENCY = 'Idempotency-Key';
    private const HEADER_REQUEST_ID  = 'X-Request-Id';
    private const LOCATION_PREFIX    = '/Api/Admin/Backup/Jobs/';
    private const CAPABILITY         = 'Backup.Import';
    private const LOG_ACCEPTED       = 'br.import.endpoint.accepted';
    private const LOG_VERIFIED       = 'br.import.endpoint.verified';
    private const LOG_ENQUEUED       = 'br.import.endpoint.enqueued';

    public function __construct(
        private readonly BrFeatureFlagService $flags,
        private readonly BrImportPreflight $preflight,
        private readonly BrImportService $service,
    ) {}

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="BrImportController store",
     *     tags={"BrImportController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function store(BrImportRequest $request): JsonResponse
    {
        $idempotencyKey = $this->requireIdempotencyKey($request);
        $requestId = (string) $request->headers->get(self::HEADER_REQUEST_ID, '');
        $payload = $request->payload();
        $this->flags->assertKillSwitchOff();
        $this->flags->assertEnabled(BrFlagId::ImportEnabled);
        Log::info(self::LOG_ACCEPTED, $this->logCtx($payload, $requestId));
        $report = $this->preflight->run($payload['ArchiveId'], $this->appVersion(), $requestId);
        Log::info(self::LOG_VERIFIED, $this->logCtx($payload, $requestId));
        if ($payload['Mode'] === BrImportRequest::MODE_VERIFY_AND_APPLY) {
            return $this->respondApply($request, $idempotencyKey, $requestId, $payload, $report);
        }

        return $this->respondVerifyOnly($requestId, $idempotencyKey, $payload, $report);
    }

    /**
     * @param array{ArchiveId:string, Mode:string, Note:?string} $payload
     * @param array<string, mixed> $report
     */
    private function respondVerifyOnly(string $requestId, string $idempotencyKey, array $payload, array $report): JsonResponse
    {
        return ApiEnvelope::success(
            [$this->verifyOnlyData($report)],
            $requestId,
            httpCode: 200,
            message: 'OK',
            extraAttributes: ['IdempotencyKey' => $idempotencyKey, 'Capability' => self::CAPABILITY, 'Mode' => $payload['Mode']],
        );
    }

    /**
     * @param array{ArchiveId:string, Mode:string, Note:?string} $payload
     * @param array<string, mixed> $report
     */
    private function respondApply(BrImportRequest $request, string $idempotencyKey, string $requestId, array $payload, array $report): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $enqueue = $this->service->enqueue($userId, $idempotencyKey, $requestId, $payload['ArchiveId'], $payload['Note'], [
            'ManifestSha256'  => (string) ($report['ManifestSha256'] ?? ''),
            'EncryptionEpoch' => (int) ($report['EncryptionEpoch'] ?? 0),
            'EncryptionKid'   => (string) ($report['EncryptionKid'] ?? ''),
        ]);
        Log::info(self::LOG_ENQUEUED, ['JobId' => $enqueue['JobId'], 'ArchiveId' => $enqueue['ArchiveId'], 'RequestId' => $requestId, 'Shadow' => $enqueue['Shadow']]);
        $resp = ApiEnvelope::success(
            [$this->applyData($enqueue)],
            $requestId,
            httpCode: 202,
            message: 'Accepted',
            extraAttributes: ['IdempotencyKey' => $idempotencyKey, 'Capability' => self::CAPABILITY, 'Mode' => $payload['Mode']],
        );

        return $resp->header('Location', self::LOCATION_PREFIX . $enqueue['JobId']);
    }

    private function requireIdempotencyKey(BrImportRequest $request): string
    {
        $key = trim((string) $request->headers->get(self::HEADER_IDEMPOTENCY, ''));
        if ($key === '') {
            Log::warning('br.import.missing_idempotency_key', [
                'RequestId' => $request->headers->get(self::HEADER_REQUEST_ID),
            ]);
            throw InternalException::custom('IdempotencyKeyRequired',
                'Idempotency-Key header is required on this endpoint.',
                [['Field' => self::HEADER_IDEMPOTENCY, 'Rule' => 'Missing']],
            )
        }

        return $key;
    }

    private function appVersion(): string
    {
        return (string) (config('app.version') ?? config('lara.version') ?? '0.0.0');
    }

    /**
     * @param array{ArchiveId:string, Mode:string, Note:?string} $payload
     * @return array<string, mixed>
     */
    private function logCtx(array $payload, string $requestId): array
    {
        return ['ArchiveId' => $payload['ArchiveId'], 'Mode' => $payload['Mode'], 'RequestId' => $requestId];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function verifyOnlyData(array $r): array
    {
        return [
            'ArchiveId'       => (string) ($r['ArchiveId'] ?? ''),
            'ManifestSha256'  => (string) ($r['ManifestSha256'] ?? ''),
            'ChunkCount'      => (int) ($r['ChunkCount'] ?? 0),
            'EncryptionEpoch' => (int) ($r['EncryptionEpoch'] ?? 0),
            'EncryptionKid'   => (string) ($r['EncryptionKid'] ?? ''),
            'Scopes'          => (array) ($r['Scopes'] ?? []),
        ];
    }

    /**
     * @param array{JobId:string, ArchiveId:string, State:string, CreatedAt:string, Shadow:bool} $e
     * @return array<string, mixed>
     */
    private function applyData(array $e): array
    {
        return [
            'JobId'     => $e['JobId'],
            'ArchiveId' => $e['ArchiveId'],
            'State'     => $e['State'],
            'CreatedAt' => $e['CreatedAt'],
            'Shadow'    => $e['Shadow'],
        ];
    }
}
