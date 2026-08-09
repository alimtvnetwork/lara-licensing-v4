<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 2a. Root DB `BackupSnapshots` table + `BackupSnapshotState`
 * enum type.
 *
 * Normative sources:
 *  - spec/26-backup-restore/13-endpoint-snapshot.md v1.0.0
 *      §"Row Contract" (state machine `Draft -> Sealed -> Retiring -> Purged`),
 *      §"Pointer Contract (SC-H Pin Semantics)" (pin_count),
 *      §"Per-Shard Mutex" (advisory lock name),
 *      §"Retention" (closed set: keepDays, keepCount, keepUntilExplicitDelete).
 *  - spec/26-backup-restore/25-migration-and-rollout.md v1.0.0 §"Migration
 *    Order" migration 4 (`backup_snapshots`, reversible in S0).
 *  - INV-BR-EP-SN-1..7 (spec 13 §"Invariants Introduced").
 *
 * Placement: root DB. Snapshots are listed cross-shard by operators and
 * ScopeShards is a JSONB[] per spec (`{shardId, scopeSelector}` tuples),
 * so a single root table is the correct home. Per-shard advisory lock is
 * named `snapshot.create:<shardId>` and is asserted at write time, not
 * schema time.
 *
 * Column mapping (spec camelCase -> physical PascalCase per project memory):
 *   snapshotId     -> BackupSnapshotId (UUID v7)
 *   label          -> Label            (VARCHAR 80, ^[A-Za-z0-9._-]+$)
 *   note           -> Note             (VARCHAR 280 NULL)
 *   state          -> State            (BackupSnapshotState)
 *   scope          -> Scope            (JSONB, closed-set selectors)
 *   retention      -> Retention        (JSONB with policy in closed set)
 *   producedAt     -> ProducedAt       (TIMESTAMPTZ)
 *   sealedAt       -> SealedAt         (TIMESTAMPTZ NULL)
 *   retiringAt     -> RetiringAt       (TIMESTAMPTZ NULL)
 *   purgedAt       -> PurgedAt         (TIMESTAMPTZ NULL)
 *   expiresAt      -> ExpiresAt        (TIMESTAMPTZ NULL, derived from retention)
 *   pinCount       -> PinCount         (INTEGER, SC-H pin count)
 *   actorId        -> ActorId          (UUID)
 *   requestId      -> RequestId        (VARCHAR 64, INV-BR-OB-1)
 *   jobId          -> JobId            (UUID NULL, links to BackupJobs)
 *   idempotencyKey -> IdempotencyKey   (VARCHAR 128, unique per Actor)
 *   createdAt      -> CreatedAt        (TIMESTAMPTZ)
 *
 * Uniqueness:
 *   - Ux(Label) partial WHERE State IN ('Draft','Sealed','Retiring')
 *     enforces INV-BR-EP-SN-4 (label unique across active snapshots).
 *   - Ux(ActorId, IdempotencyKey) supports 202 replay lookup.
 *
 * Indexes:
 *   - Ix(State, ExpiresAt) drives the retention sweeper.
 *   - Ix(JobId) supports 202 replay + progress joins.
 *
 * Reversibility: reversible in S0 (drops table + enum) per
 * INV-BR-MG-2 gating (no snapshot rows exist yet).
 */
return new class extends Migration
{
    private const CONN = 'root';

    private const STATE_MEMBERS = [
        'Draft',
        'Sealed',
        'Retiring',
        'Purged',
    ];

    public function up(): void
    {
        $this->createSnapshotStateEnum();
        $this->createSnapshotsTable();
        $this->createSnapshotIndexes();
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "BackupSnapshots"');
        DB::connection(self::CONN)->statement('DROP TYPE IF EXISTS "BackupSnapshotState"');
    }

    private function createSnapshotStateEnum(): void
    {
        $quoted = implode(',', array_map(
            static fn (string $m): string => "'{$m}'",
            self::STATE_MEMBERS,
        ));
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'BackupSnapshotState') THEN
                    CREATE TYPE "BackupSnapshotState" AS ENUM ({$quoted});
                END IF;
            END
            $$;
        SQL);
    }

    private function createSnapshotsTable(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "BackupSnapshots" (
                "BackupSnapshotId" UUID                   PRIMARY KEY,
                "Label"            VARCHAR(80)            NOT NULL,
                "Note"             VARCHAR(280)               NULL,
                "State"            "BackupSnapshotState"  NOT NULL DEFAULT \'Draft\',
                "Scope"            JSONB                  NOT NULL,
                "Retention"        JSONB                  NOT NULL,
                "ProducedAt"       TIMESTAMPTZ            NOT NULL DEFAULT NOW(),
                "SealedAt"         TIMESTAMPTZ                NULL,
                "RetiringAt"       TIMESTAMPTZ                NULL,
                "PurgedAt"         TIMESTAMPTZ                NULL,
                "ExpiresAt"        TIMESTAMPTZ                NULL,
                "PinCount"         INTEGER                NOT NULL DEFAULT 0,
                "ActorId"          UUID                   NOT NULL,
                "RequestId"        VARCHAR(64)            NOT NULL,
                "JobId"            UUID                       NULL,
                "IdempotencyKey"   VARCHAR(128)           NOT NULL,
                "CreatedAt"        TIMESTAMPTZ            NOT NULL DEFAULT NOW(),
                CONSTRAINT "CkBackupSnapshotsLabelPattern"
                    CHECK ("Label" ~ \'^[A-Za-z0-9._-]+$\' AND char_length("Label") BETWEEN 1 AND 80),
                CONSTRAINT "CkBackupSnapshotsPinCountRange"
                    CHECK ("PinCount" >= 0),
                CONSTRAINT "CkBackupSnapshotsRetentionPolicy"
                    CHECK ("Retention" ? \'policy\'
                        AND ("Retention"->>\'policy\') IN (\'keepDays\',\'keepCount\',\'keepUntilExplicitDelete\')),
                CONSTRAINT "CkBackupSnapshotsSealedHasTimestamp"
                    CHECK (
                        ("State" = \'Draft\' AND "SealedAt" IS NULL)
                        OR ("State" IN (\'Sealed\',\'Retiring\',\'Purged\') AND "SealedAt" IS NOT NULL)
                    ),
                CONSTRAINT "CkBackupSnapshotsPurgedHasTimestamp"
                    CHECK (
                        ("State" = \'Purged\' AND "PurgedAt" IS NOT NULL)
                        OR ("State" <> \'Purged\' AND "PurgedAt" IS NULL)
                    ),
                CONSTRAINT "UxBackupSnapshotsActorIdempotencyKey"
                    UNIQUE ("ActorId", "IdempotencyKey")
            )
        ');
    }

    private function createSnapshotIndexes(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE UNIQUE INDEX IF NOT EXISTS "UxBackupSnapshotsLabelActive"
                ON "BackupSnapshots" ("Label")
                WHERE "State" IN (\'Draft\',\'Sealed\',\'Retiring\')
        ');

        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupSnapshotsRetentionSweep"
                ON "BackupSnapshots" ("State", "ExpiresAt")
        ');

        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupSnapshotsJobId"
                ON "BackupSnapshots" ("JobId")
        ');

        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupSnapshotsRequestId"
                ON "BackupSnapshots" ("RequestId")
        ');
    }
};
