<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Db\ShardResolver;
use App\Http\Resources\MetricsResource;
use App\Http\Resources\ShardStatusResource;
use App\Models\AuthSession;
use App\Models\License;
use App\Models\QuotaRequest;
use App\Models\Reseller;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 09 step 29. Admin dashboard KPI aggregator.
 *
 * Root cause this addresses: the Admin console shell exposes a `StatCard`
 * primitive (v0.284.0) but the dashboard route has no backing data source,
 * so KPIs would silently render zero. This controller emits one envelope
 * that carries every tile at once, keyed by PascalCase per spec 21.
 *
 * Sources:
 *  - Root DB: `Resellers` (active count) and `AuthSessions` (unrevoked, unexpired).
 *  - Shard DBs: fanned out across all active resellers to sum `Licenses` and
 *    `QuotaRequests` in Pending status. Shard errors do not fail the request;
 *    they surface as `Attributes.Warnings[]` mirroring `QuotaRequestController::indexAll`.
 *
 * Endpoint:
 *   GET /Api/Admin/Metrics -> 200 with Results:[{ ... KPIs ... }]
 */
final class MetricsController
{
    private const QUOTA_REQUEST_STATUS_PENDING = 1;

    public function __construct(private readonly ShardResolver $shardResolver)
    {
    }

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="MetricsController index",
     *     tags={"MetricsController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->headers->get('X-Request-Id') ?? '');
        $resellersActive = (int) Reseller::query()->where('IsActive', true)->count();
        $sessionsActive = $this->countActiveSessions();
        [$licensesTotal, $quotaPending, $warnings] = $this->fanoutShardCounts($requestId);
        $payload = (new MetricsResource([
            'ResellersActive' => $resellersActive,
            'SessionsActive' => $sessionsActive,
            'LicensesTotal' => $licensesTotal,
            'QuotaRequestsPending' => $quotaPending,
            'GeneratedAt' => Carbon::now()->toIso8601ZuluString(),
        ]))->resolve();
        Log::info('admin.metrics.index', [
            'requestId' => $requestId,
            'resellersActive' => $resellersActive,
            'sessionsActive' => $sessionsActive,
            'licensesTotal' => $licensesTotal,
            'quotaPending' => $quotaPending,
            'warningCount' => count($warnings),
        ]);

        return ApiEnvelope::success([$payload], $requestId, extraAttributes: ['Warnings' => $warnings]);
    }

    /**
     * Plan 09 step 31 (backend half). Per-shard reachability probe.
     *
     * Root cause this addresses: the dashboard warnings banner (v0.287.0)
     * derives from a full metrics fanout that is stale for up to 30s. When
     * a shard recovers, operators had no cheap way to confirm health short
     * of hard-refresh. This lightweight endpoint returns one row per active
     * reseller with a `Reachable` boolean, so the banner's "Recheck now"
     * control can eventually target it without recomputing every KPI.
     *
     * Endpoint: GET /Api/Admin/Metrics/ShardStatus
     */
        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="MetricsController shardStatus",
     *     tags={"MetricsController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function shardStatus(Request $request): JsonResponse
    {
        $requestId = (string) ($request->headers->get('X-Request-Id') ?? '');
        $resellers = Reseller::query()->where('IsActive', true)->orderBy('ResellerSlug')->get();
        $rows = [];
        $unreachable = 0;
        foreach ($resellers as $reseller) {
            $slug = (string) $reseller->ResellerSlug;
            [$reachable, $error] = $this->probeShard($slug, $requestId);
            $rows[] = (new ShardStatusResource([
                'ResellerSlug' => $slug,
                'Reachable' => $reachable,
                'Error' => $error,
            ]))->resolve();
            if ($reachable === false) {
                $unreachable++;
            }
        }
        Log::info('admin.metrics.shard_status', [
            'requestId' => $requestId,
            'shardCount' => count($rows),
            'unreachable' => $unreachable,
            'checkedAt' => Carbon::now()->toIso8601ZuluString(),
        ]);

        return ApiEnvelope::success($rows, $requestId, extraAttributes: [
            'CheckedAt' => Carbon::now()->toIso8601ZuluString(),
            'UnreachableCount' => $unreachable,
        ]);
    }

    /**
     * @return array{0:bool,1:string|null}
     */
    private function probeShard(string $slug, string $requestId): array
    {
        try {
            $this->shardResolver->bind($slug);
            License::query()->limit(1)->exists();

            return [true, null];
        } catch (Throwable $e) {
            Log::warning('admin.metrics.shard_status.probe_error', [
                'requestId' => $requestId,
                'resellerSlug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return [false, 'ShardUnavailable'];
        }
    }

    private function countActiveSessions(): int
    {
        // Root cause fix (spec 47 §Session lifecycle): AuthSessions carries
        // `EndedAt`, not `RevokedAt`. The previous predicate referenced a
        // non-existent column and 500'd the dashboard KPI endpoint the
        // moment any AuthSessions row existed. Locked by MetricsTest.
        return (int) AuthSession::query()
            ->whereNull('EndedAt')
            ->where('ExpiresAt', '>', Carbon::now())
            ->count();
    }


    /**
     * @return array{0:int,1:int,2:array<int,array<string,string>>}
     */
    private function fanoutShardCounts(string $requestId): array
    {
        $resellers = Reseller::query()->where('IsActive', true)->orderBy('ResellerSlug')->get();
        $licensesTotal = 0;
        $quotaPending = 0;
        $warnings = [];
        foreach ($resellers as $reseller) {
            try {
                $this->shardResolver->bind((string) $reseller->ResellerSlug);
                $licensesTotal += (int) License::query()->where('ResellerId', (int) $reseller->ResellerId)->count();
                $quotaPending += (int) QuotaRequest::query()
                    ->where('ResellerId', (int) $reseller->ResellerId)
                    ->where('Status', self::QUOTA_REQUEST_STATUS_PENDING)
                    ->count();
            } catch (Throwable $e) {
                Log::warning('admin.metrics.shard_error', [
                    'requestId' => $requestId,
                    'resellerSlug' => (string) $reseller->ResellerSlug,
                    'error' => $e->getMessage(),
                ]);
                $warnings[] = ['ResellerSlug' => (string) $reseller->ResellerSlug, 'Error' => 'ShardUnavailable'];
            }
        }

        return [$licensesTotal, $quotaPending, $warnings];
    }
}
