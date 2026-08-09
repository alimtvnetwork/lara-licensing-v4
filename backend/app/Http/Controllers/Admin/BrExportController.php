<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use App\Http\Requests\Admin\BrExportRequest;
use App\Services\BR\BrExportService;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 9. `POST /Api/Admin/Backup/Exports` shadow endpoint.
 *
 * Contract: spec/26-backup-restore/11-endpoint-export.md v1.0.0.
 *  - Middleware chain (from routes/api.php): auth:sanctum, session.active,
 *    require.role:Admin|SuperAdmin, IdempotencyKeyMiddleware.
 *  - Idempotency-Key REQUIRED (adds `api/admin/backup/exports` to the
 *    middleware's REQUIRED_PREFIXES); replay is byte-identical.
 *  - Returns 202 Accepted with canonical envelope (Status/Attributes/
 *    Results) plus `Location` header pointing at the job resource
 *    (INV-BR-EP-EX-1).
 *
 * Function bodies capped at 15 lines. No magic strings: Idempotency
 * header name and Location prefix are `private const`.
 */
final class BrExportController
{
    private const HEADER_IDEMPOTENCY = 'Idempotency-Key';
    private const HEADER_REQUEST_ID  = 'X-Request-Id';
    private const LOCATION_PREFIX    = '/Api/Admin/Backup/Jobs/';
    private const CAPABILITY         = 'Backup.Export';

    public function __construct(private readonly BrExportService $service) {}

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="BrExportController store",
     *     tags={"BrExportController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function store(BrExportRequest $request): JsonResponse
    {
        $idempotencyKey = $this->requireIdempotencyKey($request);
        $requestId = (string) $request->headers->get(self::HEADER_REQUEST_ID, '');
        $userId = (int) $request->user()->getAuthIdentifier();
        $result = $this->service->enqueue($userId, $idempotencyKey, $requestId, $request->payload());
        $resp = ApiEnvelope::success(
            [$this->responseData($result)],
            $requestId,
            httpCode: 202,
            message: 'Accepted',
            extraAttributes: ['IdempotencyKey' => $idempotencyKey, 'Capability' => self::CAPABILITY],
        );

        return $resp->header('Location', self::LOCATION_PREFIX . $result['JobId']);
    }

    private function requireIdempotencyKey(BrExportRequest $request): string
    {
        $key = trim((string) $request->headers->get(self::HEADER_IDEMPOTENCY, ''));
        if ($key === '') {
            Log::warning('br.export.missing_idempotency_key', [
                'RequestId' => $request->headers->get(self::HEADER_REQUEST_ID),
            ]);
            throw InternalException::custom('IdempotencyKeyRequired',
                'Idempotency-Key header is required on this endpoint.',
                [['Field' => self::HEADER_IDEMPOTENCY, 'Rule' => 'Missing']],
            )
        }

        return $key;
    }

    /**
     * @param array{JobId:string, ArchiveId:string, State:string, CreatedAt:string, Shadow:bool} $r
     * @return array<string, mixed>
     */
    private function responseData(array $r): array
    {
        return [
            'JobId'     => $r['JobId'],
            'ArchiveId' => $r['ArchiveId'],
            'State'     => $r['State'],
            'CreatedAt' => $r['CreatedAt'],
            'Shadow'    => $r['Shadow'],
        ];
    }
}
