<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Db\ShardResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 10 of Plan 20 (v3.3).
 * Domain-specific seeder for Service Usage and Latency metrics.
 */
final class ServiceUsageSeeder extends Seeder
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

            $this->seedUsage((int)$reseller->ResellerId);
        }

        $this->command?->line('  ServiceUsageSeeder: domain populated.');
    }

    private function seedUsage(int $resellerId): void
    {
        $services = ['Auth', 'LicenseCheck', 'Heartbeat', 'Update'];
        
        foreach ($services as $service) {
            for ($i = 24; $i >= 0; $i--) {
                $hour = now()->subHours($i)->format('Y-m-d H:00:00');
                DB::connection(self::CONN_SHARD)->statement(
                    'INSERT INTO "ServiceUsage" ("ResellerId", "ServiceName", "UsageCount", "AvgLatencyMs", "RecordedAt") 
                     VALUES (?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
                    [$resellerId, $service, rand(50, 200), rand(10, 150), $hour]
                );
            }
        }
    }
}
