<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Db\ShardResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 15 of Plan 20 (v3.3).
 * Domain-specific seeder for Platform Statistics (shard-scoped).
 */
final class PlatformStatsSeeder extends Seeder
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

            $this->seedStats((int)$reseller->ResellerId);
        }

        $this->command?->line('  PlatformStatsSeeder: domain populated.');
    }

    private function seedStats(int $resellerId): void
    {
        $platforms = ['WindowsAmd64', 'LinuxAmd64', 'DarwinArm64'];
        foreach ($platforms as $platform) {
            DB::connection(self::CONN_SHARD)->statement(
                'INSERT INTO "PlatformStats" ("ResellerId", "PlatformName", "ActiveUsers", "CreatedAt") 
                 VALUES (?, ?, ?, NOW())',
                [$resellerId, $platform, rand(10, 100)]
            );
        }
    }
}
