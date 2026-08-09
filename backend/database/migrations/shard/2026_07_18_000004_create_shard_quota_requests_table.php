<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 18. Shard DB `QuotaRequests` (approval workflow inbox).
 *
 * Normative source: spec/21-app/42-quota-requests.md v1.1.0 §State machine
 * and §Approval obligations. Column set and CHECKs mirror
 * spec/23-app-db/01-schema.md §QuotaRequests (lines 280-320). This table
 * lives on the shard per spec/23-app-db/10-reseller-shard-split-db.md
 * §App-tier tables per reseller shard: quota rows never leak to Root, they
 * are scoped to their reseller's shard.
 *
 * `Status` is enum-backed per SA-031 with a CHECK IN (1,2,3,4) mapping to
 * `Pending`,`Approved`,`Denied`,`Cancelled` (AC-QR-007). The four
 * transition CHECKs (AC-ADB-007) forbid any illegal combination of
 * `Status` and its dependent columns.
 *
 * FK `QuotaRequestId` from `LicenseLedger` (step 17 migration) is a loose
 * numeric reference until this table exists; the physical FK is declared
 * here on `LicenseLedger.QuotaRequestId` in a follow-up ALTER, kept out of
 * this file so a fresh shard boot ordering never dead-locks.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "QuotaRequests" (
                "QuotaRequestId"      BIGSERIAL PRIMARY KEY,
                "ResellerId"          BIGINT NOT NULL,
                "LicenseCategoryId"   SMALLINT NOT NULL,
                "LicenseTierId"       SMALLINT NOT NULL,
                "RequestedDelta"      INTEGER NOT NULL,
                "ApprovedDelta"       INTEGER NULL,
                "Status"              SMALLINT NOT NULL DEFAULT 1,
                "Justification"       VARCHAR(1024) NOT NULL,
                "DenialReason"        VARCHAR(1024) NULL,
                "SubmittedByUserId"   BIGINT NOT NULL,
                "DecidedByUserId"     BIGINT NULL,
                "SubmittedAt"         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "DecidedAt"           TIMESTAMPTZ NULL,
                "RequestId"           CHAR(26) NOT NULL,
                "IdempotencyKey"      CHAR(32) NOT NULL,
                CONSTRAINT "CkQuotaRequestsStatusRange"
                    CHECK ("Status" IN (1,2,3,4)),
                CONSTRAINT "CkQuotaRequestsDeltaNonzero"
                    CHECK ("RequestedDelta" <> 0),
                CONSTRAINT "CkQuotaRequestsApprovedDelta"
                    CHECK ("Status" <> 2 OR "ApprovedDelta" IS NOT NULL),
                CONSTRAINT "CkQuotaRequestsDenialReason"
                    CHECK ("Status" <> 3 OR "DenialReason" IS NOT NULL),
                CONSTRAINT "CkQuotaRequestsDecidedBy"
                    CHECK (("Status" IN (2,3) AND "DecidedByUserId" IS NOT NULL) OR "Status" NOT IN (2,3)),
                CONSTRAINT "CkQuotaRequestsDecidedAt"
                    CHECK (("Status" IN (2,3,4) AND "DecidedAt" IS NOT NULL) OR "Status" = 1)
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX  IF NOT EXISTS "IX_QuotaRequests_Reseller_Status_Submitted" ON "QuotaRequests" ("ResellerId","Status","SubmittedAt")');
        DB::connection(self::CONN)->statement('CREATE INDEX  IF NOT EXISTS "IX_QuotaRequests_Status_Submitted"          ON "QuotaRequests" ("Status","SubmittedAt")');
        DB::connection(self::CONN)->statement('CREATE INDEX  IF NOT EXISTS "IX_QuotaRequests_RequestId"                 ON "QuotaRequests" ("RequestId")');
        DB::connection(self::CONN)->statement('CREATE UNIQUE INDEX IF NOT EXISTS "UX_QuotaRequests_Idem"                ON "QuotaRequests" ("ResellerId","SubmittedByUserId","IdempotencyKey")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "QuotaRequests"');
    }
};
