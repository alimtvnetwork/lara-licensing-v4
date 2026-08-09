<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.300.0. Unauthenticated readiness probe: GET /Api/Public/Health.
 *
 * Root cause this exists: cPanel deploys, GitHub Actions release smoke
 * tests, and load-balancer probes have no cheap unauthenticated endpoint
 * to confirm the app boots and can reach Root. Every other route is
 * gated by Sanctum or requires an idempotency key; probes were forced to
 * hit `/Api/Auth/Login` and interpret 4xx envelopes as "up", which is
 * both wrong (rate-limit exhaustion masquerades as down) and pollutes
 * the login audit trail.
 *
 * Contract:
 *  - Status: 200 when boot ok + Root connectivity ok; 503 when Root ping fails.
 *  - Envelope: standard `ApiEnvelope::success` / `failure`.
 *  - Results[0]: { App, Version, RootDb, Time }. No secrets, no shard
 *    fanout (shard health lives on the authenticated
 *    Admin\MetricsController::shardStatus surface).
 *  - Failure code: `ServiceUnavailable` (already in the closed set).
 */
final class HealthController
{
    private const ROOT_CONNECTION = 'root';

    public function __invoke(Request $request): JsonResponse
    {
        $requestId = (string) $request->headers->get('X-Request-Id', '');
        $rootOk = $this->pingRoot();
        $payload = [
            'App' => (string) config('app.name', 'Licensing Portal'),
            'Version' => (string) config('app.version', 'dev'),
            'RootDb' => $rootOk ? 'ok' : 'down',
            'Time' => now()->toIso8601String(),
        ];
        if ($rootOk) {
            Log::info('public.health.ok', ['RequestId' => $requestId]);

            return ApiEnvelope::success([$payload], $requestId);
        }
        Log::error('public.health.root_down', ['RequestId' => $requestId]);

        return ApiEnvelope::failure(
            errorCode: 'ServiceUnavailable',
            errorMessage: 'Root database is unreachable.',
            requestId: $requestId,
            httpCode: 503,
            message: 'Service Unavailable',
            extraAttributes: ['Payload' => $payload],
        );
    }

    private function pingRoot(): bool
    {
        try {
            DB::connection(self::ROOT_CONNECTION)->selectOne('select 1 as ok');

            return true;
        } catch (Throwable $e) {
            Log::error('public.health.root_ping_failed', ['Exception' => $e::class, 'Message' => $e->getMessage()]);

            return false;
        }
    }
}
