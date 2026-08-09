<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 18 of Plan 20 (v3.3).
 * Domain-specific seeder for Api Keys (Root DB).
 */
final class ApiKeysSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $resellers = DB::connection(self::CONN_ROOT)->select('SELECT "ResellerId" FROM "Resellers" LIMIT 2');
        
        foreach ($resellers as $reseller) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "ApiKeys" ("ResellerId", "KeyId", "KeyHash", "Description", "IsActive") 
                 VALUES (?, ?, ?, ?, TRUE)',
                [$reseller->ResellerId, "ak_" . bin2hex(random_bytes(4)), hash('sha256', 'test_key'), "E2E Test Key", true]
            );
        }

        $this->command?->line('  ApiKeysSeeder: domain populated.');
    }
}
