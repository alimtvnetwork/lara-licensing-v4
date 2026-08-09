<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 1c. Root DB `IdempotencyRecords` BR-scoped indexes and
 * endpoint closed-set enforcement.
 *
 * Normative source: spec/26-backup-restore/16-idempotency-and-locks.md
 * v1.0.0 §"Idempotency Record" (closed set of `endpoint` values:
 * `exports`, `imports`, `snapshots`, `restores`, `jobsCancel`) and
 * §"Replay Matrix" (24h retention, body-hash mismatch handling).
 *
 * Rationale: BR reuses the existing `IdempotencyRecords` table
 * introduced at v0.234.0 (`2026_07_18_000009_create_root_idempotency_records_table.php`)
 * rather than creating a parallel table, per `INV-BR-A` (single source
 * of truth for replay). This migration adds:
 *   1. A partial index on `Endpoint` scoped to BR-owned endpoints so
 *      the retention sweep + replay lookup for BR is O(log n) even
 *      when non-BR endpoints dominate the row count.
 *   2. A CHECK constraint on `Endpoint` values whose prefix is
 *      `backup.` so BR endpoints stay inside the closed set from spec
 *      §"Idempotency Record". Non-BR rows are not affected (the
 *      CHECK ignores rows whose Endpoint does not start with
 *      `backup.`).
 *
 * The constraint uses `NOT VALID` + `VALIDATE CONSTRAINT` so the
 * migration is safe on hot tables; existing non-BR rows pass by
 * construction because they never carry a `backup.` prefix.
 *
 * Reversibility: fully reversible; drops index and constraint.
 */
return new class extends Migration
{
    private const CONN = 'root';

    private const BR_ENDPOINTS = [
        'backup.exports',
        'backup.imports',
        'backup.snapshots',
        'backup.restores',
        'backup.jobsCancel',
    ];

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IxIdempotencyRecordsBackupScope"
                ON "IdempotencyRecords" ("Endpoint", "ExpiresAt")
                WHERE "Endpoint" LIKE \'backup.%\'
        ');

        $inList = implode(',', array_map(
            static fn (string $e): string => "'{$e}'",
            self::BR_ENDPOINTS,
        ));

        DB::connection(self::CONN)->statement(<<<SQL
            ALTER TABLE "IdempotencyRecords"
                DROP CONSTRAINT IF EXISTS "CkIdempotencyRecordsBackupClosedSet"
        SQL);

        DB::connection(self::CONN)->statement(<<<SQL
            ALTER TABLE "IdempotencyRecords"
                ADD CONSTRAINT "CkIdempotencyRecordsBackupClosedSet"
                CHECK (
                    "Endpoint" NOT LIKE 'backup.%'
                    OR "Endpoint" IN ({$inList})
                ) NOT VALID
        SQL);

        DB::connection(self::CONN)->statement(<<<SQL
            ALTER TABLE "IdempotencyRecords"
                VALIDATE CONSTRAINT "CkIdempotencyRecordsBackupClosedSet"
        SQL);
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('
            ALTER TABLE "IdempotencyRecords"
                DROP CONSTRAINT IF EXISTS "CkIdempotencyRecordsBackupClosedSet"
        ');
        DB::connection(self::CONN)->statement('
            DROP INDEX IF EXISTS "IxIdempotencyRecordsBackupScope"
        ');
    }
};
