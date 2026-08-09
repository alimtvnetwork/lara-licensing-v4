<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 06 (spec 47 §5, AC-IMP-007). Single write path for Root `AuditLogs`.
 *
 * Root cause this class exists:
 *   Handlers were inlining raw INSERTs (or worse, only writing to the
 *   application log), so we could not guarantee a row per mutation nor
 *   guarantee AuditEnrichment ran. All new mutating handlers MUST go
 *   through `AuditWriter::write`.
 *
 * Never throws to the caller: audit persistence failure is logged at
 * ERROR level but does not abort the underlying mutation transaction,
 * because dropping a user-facing mutation on an audit outage is a worse
 * failure mode than a missing audit row (which the log line captures).
 */
final class AuditWriter
{
    public const ROOT = 'root';
    private const ACTOR_USER = 'User';

    /**
     * @param array<string, mixed> $payload
     */
    public static function write(
        Request $request,
        string $action,
        string $targetType,
        int|string|null $targetId,
        array $payload,
    ): void {
        $actorId = self::actorId($request);
        $enriched = AuditEnrichment::enrich($request, $payload);
        $requestId = (string) ($enriched['RequestId'] ?? '');
        self::mirrorLog($action, $targetType, $targetId, $actorId, $enriched);
        try {
            DB::connection(self::ROOT)->insert(
                self::insertSql(),
                [self::ACTOR_USER, $actorId, $action, $targetType, $targetId === null ? null : (string) $targetId, $requestId, (string) json_encode($enriched)]
            );
        } catch (Throwable $e) {
            Log::error('audit.write_failed', ['Action' => $action, 'TargetType' => $targetType, 'TargetId' => $targetId, 'RequestId' => $requestId, 'Error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $enriched
     */
    private static function mirrorLog(string $action, string $targetType, int|string|null $targetId, ?int $actorId, array $enriched): void
    {
        Log::info('audit.write', [
            'Action' => $action, 'TargetType' => $targetType, 'TargetId' => $targetId, 'ActorId' => $actorId,
            'RequestId' => $enriched['RequestId'] ?? null,
            'ResellerId' => $enriched['ResellerId'] ?? null,
            'ResellerSlug' => $enriched['ResellerSlug'] ?? null,
            'PortalKeyId' => $enriched['PortalKeyId'] ?? null,
            'ImpersonatorUserId' => $enriched['ImpersonatorUserId'] ?? null,
        ]);
    }

    /**
     * Dialect-aware INSERT. Postgres jsonb columns need an explicit
     * `?::jsonb` cast on the last placeholder; sqlite (used by feature
     * tests) can't parse `::` so the cast is dropped. Every raw audit
     * insert (AuditWriter and sweep commands) MUST go through this
     * builder so the two drivers stay in lock-step.
     */
    public static function insertSql(string $connection = self::ROOT): string
    {
        $driver = (string) DB::connection($connection)->getDriverName();
        $cast = $driver === 'pgsql' ? '?::jsonb' : '?';

        return 'INSERT INTO "AuditLogs" ("ActorType","ActorId","Action","TargetType","TargetId","RequestId","PayloadJson") VALUES (?, ?, ?, ?, ?, ?, ' . $cast . ')';
    }

    private static function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
