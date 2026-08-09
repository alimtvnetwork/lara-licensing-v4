<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 37 pre-req (originally scoped to step 40). Shard DB `Quotas`
 * table (aka `ResellerQuotas`).
 *
 * Root cause for shipping this now: spec 42 v1.1.0 `Approve` requires an
 * atomic UPSERT of `LicensesGranted` on the `(ResellerId, LicenseCategoryId,
 * LicenseTierId, PeriodStart)` primary key alongside the `QuotaRequests`
 * transition and the `LicenseLedger` `QuotaAdjusted` row. Without this table
 * the Admin approve endpoint has nowhere to land its side effect, and any
 * "approve" would be a silent no-op that breaks AC-QR-004.
 *
 * Normative sources:
 *  - spec/21-app/41-reseller-quotas.md v1.0.0 §3 (shape), §4 (decrement contract)
 *  - spec/23-app-db/01-schema.md §ResellerQuotas
 *  - spec/23-app-db/10-reseller-shard-split-db.md §App-tier tables
 *
 * Physical column names are PascalCase per spec 24. All CHECKs mirror the
 * invariants in spec 41 §3 verbatim so DB rejects illegal state before the
 * app layer sees it. `LicensesRemaining` is NOT stored: it is derived at
 * read time (`LicensesGranted - LicensesConsumed`), matching spec 41 §2.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "Quotas" (
                "ResellerId"          BIGINT      NOT NULL,
                "LicenseCategoryId"   SMALLINT    NOT NULL,
                "LicenseTierId"       SMALLINT    NOT NULL,
                "LicensesGranted"     BIGINT      NOT NULL DEFAULT 0,
                "LicensesConsumed"    BIGINT      NOT NULL DEFAULT 0,
                "PeriodStart"         TIMESTAMPTZ NOT NULL,
                "PeriodEnd"           TIMESTAMPTZ NULL,
                "CreatedAt"           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY ("ResellerId","LicenseCategoryId","LicenseTierId","PeriodStart"),
                CONSTRAINT "CkQuotasGrantedNonNegative"
                    CHECK ("LicensesGranted" >= 0),
                CONSTRAINT "CkQuotasConsumedNonNegative"
                    CHECK ("LicensesConsumed" >= 0),
                CONSTRAINT "CkQuotasConsumedWithinGranted"
                    CHECK ("LicensesConsumed" <= "LicensesGranted"),
                CONSTRAINT "CkQuotasPeriodOrder"
                    CHECK ("PeriodEnd" IS NULL OR "PeriodEnd" > "PeriodStart"),
                CONSTRAINT "CkQuotasCategoryOrdinal"
                    CHECK ("LicenseCategoryId" BETWEEN 1 AND 7),
                CONSTRAINT "CkQuotasTierOrdinal"
                    CHECK ("LicenseTierId" BETWEEN 1 AND 4)
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Quotas_Reseller_Active" ON "Quotas" ("ResellerId","PeriodStart") WHERE "PeriodEnd" IS NULL');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Quotas_Reseller_Tuple"  ON "Quotas" ("ResellerId","LicenseCategoryId","LicenseTierId")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "Quotas"');
    }
};
