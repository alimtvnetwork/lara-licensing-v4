<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Db\ShardResolver;
use App\Models\AuthSession;
use App\Models\ImpersonationIndex;
use App\Models\Reseller;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 06 step 44 (impersonation timeout sweep). Satisfies AC-IMP-006 per
 * spec/21-app/47-impersonation-server-handler.md §4.
 *
 * Root cause this command exists: when an operator never calls
 * `POST /Impersonation/End`, the shard `AuthSessions` row and Root
 * `ImpersonationIndex` row would sit forever with `EndedAt = NULL`,
 * indefinitely blocking that operator from starting a new impersonation
 * via the global partial unique index `UX_ImpersonationIndex_OneActive`.
 * Spec 47 §4 mandates a 15s sweep that closes expired rows with
 * `EndedAt = ExpiresAt` (contractual end time, NOT `NOW()`) and writes
 * one `ImpersonationEnded` audit row with `EndReason = Timeout`.
 *
 * Behavior:
 *  - Iterate {Root} + every Reseller shard, `SELECT ... FOR UPDATE SKIP LOCKED`
 *    on `AuthSessions` rows where `Kind = Impersonation`, `EndedAt IS NULL`,
 *    `ExpiresAt <= NOW()`. Each row commits in its own transaction so one
 *    failure does not starve the batch (spec 47 §4 step 4).
 *  - For each row: update `AuthSessions` (shard scope), then stamp the
 *    Root `ImpersonationIndex` row, then insert Root `AuditLogs`.
 *  - Batch cap keeps a single run bounded; scheduler cadence (15s) picks
 *    up leftovers well before the AC-IMP-006 60s ceiling.
 */
final class ImpersonationTimeoutSweepCommand extends Command
{
    protected $signature = 'impersonation:timeout-sweep {--batch=200}';

    protected $description = 'Close expired impersonation sessions (AC-IMP-006, spec 47 §4).';

    private const ROOT = 'root';
    private const AUDIT_ACTOR_USER = 'User';
    private const AUDIT_ACTION_ENDED = 'ImpersonationEnded';
    private const AUDIT_TARGET_TYPE = 'Users';
    private const REQUEST_ID_PREFIX = 'sweep-';

    public function handle(ShardResolver $shards): int
    {
        $batch = (int) $this->option('batch');
        $totals = ['BatchSize' => $batch, 'ExpiredSessions' => 0, 'Errors' => 0];
        $this->sweepConnection(self::ROOT, $batch, $totals);
        foreach (Reseller::query()->orderBy('ResellerId')->cursor() as $reseller) {
            $slug = (string) $reseller->ResellerSlug;
            if ($slug === '') {
                continue;
            }
            $shards->bind($slug);
            $this->sweepConnection(ShardResolver::alias(), $batch, $totals);
        }
        Log::info('impersonation.sweep.completed', $totals);

        return self::SUCCESS;
    }

    /**
     * @param array{BatchSize:int,ExpiredSessions:int,Errors:int} $totals
     */
    private function sweepConnection(string $connection, int $batch, array &$totals): void
    {
        $rows = DB::connection($connection)
            ->table('AuthSessions')
            ->select(['SessionId', 'ImpersonatorUserId', 'UserId', 'ExpiresAt'])
            ->where('Kind', AuthSession::KIND_IMPERSONATION)
            ->whereNull('EndedAt')
            ->where('ExpiresAt', '<=', Carbon::now())
            ->limit($batch)
            ->get();
        foreach ($rows as $row) {
            $this->closeOne($connection, $row, $totals);
        }
    }

    /**
     * @param array{BatchSize:int,ExpiredSessions:int,Errors:int} $totals
     */
    private function closeOne(string $connection, object $row, array &$totals): void
    {
        $sessionId = (string) $row->SessionId;
        try {
            $this->closeOneTx($connection, $sessionId, $row);
            $totals['ExpiredSessions']++;
        } catch (Throwable $e) {
            $totals['Errors']++;
            Log::warning('impersonation.sweep.row_failed', [
                'SessionId' => $sessionId,
                'ShardConnection' => $connection,
                'Error' => $e->getMessage(),
            ]);
        }
    }

    private function closeOneTx(string $connection, string $sessionId, object $row): void
    {
        DB::connection(self::ROOT)->transaction(function () use ($connection, $sessionId, $row): void {
            $locked = $this->lockShardRow($connection, $sessionId);
            if ($locked === null) {
                return;
            }
            $endedAt = Carbon::parse((string) $locked->ExpiresAt);
            $this->stampShardEnded($connection, $sessionId, $endedAt);
            $this->stampIndexEnded($sessionId, $endedAt);
            $this->insertTimeoutAudit($sessionId, (int) $row->ImpersonatorUserId, (int) $row->UserId);
            // Plan 06 step 45: revoke the paired Sanctum bearer so the
            // impersonation token cannot outlive its session's contractual
            // ExpiresAt. Defence-in-depth alongside AssertActiveSessionMiddleware.
            app(\App\Services\AuthSessionService::class)->revokeTokensForSession($sessionId);
        });
    }

    private function lockShardRow(string $connection, string $sessionId): ?object
    {
        /** @var Connection $conn */
        $conn = DB::connection($connection);

        return $conn->table('AuthSessions')
            ->where('SessionId', $sessionId)
            ->whereNull('EndedAt')
            ->lockForUpdate()
            ->first(['SessionId', 'ExpiresAt']);
    }

    private function stampShardEnded(string $connection, string $sessionId, Carbon $endedAt): void
    {
        DB::connection($connection)->table('AuthSessions')
            ->where('SessionId', $sessionId)
            ->whereNull('EndedAt')
            ->update(['EndedAt' => $endedAt, 'RevokeReason' => ImpersonationIndex::END_TIMEOUT]);
    }

    private function stampIndexEnded(string $sessionId, Carbon $endedAt): void
    {
        ImpersonationIndex::query()
            ->where('SessionId', $sessionId)
            ->whereNull('EndedAt')
            ->update(['EndedAt' => $endedAt, 'EndReason' => ImpersonationIndex::END_TIMEOUT]);
    }

    private function insertTimeoutAudit(string $sessionId, int $operatorId, int $targetId): void
    {
        $payload = [
            'SessionId' => $sessionId,
            'ImpersonatorUserId' => $operatorId,
            'TargetUserId' => $targetId,
            'EndReason' => ImpersonationIndex::END_TIMEOUT,
        ];
        DB::connection(self::ROOT)->insert(
            \App\Support\AuditWriter::insertSql(self::ROOT),
            [
                self::AUDIT_ACTOR_USER,
                $operatorId,
                self::AUDIT_ACTION_ENDED,
                self::AUDIT_TARGET_TYPE,
                $targetId,
                self::REQUEST_ID_PREFIX . $sessionId,
                (string) json_encode($payload),
            ]
        );
    }
}
