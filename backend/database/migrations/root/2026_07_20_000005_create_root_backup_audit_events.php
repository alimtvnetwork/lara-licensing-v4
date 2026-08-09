<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 2b. Root DB `BackupAuditEvents` table + `AuditActorKind`
 * enum + `BEFORE INSERT` trigger that computes `PrevHash`/`RowHash` under
 * a per-shard advisory transaction lock (hash-chain).
 *
 * Normative sources:
 *  - spec/26-backup-restore/23-audit-and-compliance.md v1.0.0 §"Table"
 *    (column list + types), §"Hash-Chain Guarantees" (BEFORE INSERT
 *    trigger reads prevHash under `pg_advisory_xact_lock('audit.chain:'
 *    ||shardId)`), §"GDPR Right-to-Erasure" (only migration role
 *    `audit_pseudonymiser` may UPDATE; see migration 6 for that role +
 *    procedure).
 *  - INV-BR-AU-1..7 (append-only, chain-continuity, per-shard chain,
 *    genesis prevHash = x'00'*32, chain re-hash under advisory lock).
 *  - spec/26-backup-restore/25-migration-and-rollout.md v1.0.0 §"Migration
 *    Order" migration 5 (`backup_audit_events`, reversible in S0 while no
 *    rows exist; forward-only afterwards per INV-BR-MG-2).
 *
 * Placement: root DB. The chain is per-shard (rows carry `ShardId`) but
 * the physical table is single so operators can verify chains
 * cross-shard from one place; spec 23 §"Hash-Chain Guarantees" pt 4:
 * "The chain is not global; it is per-shard so shard-scoped Exports can
 * carry a complete verifiable slice."
 *
 * Column mapping (spec camelCase -> physical PascalCase):
 *   id            -> BackupAuditEventId (UUID v7)
 *   occurredAt    -> OccurredAt         (TIMESTAMPTZ)
 *   code          -> Code               (VARCHAR 80, closed set from
 *                                        spec/22 audit catalogue + the
 *                                        two additions from spec/23)
 *   actorKind     -> ActorKind          (AuditActorKind enum)
 *   actorUserId   -> ActorUserId        (UUID NULL)
 *   actorRole     -> ActorRole          (VARCHAR 80 NULL)
 *   requestId     -> RequestId          (VARCHAR 64) INV-BR-OB-1
 *   jobId         -> JobId              (UUID NULL)
 *   errorId       -> ErrorId            (VARCHAR 32 NULL)
 *   snapshotId    -> SnapshotId         (UUID NULL)
 *   policyVersion -> PolicyVersion      (INTEGER NULL)
 *   payload       -> Payload            (JSONB, bounded 4 KiB)
 *   prevHash      -> PrevHash           (BYTEA(32))
 *   rowHash       -> RowHash            (BYTEA(32))
 *   shardId       -> ShardId            (VARCHAR 80)
 *   schemaVersion -> SchemaVersion      (INTEGER, initial value 1)
 *
 * Trigger: `TrBackupAuditEventsHashChain` runs BEFORE INSERT. It:
 *   1. Acquires `pg_advisory_xact_lock(hashtext('audit.chain:'||ShardId))`
 *      so concurrent inserts serialise per shard.
 *   2. Reads the previous row's RowHash for the same ShardId (or
 *      x'00'*32 for genesis).
 *   3. Overwrites NEW.PrevHash and NEW.RowHash. Application-supplied
 *      values are ignored, satisfying spec 23 §"Hash-Chain Guarantees"
 *      pt 1 "the application cannot supply it".
 *
 * Payload size cap (4 KiB) is enforced by a CHECK constraint on
 * `octet_length(Payload::text) <= 4096` per spec 23 payload rules.
 *
 * Reversibility: reversible in S0 (drops trigger, function, table, enum)
 * before any row exists. After genesis emission this migration becomes
 * forward-only per INV-BR-MG-2.
 */
