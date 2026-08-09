<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 16. Shard DB `Serials` table.
 *
 * Per spec/23-app-db/01-schema.md §Serials and spec/21-app/11-api-contracts
 * (portal serial issuance): each Serial is bound 1:1 to a device on an issued
 * License. Idempotency for `POST /Api/Portal/Serials` is enforced by a UNIQUE
 * index on (`LicenseId`, `DeviceIdHash`) so retries of the same request
 * resolve to the same row rather than minting a new one. `SerialValue` is
 * shard-scoped UNIQUE; cross-shard uniqueness derives from the Root
 * `Prefixes` registry (Plan 06 step 12).
 *
 * `EnvironmentName` is denormalized from the parent License so the verify
 * handshake does not cross-join Root at read time; it MUST match the parent
 * `Licenses.EnvironmentName` at INSERT (enforced by app layer, see
 * Portal\SerialController@issue). `FeaturePayloadHash` caches the resolved
 * precedence output (Tier + License override, spec 45) so verify responses
 * are byte-stable across retries.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "Serials" (
                "SerialId"           BIGSERIAL PRIMARY KEY,
                "LicenseId"          BIGINT NOT NULL
                    REFERENCES "Licenses"("LicenseId") ON DELETE RESTRICT,
                "SerialValue"        VARCHAR(64) NOT NULL UNIQUE
                    CHECK ("SerialValue" ~ \'^[A-Z0-9-]{8,64}$\'),
                "DeviceIdHash"       CHAR(64) NOT NULL
                    CHECK ("DeviceIdHash" ~ \'^[a-f0-9]{64}$\'),
                "EnvironmentName"    VARCHAR(16) NOT NULL
                    CHECK ("EnvironmentName" IN (\'Production\',\'Staging\',\'Development\')),
                "FeaturePayloadHash" CHAR(64) NULL
                    CHECK ("FeaturePayloadHash" IS NULL OR "FeaturePayloadHash" ~ \'^[a-f0-9]{64}$\'),
                "IdempotencyKey"     VARCHAR(128) NOT NULL,
                "IsRevoked"          BOOLEAN NOT NULL DEFAULT FALSE,
                "IssuedAt"           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "LastVerifiedAt"     TIMESTAMPTZ NULL,
                "CreatedAt"          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        // 1:1 device binding: retry of the same (License, Device) MUST hit
        // the same row (portal serial idempotency invariant, spec 29 v1.0.0).
        DB::connection(self::CONN)->statement('CREATE UNIQUE INDEX IF NOT EXISTS "UX_Serials_License_Device" ON "Serials" ("LicenseId","DeviceIdHash")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Serials_LicenseId"      ON "Serials" ("LicenseId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Serials_IsRevoked"      ON "Serials" ("IsRevoked")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Serials_LastVerifiedAt" ON "Serials" ("LastVerifiedAt")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "Serials"');
    }
};
