<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrJobKind;
use App\Domain\BR\BrJobState;
use App\Domain\BR\BrRetryPolicy;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Plan 14 step 10. Kind-agnostic dequeue + lease + terminal-transition
 * primitive for BR workers.
 *
 * Contract mirrors spec 26 §15 "Worker Lease and Dequeue" +
 * "State Machine" + "Retry Policy":
 *  - `dequeue(kind, workerId, requestId)` runs
 *      SELECT ... FROM "BackupJobs"
 *      WHERE "State"='Queued' AND "Kind"=? AND ("WorkerLeaseUntil" IS NULL OR "WorkerLeaseUntil" < NOW())
 *      ORDER BY "CreatedAt" ASC
 *      FOR UPDATE SKIP LOCKED
 *      LIMIT 1
 *    inside a single `root` transaction. On hit it flips the row to
 *    `Running`, bumps `AttemptCount`, sets `StartedAt = coalesce(...,NOW())`
 *    and `WorkerLeaseUntil = NOW() + LEASE_SECONDS`. Returns the
 *    post-update row as an assoc array; returns `null` when no row
 *    matched (INV-BR-JP-4, INV-BR-A guaranteed by the outer tx).
 *  - `succeed(jobId, result)` transitions `Running -> Succeeded` and
 *    persists `Result`.
 *  - `fail(jobId, kind, errorCode, errorReason, errorId, attemptCount)`
 *    consults `BrRetryPolicy`; retryable + under cap -> `Running -> Queued`
 *    with `WorkerLeaseUntil = NOW() + backoff[attemptCount-1]`. Non-retryable
 *    or exhausted -> `Running -> Failed` with `FinishedAt = NOW()`.
 *
 * 15-line function cap enforced by splitting `dequeue` into `lockRow` +
 * `promoteRow`, and `fail` into `retryable` + `terminal`.
 */
final class BrJobDispatcher
{
    public const CONN = 'root';
    private const TABLE = 'BackupJobs';
    private const LEASE_SECONDS = 60;
    private const LOG_DEQUEUE = 'br.job.dequeued';
    private const LOG_SUCCESS = 'br.job.succeeded';
    private const LOG_RETRY   = 'br.job.retry_scheduled';
    private const LOG_FAILED  = 'br.job.failed';
    private const ERR_TRANSITION = 'BackupWorkerTransitionFailed';

    /**
     * @return array<string, mixed>|null
     */
    public function dequeue(BrJobKind $kind, string $workerId, string $requestId): ?array
    {
        return DB::connection(self::CONN)->transaction(function () use ($kind, $workerId, $requestId): ?array {
            $row = $this->lockRow($kind);
            if ($row === null) {
                return null;
            }
            $updated = $this->promoteRow((array) $row, $workerId);
            Log::info(self::LOG_DEQUEUE, ['JobId' => $updated['BackupJobId'], 'Kind' => $kind->value, 'AttemptCount' => $updated['AttemptCount'], 'WorkerId' => $workerId, 'RequestId' => $requestId, 'InheritedRequestId' => $updated['RequestId']]);

            return $updated;
        });
    }

