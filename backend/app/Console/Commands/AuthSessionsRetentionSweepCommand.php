<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v0.298.0. Root `AuthSessions` retention sweep.
 *
 * Root cause this command exists: every login writes an `AuthSessions`
 * row that is only ever closed (EndedAt stamped) or left to natural TTL
 * expiry. Nothing hard-deletes the row, so `AssertActiveSessionMiddleware`
 * and `AuditEnrichment::resolveSession` scan an ever-growing table on
 * every authenticated request. Spec 31 assumes retention; we now enforce
 * it. Impersonation rows are excluded (Kind = Impersonation) because
 * they participate in the audit lineage read path via `ParentSessionId`.
 *
 * Behavior:
 *  - Delete rows where `Kind = 'Normal'` AND (`EndedAt IS NOT NULL AND
 *    EndedAt < now - retention_days` OR `EndedAt IS NULL AND ExpiresAt <
 *    now - retention_days`).
 *  - retention_days from config('lara.auth_sessions_retention_days').
 *  - Batched delete so a single run is bounded; scheduler cadence
 *    (daily) mops up the tail.
 */
final class AuthSessionsRetentionSweepCommand extends Command
{
    protected $signature = 'auth:sessions-retention-sweep {--batch=1000}';

    protected $description = 'Delete AuthSessions rows past retention window (spec 31).';

    private const CONN = 'root';
    private const TABLE = 'AuthSessions';
    private const KIND_NORMAL = 'Normal';

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $retentionDays = max(1, (int) config('lara.auth_sessions_retention_days', 90));
        $cutoff = Carbon::now()->subDays($retentionDays);

        $deleted = $this->deleteBatched($cutoff, $batch);
        Log::info('auth.sessions.retention_sweep', [
            'RetentionDays' => $retentionDays,
            'Cutoff' => $cutoff->toIso8601String(),
            'Deleted' => $deleted,
            'Batch' => $batch,
        ]);
        $this->line("auth:sessions-retention-sweep deleted={$deleted} cutoff={$cutoff->toIso8601String()}");

        return self::SUCCESS;
    }

    private function deleteBatched(Carbon $cutoff, int $batch): int
    {
        $total = 0;
        do {
            $ids = DB::connection(self::CONN)
                ->table(self::TABLE)
                ->where('Kind', self::KIND_NORMAL)
                ->where(function ($q) use ($cutoff): void {
                    $q->where(function ($qq) use ($cutoff): void {
                        $qq->whereNotNull('EndedAt')->where('EndedAt', '<', $cutoff);
                    })->orWhere(function ($qq) use ($cutoff): void {
                        $qq->whereNull('EndedAt')->where('ExpiresAt', '<', $cutoff);
                    });
                })
                ->limit($batch)
                ->pluck('SessionId')
                ->all();
            if ($ids === []) break;
            $n = DB::connection(self::CONN)->table(self::TABLE)->whereIn('SessionId', $ids)->delete();
            $total += (int) $n;
        } while (count($ids) === $batch);

        return $total;
    }
}
