<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 39 (substrate, binding axis). Shard `MachineBindings`.
 *
 * Root cause this migration addresses (one sentence): `Verify/Final` in
 * `spec/21-app/11-api-contracts/03-verification-contracts.md` v1.3.0
 * cannot honor `LicenseMachineLimit` (409, AC-VAR-001) or return
 * `MachineBindingId` because the substrate table pinned by
 * `spec/21-app/30-machine-bindings.md` §Storage lines 38-51 and
 * `spec/23-app-db/01-schema.md` lines 470-482 does not exist yet.
 *
 * Columns and invariants:
 *  - `MachineBindingId`     BIGSERIAL PK.
 *  - `LicenseId`            FK -> `Licenses`, ON DELETE RESTRICT (audit trail).
 *  - `FingerprintHash`      CHAR(64) hex, canonical form per spec 30
 *                           §"Canonical fingerprint form" lines 25-33.
 *                           CHECK enforces lowercase hex only (AC-MB-001).
 *  - `FirstSeenAt`          TIMESTAMPTZ NOT NULL DEFAULT NOW().
 *  - `LastSeenAt`           TIMESTAMPTZ NOT NULL DEFAULT NOW().
 *  - `ReleasedAt`           TIMESTAMPTZ NULL. NULL while active, never
 *                           cleared once set (AC-MB-007). Set together
 *                           with `RebindCooldownUntil` in one UPDATE
 *                           (AC-MB-005).
 *  - `RebindCooldownUntil`  TIMESTAMPTZ NULL. `NOW() + 15 minutes` at
 *                           unbind (spec 30 §Unbind step 1).
 *  - `CreatedAt` / `UpdatedAt` TIMESTAMPTZ NOT NULL DEFAULT NOW().
 *
 * Indexes:
 *  - UNIQUE `(LicenseId, FingerprintHash) WHERE ReleasedAt IS NULL`:
 *    partial unique index enforces AC-MB-003 (only NULL-released rows
 *    count as concurrent) while allowing post-cooldown re-INSERT of the
 *    SAME hash without mutating the released historical row (AC-MB-007).
 *  - INDEX `(LicenseId, ReleasedAt)`: fast `COUNT(*) WHERE LicenseId=?
 *    AND ReleasedAt IS NULL` quota check per AC-MB-003.
 *  - INDEX `(RebindCooldownUntil)`: cooldown sweep.
 *
 * Not persisted here (AC-MB-001): raw MAC, motherboard serial, CPUID,
 * MachineGuid. Those live in transient request memory only.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "MachineBindings" (
                "MachineBindingId"     BIGSERIAL PRIMARY KEY,
                "LicenseId"            BIGINT NOT NULL
                    REFERENCES "Licenses"("LicenseId") ON DELETE RESTRICT,
                "FingerprintHash"      CHAR(64) NOT NULL
                    CONSTRAINT "CkMachineBindingFingerprintHex"
                    CHECK ("FingerprintHash" ~ \'^[a-f0-9]{64}$\'),
                "FirstSeenAt"          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "LastSeenAt"           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "ReleasedAt"           TIMESTAMPTZ NULL,
                "RebindCooldownUntil"  TIMESTAMPTZ NULL,
                "CreatedAt"            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT "CkMachineBindingReleasedCooldownPair"
                    CHECK (
                        ("ReleasedAt" IS NULL AND "RebindCooldownUntil" IS NULL)
                        OR
                        ("ReleasedAt" IS NOT NULL AND "RebindCooldownUntil" IS NOT NULL)
                    ),
                CONSTRAINT "CkMachineBindingLastSeenAfterFirst"
                    CHECK ("LastSeenAt" >= "FirstSeenAt")
            )
        ');
        // Partial unique: only ACTIVE (unreleased) rows are unique per
        // (LicenseId, FingerprintHash). Post-cooldown re-INSERT lands as
        // a new row (AC-MB-007) because the released row has ReleasedAt
        // IS NOT NULL and is thus outside the index predicate.
        DB::connection(self::CONN)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "UX_MachineBindings_ActivePerLicense" '
            . 'ON "MachineBindings" ("LicenseId","FingerprintHash") '
            . 'WHERE "ReleasedAt" IS NULL'
        );
        DB::connection(self::CONN)->statement(
            'CREATE INDEX IF NOT EXISTS "IX_MachineBindings_LicenseReleased" '
            . 'ON "MachineBindings" ("LicenseId","ReleasedAt")'
        );
        DB::connection(self::CONN)->statement(
            'CREATE INDEX IF NOT EXISTS "IX_MachineBindings_CooldownUntil" '
            . 'ON "MachineBindings" ("RebindCooldownUntil") '
            . 'WHERE "RebindCooldownUntil" IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "MachineBindings"');
    }
};
