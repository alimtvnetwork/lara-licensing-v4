<?php

declare(strict_types=1);

use App\Db\ShardResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 43 (shard substrate). Shard `AuthSessions` table.
 *
 * Normative: spec/21-app/46-impersonation.md v1.1.0 §4.3.2 and §4.3.5,
 * spec/23-app-db/10-reseller-shard-split-db.md.
 *
 * Root cause this table exists: impersonation rows whose target is a
 * shard-scoped user (Users.TenantId IS NOT NULL) MUST land in that
 * tenant's shard (spec 46 §4.3.2). The Root AuthSessions table cannot
 * hold them without violating tenancy boundaries (AC-IMP-010).
 *
 * Cross-DB semantics:
 *  - `UserId` and `ImpersonatorUserId` reference `Users.UserId` in the
 *    Root DB. Cross-DB physical FKs are impossible, so this is a
 *    logical FK enforced by application code
 *    (spec 46 §4.3.3, ImpersonationService::begin).
 *  - `ParentSessionId` references `AuthSessions.SessionId` in Root
 *    (Admin's Normal session). Also a logical FK.
 *  - `UX_AuthSessions_OneActiveImpersonation` is deliberately omitted
 *    here: single-active-per-operator across shards is enforced by
 *    the Root `ImpersonationIndex.UX_ImpersonationIndex_OneActive`
 *    partial unique index (spec 46 §4.3.5, AC-IMP-011).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection(ShardResolver::alias())->statement('
            CREATE TABLE IF NOT EXISTS "AuthSessions" (
                "SessionId"           UUID         PRIMARY KEY,
                "UserId"              BIGINT       NOT NULL,
                "Kind"                VARCHAR(32)  NOT NULL
                    CHECK ("Kind" IN (\'Normal\',\'Impersonation\',\'ServiceAccount\')),
                "ImpersonatorUserId"  BIGINT       NULL,
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

        DB::connection(ShardResolver::alias())->statement('CREATE INDEX IF NOT EXISTS "IX_AuthSessions_UserId"            ON "AuthSessions" ("UserId")');
        DB::connection(ShardResolver::alias())->statement('CREATE INDEX IF NOT EXISTS "IX_AuthSessions_ImpersonatorUserId" ON "AuthSessions" ("ImpersonatorUserId")');
        DB::connection(ShardResolver::alias())->statement('CREATE INDEX IF NOT EXISTS "IX_AuthSessions_ParentSessionId"    ON "AuthSessions" ("ParentSessionId")');
    }

    public function down(): void
    {
        DB::connection(ShardResolver::alias())->statement('DROP TABLE IF EXISTS "AuthSessions"');
    }
};
