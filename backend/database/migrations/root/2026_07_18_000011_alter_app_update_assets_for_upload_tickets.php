<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 45 (schema patch). Adjust `AppUpdateAssets` for the
 * three-phase publish saga.
 *
 * Root cause (one sentence): the initial migration shipped in v0.236.0
 * made `AppUpdateAssets.AppUpdateId` NOT NULL, but spec/21-app/
 * 17-self-update-endpoint.md v1.3.0 §"Publish state machine" line 285
 * requires the asset row to exist BEFORE the parent `AppUpdates` row
 * ("POST /Admin/AppUpdates/UploadTicket ... INSERT AppUpdateAssets ...
 * with IsFinalized = 0" precedes "POST /Admin/AppUpdates ... INSERT
 * AppUpdates"), so orphan asset rows must be legal until finalisation.
 *
 * Changes:
 *  - `AppUpdateId` becomes NULLABLE (orphan until step 3 finalises).
 *  - Add `Product` and `Version` columns so orphan rows can be looked
 *    up by (Product, Version, Platform) at finalise-time, since no
 *    parent row exists yet to key on.
 *  - Enforce `(Product, Version, Platform)` uniqueness on non-final
 *    tickets via a partial unique index; the finalised rows retain
 *    their `(AppUpdateId, Platform)` unique index from migration 10.
 *  - Add `UploadToken` uniqueness so the receiver can look up assets
 *    by opaque token without ambiguity.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('ALTER TABLE "AppUpdateAssets" ALTER COLUMN "AppUpdateId" DROP NOT NULL');
        DB::connection(self::CONN)->statement('ALTER TABLE "AppUpdateAssets" ADD COLUMN IF NOT EXISTS "Product" VARCHAR(64) NULL');
        DB::connection(self::CONN)->statement('ALTER TABLE "AppUpdateAssets" ADD COLUMN IF NOT EXISTS "Version" VARCHAR(64) NULL');
        DB::connection(self::CONN)->statement('CREATE UNIQUE INDEX IF NOT EXISTS "UX_AppUpdateAssets_TicketTriple" ON "AppUpdateAssets" ("Product", "Version", "Platform") WHERE "IsFinalized" = 0');
        DB::connection(self::CONN)->statement('CREATE UNIQUE INDEX IF NOT EXISTS "UX_AppUpdateAssets_UploadToken" ON "AppUpdateAssets" ("UploadToken") WHERE "UploadToken" IS NOT NULL');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP INDEX IF EXISTS "UX_AppUpdateAssets_UploadToken"');
        DB::connection(self::CONN)->statement('DROP INDEX IF EXISTS "UX_AppUpdateAssets_TicketTriple"');
        DB::connection(self::CONN)->statement('ALTER TABLE "AppUpdateAssets" DROP COLUMN IF EXISTS "Version"');
        DB::connection(self::CONN)->statement('ALTER TABLE "AppUpdateAssets" DROP COLUMN IF EXISTS "Product"');
    }
};
