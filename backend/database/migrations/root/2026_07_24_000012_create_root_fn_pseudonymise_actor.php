<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 25. GDPR pseudonymisation flow.
 *
 * Implements `fn_pseudonymise_actor(uuid, text)` to scrub actor emails/PII
 * and re-hash the audit chain per INV-BR-AU-5.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION "FnPseudonymiseActor"(subject_id uuid, legal_basis text)
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS $fn$
            DECLARE
                shard_rec RECORD;
                row_rec RECORD;
                last_hash BYTEA;
                lock_key BIGINT;
                start_row RECORD;
                new_payload JSONB;
            BEGIN
                FOR shard_rec IN (SELECT DISTINCT "ShardId" FROM "BackupAuditEvents" WHERE "ActorUserId" = subject_id) LOOP
                    lock_key := hashtext('audit.chain:' || shard_rec."ShardId");
                    PERFORM pg_advisory_xact_lock(lock_key);

                    SELECT "OccurredAt", "BackupAuditEventId", "PrevHash" INTO start_row
                    FROM "BackupAuditEvents"
                    WHERE "ShardId" = shard_rec."ShardId" AND "ActorUserId" = subject_id
                    ORDER BY "OccurredAt" ASC, "BackupAuditEventId" ASC
                    LIMIT 1;

                    last_hash := start_row."PrevHash";

                    FOR row_rec IN (
                        SELECT * FROM "BackupAuditEvents"
                        WHERE "ShardId" = shard_rec."ShardId"
                          AND (
                              "OccurredAt" > start_row."OccurredAt" 
                              OR ("OccurredAt" = start_row."OccurredAt" AND "BackupAuditEventId" >= start_row."BackupAuditEventId")
                          )
                        ORDER BY "OccurredAt" ASC, "BackupAuditEventId" ASC
                        FOR UPDATE
                    ) LOOP
                        IF row_rec."ActorUserId" = subject_id THEN
                            row_rec."ActorUserId" := NULL;
                            new_payload := row_rec."Payload" - 'UserEmail' - 'UserName';
                            row_rec."Payload" := new_payload;
                        END IF;

                        row_rec."PrevHash" := COALESCE(last_hash, decode('0000000000000000000000000000000000000000000000000000000000000000','hex'));
                        row_rec."RowHash" := digest(
                            row_rec."BackupAuditEventId"::text
                            || row_rec."OccurredAt"::text
                            || row_rec."Code"
                            || COALESCE(row_rec."ActorUserId"::text,'')
                            || row_rec."RequestId"
                            || COALESCE(row_rec."JobId"::text,'')
                            || COALESCE(row_rec."ErrorId",'')
                            || COALESCE(row_rec."SnapshotId"::text,'')
                            || COALESCE(row_rec."PolicyVersion"::text,'')
                            || row_rec."Payload"::text
                            || encode(row_rec."PrevHash",'hex')
                            || row_rec."ShardId"
                            || row_rec."SchemaVersion"::text,
                            'sha256'
                        );

                        UPDATE "BackupAuditEvents"
                        SET "ActorUserId" = row_rec."ActorUserId",
                            "Payload" = row_rec."Payload",
                            "PrevHash" = row_rec."PrevHash",
                            "RowHash" = row_rec."RowHash"
                        WHERE "BackupAuditEventId" = row_rec."BackupAuditEventId";

                        last_hash := row_rec."RowHash";
                    END LOOP;
                END LOOP;
            END;
            $fn$;
        SQL);
    }

    public function down(): void
    {
        // Revert to the shell
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION "FnPseudonymiseActor"(subject_id uuid, legal_basis text)
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'not_implemented'
                    USING HINT = 'Body lands in Plan 14 step 25; shell reserves signature per INV-BR-MG-2.';
            END;
            $fn$;
        SQL);
    }
};
