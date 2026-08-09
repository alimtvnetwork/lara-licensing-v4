<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 43 (substrate). Root `ImpersonationIndex` table.
 *
 * Normative: spec/21-app/46-impersonation.md v1.1.0 §4.3.5. Lightweight
 * cross-reseller index of every impersonation session. For shard-scoped
 * targets the `AuthSessions` row lives in the target reseller's shard
 * (spec §4.3.2); this Root row is the observability pivot that lets an
 * Admin ask "who has an active impersonation right now" without fanning
 * out across every shard.
 *
 * Written inside the same two-phase transaction that inserts the shard
 * `AuthSessions` row (spec §4.3.5, AC-IMP-010). For Root-scoped targets
 * both writes happen on `root`, so the "two-phase" reduces to a single
 * DB transaction. SessionId is the shared handle.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "ImpersonationIndex" (
                "SessionId"           UUID         PRIMARY KEY,
                "ImpersonatorUserId"  BIGINT       NOT NULL REFERENCES "Users"("UserId") ON DELETE RESTRICT,
                "TargetUserId"        BIGINT       NOT NULL,
                "TargetResellerId"    BIGINT       NULL REFERENCES "Resellers"("ResellerId") ON DELETE RESTRICT,
                "StartedAt"           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "EndedAt"             TIMESTAMPTZ  NULL,
                "EndReason"           VARCHAR(32)  NULL
                    CHECK ("EndReason" IS NULL OR "EndReason" IN (\'OperatorEnded\',\'Timeout\',\'AdminForced\')),
                CONSTRAINT "CK_ImpersonationIndex_EndedReasonPaired"
                    CHECK (
                        ("EndedAt" IS NULL AND "EndReason" IS NULL)
                        OR ("EndedAt" IS NOT NULL AND "EndReason" IS NOT NULL)
                    )
            )
        ');

        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_ImpersonationIndex_Impersonator" ON "ImpersonationIndex" ("ImpersonatorUserId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_ImpersonationIndex_Target"       ON "ImpersonationIndex" ("TargetUserId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_ImpersonationIndex_Reseller"     ON "ImpersonationIndex" ("TargetResellerId")');

        // Matches AuthSessions partial-unique: one active row per operator
        // globally across shards. AC-IMP-004 / AC-IMP-011.
        DB::connection(self::CONN)->statement('
            CREATE UNIQUE INDEX IF NOT EXISTS "UX_ImpersonationIndex_OneActive"
            ON "ImpersonationIndex" ("ImpersonatorUserId")
            WHERE "EndedAt" IS NULL
        ');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "ImpersonationIndex"');
    }
};
