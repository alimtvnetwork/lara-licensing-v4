<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 1b. Root DB `BackupJobs` table.
 *
 * Normative source: spec/26-backup-restore/15-jobs-and-progress.md v1.0.0
 * §"Job Row Schema" (columns, types, unique indexes, dequeue index) and
 * §"State Machine" (closed transition set enforced at the app layer with
 * DB-level enum containment). Also `INV-BR-JP-4` (worker leases protected
 * by row-level `FOR UPDATE SKIP LOCKED`).
 *
 * Placement: root. Jobs are global; the dequeue query MUST see every
 * worker's pending row in a single index scan.
 *
 * Column mapping (spec camelCase -> physical PascalCase per project
 * memory):
 *  - id -> BackupJobId (UUID)                requestId  -> RequestId
 *  - kind -> Kind (BackupJobKind)            workerLeaseUntil -> WorkerLeaseUntil
 *  - state -> State (BackupJobState)         attemptCount -> AttemptCount
 *  - payload -> Payload (jsonb)              maxAttempts -> MaxAttempts
 *  - result -> Result (jsonb NULL)           progressSequence -> ProgressSequence
 *  - errorId/Code/Reason -> ErrorId/ErrorCode/ErrorReason
 *  - actorId -> ActorId                      createdAt/startedAt/finishedAt/cancelledAt
 *  - capability -> Capability                cancelRequestedAt (spec §Cancel)
 *  - idempotencyKey -> IdempotencyKey
 *
 * Indexes (per spec §"Job Row Schema"):
 *  - PK on BackupJobId
 *  - UX on (ActorId, IdempotencyKey) [supports 202 replay lookup]
 *  - IX on (State, Kind, CreatedAt) [dequeue query]
 *  - IX on (State, WorkerLeaseUntil) [reaper query]
 *
 * Reversibility: reversible in S0; forward-only after any BR job has been
 * enqueued per `INV-BR-MG-2` (audit rows must survive rollback).
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "BackupJobs" (
                "BackupJobId"        UUID              PRIMARY KEY,
                "Kind"               "BackupJobKind"   NOT NULL,
                "State"              "BackupJobState"  NOT NULL DEFAULT \'Queued\',
                "Payload"            JSONB             NOT NULL,
                "Result"             JSONB                 NULL,
                "ErrorId"            VARCHAR(32)           NULL,
                "ErrorCode"          VARCHAR(80)           NULL,
                "ErrorReason"        VARCHAR(120)          NULL,
                "ActorId"            UUID              NOT NULL,
                "Capability"         VARCHAR(80)       NOT NULL,
                "IdempotencyKey"     VARCHAR(128)      NOT NULL,
                "RequestId"          VARCHAR(64)       NOT NULL,
                "WorkerLeaseUntil"   TIMESTAMPTZ           NULL,
                "AttemptCount"       INTEGER           NOT NULL DEFAULT 0,
                "MaxAttempts"        INTEGER           NOT NULL DEFAULT 3,
                "ProgressSequence"   BIGINT            NOT NULL DEFAULT 0,
                "CancelRequestedAt"  TIMESTAMPTZ           NULL,
                "CreatedAt"          TIMESTAMPTZ       NOT NULL DEFAULT NOW(),
                "StartedAt"          TIMESTAMPTZ           NULL,
                "FinishedAt"         TIMESTAMPTZ           NULL,
                "CancelledAt"        TIMESTAMPTZ           NULL,
                CONSTRAINT "CkBackupJobsAttemptCountRange"
                    CHECK ("AttemptCount" >= 0 AND "AttemptCount" <= "MaxAttempts"),
                CONSTRAINT "CkBackupJobsMaxAttemptsRange"
                    CHECK ("MaxAttempts" >= 1 AND "MaxAttempts" <= 10),
                CONSTRAINT "CkBackupJobsTerminalHasFinishedAt"
                    CHECK (
                        ("State" IN (\'Succeeded\',\'Failed\',\'Cancelled\')
                            AND "FinishedAt" IS NOT NULL)
                        OR ("State" IN (\'Queued\',\'Running\')
                            AND "FinishedAt" IS NULL)
                    ),
                CONSTRAINT "CkBackupJobsCancelledHasCancelledAt"
                    CHECK (
                        ("State" = \'Cancelled\' AND "CancelledAt" IS NOT NULL)
                        OR ("State" <> \'Cancelled\' AND "CancelledAt" IS NULL)
                    ),
                CONSTRAINT "UxBackupJobsActorIdempotencyKey"
                    UNIQUE ("ActorId", "IdempotencyKey")
            )
        ');

        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupJobsDequeue"
                ON "BackupJobs" ("State", "Kind", "CreatedAt")
        ');

        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupJobsReaper"
                ON "BackupJobs" ("State", "WorkerLeaseUntil")
        ');

        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupJobsRequestId"
                ON "BackupJobs" ("RequestId")
        ');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "BackupJobs"');
    }
};
