<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 44 (substrate). Root `AppUpdates` + `AppUpdateAssets`.
 *
 * Normative: spec/21-app/17-self-update-endpoint.md v1.3.0 §"Database
 * bindings" + §"Publish state machine" + §"Admin invariants". These are
 * cross-cutting (every reseller's CLI resolves the same manifest) so
 * they live on Root, not on a shard.
 *
 * Schema notes (spec §"Publish state machine"):
 *  - `AppUpdateAssets.IsFinalized` starts at 0 after `POST UploadTicket`
 *    and transitions to 1 only inside the `POST /Admin/AppUpdates`
 *    transaction that materialises the manifest row. Manifest reads
 *    filter `IsFinalized = 1 AND IsYanked = 0`.
 *  - `(Product, Channel, Version)` is unique on `AppUpdates`
 *    (spec §"Admin invariants" §5: version monotonicity + spec
 *    §"Concurrency": duplicate publishes serialise on this index).
 *  - `(AppUpdateId, Platform)` is unique on `AppUpdateAssets`
 *    (spec §"Concurrency": two admins uploading the same platform
 *    for one version conflict on this index).
 *  - `UploadTicketExpiresAt` supports the retention sweep in
 *    spec §"Upload ticket expiry" (60-minute default per spec 17).
 *  - `PublishedByUserId` / `YankedByUserId` satisfy spec §"Admin
 *    invariants" §3 (actor stamping); FK is Restrict so an operator
 *    row cannot vanish while their published version is live.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        $this->createAppUpdates();
        $this->createAppUpdateAssets();
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "AppUpdateAssets"');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "AppUpdates"');
    }

    private function createAppUpdates(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "AppUpdates" (
                "AppUpdateId"         BIGSERIAL    PRIMARY KEY,
                "Product"             VARCHAR(64)  NOT NULL,
                "Channel"             VARCHAR(16)  NOT NULL
                    CHECK ("Channel" IN (\'Stable\',\'Beta\')),
                "Version"             VARCHAR(64)  NOT NULL,
                "MinRequiredVersion"  VARCHAR(64)  NOT NULL,
                "ReleaseNotesUrl"     VARCHAR(512) NULL,
                "PublishedAt"         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "PublishedByUserId"   BIGINT       NOT NULL REFERENCES "Users"("UserId") ON DELETE RESTRICT,
                "IsYanked"            SMALLINT     NOT NULL DEFAULT 0
                    CHECK ("IsYanked" IN (0,1)),
                "YankedAt"            TIMESTAMPTZ  NULL,
                "YankedByUserId"      BIGINT       NULL REFERENCES "Users"("UserId") ON DELETE RESTRICT,
                CONSTRAINT "UX_AppUpdates_ProductChannelVersion"
                    UNIQUE ("Product", "Channel", "Version"),
                CONSTRAINT "CK_AppUpdates_YankedPaired"
                    CHECK (
                        ("IsYanked" = 0 AND "YankedAt" IS NULL AND "YankedByUserId" IS NULL)
                        OR ("IsYanked" = 1 AND "YankedAt" IS NOT NULL AND "YankedByUserId" IS NOT NULL)
                    )
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_AppUpdates_ChannelPublishedAt" ON "AppUpdates" ("Channel", "PublishedAt" DESC)');
    }

    private function createAppUpdateAssets(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "AppUpdateAssets" (
                "AppUpdateAssetId"        BIGSERIAL    PRIMARY KEY,
                "AppUpdateId"             BIGINT       NOT NULL REFERENCES "AppUpdates"("AppUpdateId") ON DELETE CASCADE,
                "Platform"                VARCHAR(32)  NOT NULL
                    CHECK ("Platform" IN (\'WindowsAmd64\',\'LinuxAmd64\',\'DarwinArm64\')),
                "SizeBytes"               BIGINT       NOT NULL CHECK ("SizeBytes" > 0),
                "Sha256"                  CHAR(64)     NOT NULL,
                "SignatureSha256"         CHAR(64)     NULL,
                "StoragePath"             VARCHAR(512) NULL,
                "SignatureStoragePath"    VARCHAR(512) NULL,
                "IsFinalized"             SMALLINT     NOT NULL DEFAULT 0
                    CHECK ("IsFinalized" IN (0,1)),
                "UploadToken"             VARCHAR(128) NULL,
                "UploadTicketExpiresAt"   TIMESTAMPTZ  NULL,
                "CreatedAt"               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "FinalizedAt"             TIMESTAMPTZ  NULL,
                CONSTRAINT "UX_AppUpdateAssets_UpdatePlatform"
                    UNIQUE ("AppUpdateId", "Platform")
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_AppUpdateAssets_TicketExpires" ON "AppUpdateAssets" ("UploadTicketExpiresAt") WHERE "IsFinalized" = 0');
    }
};
