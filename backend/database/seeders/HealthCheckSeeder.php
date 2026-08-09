<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Db\ShardResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 17 of Plan 20 (v3.3).
 * Domain-specific seeder for Environment health status.
 */
final class HealthCheckSeeder extends Seeder
{
    private const CONN_ROOT = 'root';
    private const CONN_SHARD = 'shard';

    public function run(): void
    {
        $resellers = DB::connection(self::CONN_ROOT)->select('SELECT "ResellerId", "ResellerSlug" FROM "Resellers" LIMIT 2');
        
        foreach ($resellers as $reseller) {
            try {
                app(ShardResolver::class)->bind($reseller->ResellerSlug);
            } catch (\Throwable $e) {
                continue;
            }

            $this->seedHealth((int)$reseller->ResellerId);
        }

        $this->command?->line('  HealthCheckSeeder: domain populated.');
    }

    private function seedHealth(int $resellerId): void
    {
        $environments = ['Production', 'Staging', 'Dev'];
        foreach ($environments as $env) {
            DB::connection(self::CONN_SHARD)->statement(
                'INSERT INTO "EnvironmentHealth" ("ResellerId", "EnvironmentName", "Status", "LastCheckedAt") 
                 VALUES (?, ?, ?, NOW()) ON CONFLICT DO NOTHING',
                [$resellerId, $env, 'Healthy']
            );
        }
    }
}