return new class extends Migration
{
    private const CONN = 'root';

    private const ACTOR_KIND_MEMBERS = [
        'user',
        'worker',
        'server',
        'pep',
    ];

    public function up(): void
    {
        $this->createActorKindEnum();
        $this->createAuditEventsTable();
        $this->createAuditIndexes();
        $this->createHashChainFunction();
        $this->createHashChainTrigger();
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TRIGGER IF EXISTS "TrBackupAuditEventsHashChain" ON "BackupAuditEvents"');
        DB::connection(self::CONN)->statement('DROP FUNCTION IF EXISTS "FnBackupAuditEventsHashChain"()');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "BackupAuditEvents"');
        DB::connection(self::CONN)->statement('DROP TYPE IF EXISTS "AuditActorKind"');
    }

    private function createActorKindEnum(): void
    {
        $quoted = implode(',', array_map(
            static fn (string $m): string => "'{$m}'",
            self::ACTOR_KIND_MEMBERS,
        ));
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'AuditActorKind') THEN
                    CREATE TYPE "AuditActorKind" AS ENUM ({$quoted});
                END IF;
            END
            $$;
        SQL);
    }

    private function createAuditEventsTable(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "BackupAuditEvents" (
                "BackupAuditEventId" UUID              PRIMARY KEY,
                "OccurredAt"         TIMESTAMPTZ       NOT NULL DEFAULT NOW(),
                "Code"               VARCHAR(80)       NOT NULL,
                "ActorKind"          "AuditActorKind"  NOT NULL,
                "ActorUserId"        UUID                  NULL,
                "ActorRole"          VARCHAR(80)           NULL,
                "RequestId"          VARCHAR(64)       NOT NULL,
                "JobId"              UUID                  NULL,
                "ErrorId"            VARCHAR(32)           NULL,
                "SnapshotId"         UUID                  NULL,
                "PolicyVersion"      INTEGER               NULL,
                "Payload"            JSONB             NOT NULL,
                "PrevHash"           BYTEA             NOT NULL,
                "RowHash"            BYTEA             NOT NULL,
                "ShardId"            VARCHAR(80)       NOT NULL,
                "SchemaVersion"      INTEGER           NOT NULL DEFAULT 1,
                CONSTRAINT "CkBackupAuditEventsPayloadSize"
                    CHECK (octet_length("Payload"::text) <= 4096),
                CONSTRAINT "CkBackupAuditEventsHashLengths"
                    CHECK (octet_length("PrevHash") = 32 AND octet_length("RowHash") = 32),
                CONSTRAINT "CkBackupAuditEventsActorUserRule"
                    CHECK (
                        ("ActorKind" = \'user\'  AND "ActorUserId" IS NOT NULL)
                        OR ("ActorKind" <> \'user\')
                    )
            )
        ');
    }

    private function createAuditIndexes(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupAuditEventsShardOccurred"
                ON "BackupAuditEvents" ("ShardId", "OccurredAt")
        ');
        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupAuditEventsRequestId"
                ON "BackupAuditEvents" ("RequestId")
        ');
        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxBackupAuditEventsCode"
                ON "BackupAuditEvents" ("Code")
        ');
    }

    /**
     * SHA-256 hash-chain function. Per spec 23 §"Hash-Chain Guarantees":
     *   PrevHash := last row for this ShardId OR x'00'*32 for genesis
     *   RowHash  := SHA-256(concat(id, occurredAt, code, ..., prevHash))
     * Serialised per-shard via `pg_advisory_xact_lock(hashtext(...))`.
     */
    private function createHashChainFunction(): void
    {
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION "FnBackupAuditEventsHashChain"()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                last_hash BYTEA;
                lock_key  BIGINT;
            BEGIN
                lock_key := hashtext('audit.chain:' || NEW."ShardId");
                PERFORM pg_advisory_xact_lock(lock_key);

                SELECT "RowHash" INTO last_hash
                FROM "BackupAuditEvents"
                WHERE "ShardId" = NEW."ShardId"
                ORDER BY "OccurredAt" DESC, "BackupAuditEventId" DESC
                LIMIT 1;

                NEW."PrevHash" := COALESCE(last_hash, decode('0000000000000000000000000000000000000000000000000000000000000000','hex'));
                NEW."RowHash" := digest(
                    NEW."BackupAuditEventId"::text
                    || NEW."OccurredAt"::text
                    || NEW."Code"
                    || COALESCE(NEW."ActorUserId"::text,'')
                    || NEW."RequestId"
                    || COALESCE(NEW."JobId"::text,'')
                    || COALESCE(NEW."ErrorId",'')
                    || COALESCE(NEW."SnapshotId"::text,'')
                    || COALESCE(NEW."PolicyVersion"::text,'')
                    || NEW."Payload"::text
                    || encode(NEW."PrevHash",'hex')
                    || NEW."ShardId"
                    || NEW."SchemaVersion"::text,
                    'sha256'
                );
                RETURN NEW;
            END;
            $fn$;
        SQL);
    }

    private function createHashChainTrigger(): void
    {
        DB::connection(self::CONN)->statement('
            DROP TRIGGER IF EXISTS "TrBackupAuditEventsHashChain" ON "BackupAuditEvents"
        ');
        DB::connection(self::CONN)->statement('
            CREATE TRIGGER "TrBackupAuditEventsHashChain"
                BEFORE INSERT ON "BackupAuditEvents"
                FOR EACH ROW
                EXECUTE FUNCTION "FnBackupAuditEventsHashChain"()
        ');
    }
};
