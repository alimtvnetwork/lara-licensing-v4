<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 15. Root `IdempotencyRecords` table.
 *
 * Normative: spec/21-app/29-idempotency-lifecycle.md v1.0.0 §"Advisory lock
 * key" and §"Storage". A DB-backed record per (Endpoint, ActorId, Key) is
 * the single source of truth for replay and conflict decisions across
 * every worker in the fleet. The cache-only implementation used through
 * v0.234.0 could not survive process restarts or serve as a shared lock
 * across nodes; this migration retires that limitation.
 *
 * The row itself carries the response snapshot (status, headers-json,
 * body-blob) plus the request body hash so `IdempotencyKeyMiddleware`
 * can decide replay vs conflict under `SELECT ... FOR UPDATE`. TTL is
 * enforced logically at read time and swept by a scheduled command
 * (`idempotency:sweep`, follow-up step).
 *
 * Root is the correct home because idempotency is a cross-cutting API
 * property, not a tenant one: Admin, Reseller, and Portal callers all
 * pass through the same middleware, and Admin keys are inherently
 * cross-shard.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "IdempotencyRecords" (
                "IdempotencyRecordId"  BIGSERIAL    PRIMARY KEY,
                "Endpoint"             VARCHAR(200) NOT NULL,
                "ActorId"              VARCHAR(64)  NOT NULL,
                "IdempotencyKey"       VARCHAR(128) NOT NULL,
                "BodyHash"             CHAR(64)     NOT NULL,
                "ResponseStatus"       SMALLINT     NOT NULL,
                "ResponseHeadersJson"  TEXT         NOT NULL,
                "ResponseBody"         TEXT         NOT NULL,
                "CreatedAt"            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "ExpiresAt"            TIMESTAMPTZ  NOT NULL,
                CONSTRAINT "UX_IdempotencyRecords_Scope"
                    UNIQUE ("Endpoint", "ActorId", "IdempotencyKey")
            )
        ');

        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_IdempotencyRecords_ExpiresAt" ON "IdempotencyRecords" ("ExpiresAt")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "IdempotencyRecords"');
    }
};
