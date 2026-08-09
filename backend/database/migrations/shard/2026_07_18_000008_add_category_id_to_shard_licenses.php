<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 38b. Add `LicenseCategoryId` to shard `Licenses`.
 *
 * Unblocks two spec-blocking gaps:
 *   1. `Admin\LicenseController::issue` cannot call
 *      `QuotaService::decrement(resellerId, categoryId, tierId, ...)`
 *      without knowing the category on the license row itself.
 *   2. `Portal\SerialController` was defaulting `CategoryCode` to `K`
 *      because there was no per-license category to resolve; that
 *      default is now explicitly a fallback branch (logged), not the
 *      steady-state path.
 *
 * Ordinals 1..7 mirror `config('lara.license_categories')` and the
 * shard `LicenseCategories` catalog stub from spec/23-app-db/01-schema.md.
 * The CHECK constraint pins the closed set at the DB layer so bad
 * writes fail before the app sees them.
 *
 * Existing rows (if any) are backfilled to `7 (Key)` to match the
 * historical default the app was emitting; the accompanying release
 * notes flag this so operators can reconcile.
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
            'ALTER TABLE "Licenses" ADD COLUMN IF NOT EXISTS "LicenseCategoryId" SMALLINT NULL'
        );
        DB::connection(self::CONN)->statement(
            'UPDATE "Licenses" SET "LicenseCategoryId" = ' . self::CATEGORY_DEFAULT_KEY
            . ' WHERE "LicenseCategoryId" IS NULL'
        );
        DB::connection(self::CONN)->statement(
            'ALTER TABLE "Licenses" ALTER COLUMN "LicenseCategoryId" SET NOT NULL'
        );
        DB::connection(self::CONN)->statement(
            'ALTER TABLE "Licenses" ADD CONSTRAINT "CkLicensesCategoryId"'
            . ' CHECK ("LicenseCategoryId" BETWEEN ' . self::CATEGORY_MIN . ' AND ' . self::CATEGORY_MAX . ')'
        );
        DB::connection(self::CONN)->statement(
            'CREATE INDEX IF NOT EXISTS "IX_Licenses_CategoryId" ON "Licenses" ("LicenseCategoryId")'
        );
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP INDEX IF EXISTS "IX_Licenses_CategoryId"');
        DB::connection(self::CONN)->statement('ALTER TABLE "Licenses" DROP CONSTRAINT IF EXISTS "CkLicensesCategoryId"');
        DB::connection(self::CONN)->statement('ALTER TABLE "Licenses" DROP COLUMN IF EXISTS "LicenseCategoryId"');
    }
};