    /** @return object|null */
    private function lockRow(BrJobKind $kind): ?object
    {
        $sql = 'SELECT * FROM "' . self::TABLE . '"'
            . ' WHERE "State" = ? AND "Kind" = ?'
            . ' AND ("WorkerLeaseUntil" IS NULL OR "WorkerLeaseUntil" < NOW())'
            . ' ORDER BY "CreatedAt" ASC'
            . ' FOR UPDATE SKIP LOCKED'
            . ' LIMIT 1';
        $rows = DB::connection(self::CONN)->select($sql, [BrJobState::Queued->value, $kind->value]);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function promoteRow(array $row, string $workerId): array
    {
        $jobId = (string) $row['BackupJobId'];
        $newAttempt = ((int) $row['AttemptCount']) + 1;
        $maxAttempts = (int) $row['MaxAttempts'];
        if ($newAttempt > $maxAttempts) {
            Log::error('br.job.attempt_overflow', ['JobId' => $jobId, 'AttemptCount' => $newAttempt, 'MaxAttempts' => $maxAttempts, 'WorkerId' => $workerId]);
            throw InternalException::custom(self::ERR_TRANSITION, 'AttemptCount would exceed MaxAttempts on dequeue.', [['Field' => 'BackupJobs.AttemptCount', 'Rule' => 'ExceedsMax']]);
        }
        DB::connection(self::CONN)->update(
            'UPDATE "' . self::TABLE . '" SET "State"=?, "AttemptCount"=?, "StartedAt"=COALESCE("StartedAt", NOW()), "WorkerLeaseUntil"=NOW() + INTERVAL \'' . self::LEASE_SECONDS . ' seconds\' WHERE "BackupJobId"=? AND "State"=?',
            [BrJobState::Running->value, $newAttempt, $jobId, BrJobState::Queued->value],
        );
        $row['State'] = BrJobState::Running->value;
        $row['AttemptCount'] = $newAttempt;

        return $row;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function succeed(string $jobId, array $result, string $requestId): void
    {
        $affected = DB::connection(self::CONN)->update(
            'UPDATE "' . self::TABLE . '" SET "State"=?, "Result"=?::jsonb, "FinishedAt"=NOW(), "WorkerLeaseUntil"=NULL WHERE "BackupJobId"=? AND "State"=?',
            [BrJobState::Succeeded->value, (string) json_encode($result), $jobId, BrJobState::Running->value],
        );
        if ($affected === 0) {
            Log::error('br.job.succeed_no_row', ['JobId' => $jobId, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_TRANSITION, 'succeed transition affected zero rows', [['Field' => 'BackupJobs.State', 'Rule' => 'NotRunning']]);
        }
        Log::info(self::LOG_SUCCESS, ['JobId' => $jobId, 'RequestId' => $requestId]);
    }

    public function fail(string $jobId, BrJobKind $kind, string $errorCode, string $errorReason, string $errorId, int $attemptCount, int $maxAttempts, string $requestId): void
    {
        $backoff = BrRetryPolicy::backoffSecondsFor($kind, $attemptCount);
        $canRetry = $backoff !== null && $attemptCount < $maxAttempts;
        if ($canRetry) {
            $this->retryable($jobId, $errorCode, $errorReason, $errorId, $backoff, $attemptCount, $requestId);

            return;
        }
        $this->terminal($jobId, $errorCode, $errorReason, $errorId, $attemptCount, $maxAttempts, $requestId);
    }

    private function retryable(string $jobId, string $errorCode, string $errorReason, string $errorId, int $backoffSeconds, int $attemptCount, string $requestId): void
    {
        DB::connection(self::CONN)->update(
            'UPDATE "' . self::TABLE . '" SET "State"=?, "WorkerLeaseUntil"=NOW() + INTERVAL \'' . $backoffSeconds . ' seconds\', "ErrorId"=?, "ErrorCode"=?, "ErrorReason"=? WHERE "BackupJobId"=? AND "State"=?',
            [BrJobState::Queued->value, $errorId, $errorCode, $errorReason, $jobId, BrJobState::Running->value],
        );
        Log::warning(self::LOG_RETRY, ['JobId' => $jobId, 'AttemptCount' => $attemptCount, 'BackoffSeconds' => $backoffSeconds, 'ErrorId' => $errorId, 'ErrorCode' => $errorCode, 'RequestId' => $requestId]);
    }

    private function terminal(string $jobId, string $errorCode, string $errorReason, string $errorId, int $attemptCount, int $maxAttempts, string $requestId): void
    {
        DB::connection(self::CONN)->update(
            'UPDATE "' . self::TABLE . '" SET "State"=?, "FinishedAt"=NOW(), "WorkerLeaseUntil"=NULL, "ErrorId"=?, "ErrorCode"=?, "ErrorReason"=? WHERE "BackupJobId"=? AND "State"=?',
            [BrJobState::Failed->value, $errorId, $errorCode, $errorReason, $jobId, BrJobState::Running->value],
        );
        Log::error(self::LOG_FAILED, ['JobId' => $jobId, 'AttemptCount' => $attemptCount, 'MaxAttempts' => $maxAttempts, 'ErrorId' => $errorId, 'ErrorCode' => $errorCode, 'ErrorReason' => $errorReason, 'RequestId' => $requestId]);
    }

    public static function newWorkerId(string $prefix): string
    {
        return $prefix . '-' . Uuid::uuid7()->toString();
    }
}
