<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 40b. Extend `LicenseLedger` with `LicenseCategoryId` and
 * enforce the spec 48 §2 restore idempotency guard at the DB layer.
 *
 * Two changes in one migration because they share the same table and
 * are both required before Reseller-issued flows can write ledger rows
 * (Plan 06 step 35b / Next-Step 2):
 *
 *   1. Add `LicenseCategoryId` SMALLINT NOT NULL (backfilled from
 *      `Licenses.LicenseCategoryId` via `LicenseId` FK). Spec 48 §2
 *      step 3 requires the restore path to resolve the funding
 *      `Quotas` row using the tuple copied from the original ledger
 *      row (not from the current `Licenses` row, which the caller may
 *      have amended between issue and revoke).
 *
 *   2. Partial unique index on
 *      `(LicenseId) WHERE LedgerAction = 'QuotaRestored'` so a retried
 *      revoke cannot write a second `QuotaRestored` row. Spec 48 §2
 *      step 5. The controller catches the resulting unique violation
 *      and translates it to an idempotent replay (`QuotaRestored=true`,
 *      re-reads the existing ledger row).
 *
 * `CkLedgerCategoryId` pins the closed set at the DB layer, matching
 * `CkLicensesCategoryId` from migration 000008 so the two tables stay
 * in lockstep.
 */
return new class extends Migration
{
    private const CONN = 'shard';
    private const CATEGORY_MIN = 1;
    private const CATEGORY_MAX = 7;
    private const CATEGORY_DEFAULT_KEY = 7;

    public function up(): void
    {
        DB::connection(self::CONN)->statement(
            'ALTER TABLE "LicenseLedger" ADD COLUMN IF NOT EXISTS "LicenseCategoryId" SMALLINT NULL'
        );
        // Backfill from the joined Licenses row where possible; otherwise
        // fall back to Key (ordinal 7) matching migration 000008's
        // convention so historical rows satisfy the NOT NULL guard.
        DB::connection(self::CONN)->statement('
            UPDATE "LicenseLedger" ll
            SET "LicenseCategoryId" = COALESCE(l."LicenseCategoryId", ' . self::CATEGORY_DEFAULT_KEY . ')
            FROM "Licenses" l
            WHERE ll."LicenseId" = l."LicenseId"
              AND ll."LicenseCategoryId" IS NULL
        ');
        DB::connection(self::CONN)->statement(
            'UPDATE "LicenseLedger" SET "LicenseCategoryId" = ' . self::CATEGORY_DEFAULT_KEY
            . ' WHERE "LicenseCategoryId" IS NULL'
        );
        DB::connection(self::CONN)->statement(
            'ALTER TABLE "LicenseLedger" ALTER COLUMN "LicenseCategoryId" SET NOT NULL'
        );
        DB::connection(self::CONN)->statement(
            'ALTER TABLE "LicenseLedger" ADD CONSTRAINT "CkLedgerCategoryId"'
            . ' CHECK ("LicenseCategoryId" BETWEEN ' . self::CATEGORY_MIN . ' AND ' . self::CATEGORY_MAX . ')'
        );
        DB::connection(self::CONN)->statement(
            'CREATE INDEX IF NOT EXISTS "IX_LicenseLedger_CategoryId"'
            . ' ON "LicenseLedger" ("LicenseCategoryId")'
        );
        // Spec 48 §2 step 5: one QuotaRestored per LicenseId, ever.
        DB::connection(self::CONN)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "UX_LicenseLedger_RestoreOnce"'
            . ' ON "LicenseLedger" ("LicenseId")'
            . ' WHERE "LedgerAction" = \'QuotaRestored\''
        );
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP INDEX IF EXISTS "UX_LicenseLedger_RestoreOnce"');
        DB::connection(self::CONN)->statement('DROP INDEX IF EXISTS "IX_LicenseLedger_CategoryId"');
        DB::connection(self::CONN)->statement(
            'ALTER TABLE "LicenseLedger" DROP CONSTRAINT IF EXISTS "CkLedgerCategoryId"'
        );
        DB::connection(self::CONN)->statement(
            'ALTER TABLE "LicenseLedger" DROP COLUMN IF EXISTS "LicenseCategoryId"'
        );
    }
};
