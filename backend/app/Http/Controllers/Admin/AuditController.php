<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Resources\AuditEntryResource;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 09 step 23. Admin AuditLogs read surface.
 *
 * Root cause this exists: `AuditWriter::write` (spec 47 §5) persists every
 * mutation to Root `AuditLogs`, but there was no HTTP surface to read it,
 * so the on-screen LineageBadge convention was the only operator-visible
 * trail. This controller returns the newest N rows filtered by optional
 * `Action`, `TargetType`, and `ActorId` predicates, sorted by
 * `CreatedAt DESC`. Read-only, Admin-gated, no side effects.
 *
 * Endpoint:
 *   GET /Api/Admin/AuditLogs?Limit=&Action=&TargetType=&ActorId= -> 200
 */
final class AuditController
{
    private const CONN = 'root';
    private const TABLE = 'AuditLogs';
    private const LIMIT_DEFAULT = 100;
    private const LIMIT_MAX = 500;
    private const ACTION_REGEX = '/^[A-Za-z][A-Za-z0-9._]{1,63}$/';
    private const TARGET_REGEX = '/^[A-Za-z][A-Za-z0-9._]{1,63}$/';

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="AuditController index",
     *     tags={"AuditController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->headers->get('X-Request-Id') ?? '');
        [$limit, $filters] = $this->parseFilters($request);
        $rows = $this->fetch($limit, $filters);
        $projected = array_map(fn (array $r): array => $this->project($r), $rows);
        Log::info('admin.audit.index', [
            'requestId' => $requestId,
            'limit' => $limit,
            'filters' => $filters,
            'returned' => count($projected),
        ]);

        return ApiEnvelope::success($projected, $requestId, extraAttributes: [
            'Count' => count($projected),
            'Limit' => $limit,
        ]);
    }

    /**
     * @return array{0:int,1:array<string,int|string>}
     */
    private function parseFilters(Request $request): array
    {
        $limitRaw = (int) $request->query('Limit', (string) self::LIMIT_DEFAULT);
        $limit = max(1, min(self::LIMIT_MAX, $limitRaw));
        $filters = [];
        $action = trim((string) $request->query('Action', ''));
        if ($action !== '' && preg_match(self::ACTION_REGEX, $action) === 1) {
            $filters['Action'] = $action;
        }
        $target = trim((string) $request->query('TargetType', ''));
        if ($target !== '' && preg_match(self::TARGET_REGEX, $target) === 1) {
            $filters['TargetType'] = $target;
        }
        $actorRaw = trim((string) $request->query('ActorId', ''));
        if ($actorRaw !== '' && ctype_digit($actorRaw)) {
            $filters['ActorId'] = (int) $actorRaw;
        }

        return [$limit, $filters];
    }

    /**
     * @param array<string,int|string> $filters
     * @return array<int,array<string,mixed>>
     */
    private function fetch(int $limit, array $filters): array
    {
        $q = DB::connection(self::CONN)->table(self::TABLE)
            ->select(['AuditLogId', 'ActorType', 'ActorId', 'Action', 'TargetType', 'TargetId', 'RequestId', 'PayloadJson', 'CreatedAt'])
            ->orderByDesc('CreatedAt')
            ->orderByDesc('AuditLogId')
            ->limit($limit);
        foreach ($filters as $col => $val) {
            $q->where($col, $val);
        }

        return array_map(fn ($row): array => (array) $row, $q->get()->all());
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function project(array $row): array
    {
        return (new AuditEntryResource($row))->resolve();
    }
}
