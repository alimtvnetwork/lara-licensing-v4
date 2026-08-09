<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 43 (substrate). Root `AuthSessions` table.
 *
 * Normative: spec/21-app/46-impersonation.md v1.1.0 §3 (Model),
 * spec/21-app/47-impersonation-server-handler.md v1.0.0 §2 (transactional
 * order), spec/21-app/31-auth-session-family.md (parent session lineage).
 *
 * Root-scoped rows only: Admin `Normal` sessions and impersonation rows
 * whose target is Root-scoped (Users.TenantId IS NULL). Shard-scoped
 * impersonation rows (target in a reseller shard) live in that shard's
 * `AuthSessions` table and land with the shard-provisioning migration
 * scheduled after Plan 06 step 43 wire-in.
 *
 * Kind enum is closed via CHECK. `ImpersonatorUserId` is NOT NULL when
 * Kind = Impersonation and NULL otherwise (partial CHECK). ExpiresAt is
 * always set at insert time; renewal path lives in the session-family
 * spec, not here.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "AuthSessions" (
                "SessionId"           UUID         PRIMARY KEY,
                "UserId"              BIGINT       NOT NULL REFERENCES "Users"("UserId") ON DELETE CASCADE,
                "Kind"                VARCHAR(32)  NOT NULL
                    CHECK ("Kind" IN (\'Normal\',\'Impersonation\',\'ServiceAccount\')),
                "ImpersonatorUserId"  BIGINT       NULL REFERENCES "Users"("UserId") ON DELETE RESTRICT,
                "ParentSessionId"     UUID         NULL,
                "CreatedAt"           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "ExpiresAt"           TIMESTAMPTZ  NOT NULL,
                "EndedAt"             TIMESTAMPTZ  NULL,
                "RevokeReason"        VARCHAR(32)  NULL
                    CHECK ("RevokeReason" IS NULL OR "RevokeReason" IN (\'OperatorEnded\',\'Timeout\',\'AdminForced\',\'FamilyRevoked\',\'RefreshReused\')),
                CONSTRAINT "CK_AuthSessions_ImpersonatorPairedWithKind"
                    CHECK (
                        ("Kind" = \'Impersonation\' AND "ImpersonatorUserId" IS NOT NULL AND "ParentSessionId" IS NOT NULL)
                        OR ("Kind" <> \'Impersonation\' AND "ImpersonatorUserId" IS NULL)
                    ),
                CONSTRAINT "CK_AuthSessions_EndedReasonPaired"
                    CHECK (
                        ("EndedAt" IS NULL AND "RevokeReason" IS NULL)
                        OR ("EndedAt" IS NOT NULL AND "RevokeReason" IS NOT NULL)
                    )
            )
        ');

        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_AuthSessions_UserId"            ON "AuthSessions" ("UserId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_AuthSessions_ImpersonatorUserId" ON "AuthSessions" ("ImpersonatorUserId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_AuthSessions_ParentSessionId"    ON "AuthSessions" ("ParentSessionId")');

        // Single active impersonation per operator (spec 46 AC-IMP-004 and
        // AC-IMP-011). Enforced as a partial unique index on ImpersonatorUserId
        // WHERE the session is active. Cross-shard uniqueness cannot be enforced
        // physically; the shard write path checks Root before insert
        // (spec 46 §4.3.4).
        DB::connection(self::CONN)->statement('
            CREATE UNIQUE INDEX IF NOT EXISTS "UX_AuthSessions_OneActiveImpersonation"
            ON "AuthSessions" ("ImpersonatorUserId")
            WHERE "Kind" = \'Impersonation\' AND "EndedAt" IS NULL
        ');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "AuthSessions"');
    }
};
