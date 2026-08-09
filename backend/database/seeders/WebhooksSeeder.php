<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 16 of Plan 20 (v3.3).
 * Domain-specific seeder for Webhook Configurations.
 */
final class WebhooksSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $resellers = DB::connection(self::CONN_ROOT)->select('SELECT "ResellerId" FROM "Resellers" LIMIT 2');
        
        foreach ($resellers as $reseller) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "Webhooks" ("ResellerId", "Url", "EventMask", "IsActive", "Secret") 
                 VALUES (?, ?, ?, TRUE, ?)',
                [$reseller->ResellerId, "https://api.reseller-{$reseller->ResellerId}.test/webhook", '*', "secret_" . bin2hex(random_bytes(8))]
            );
        }

        $this->command?->line('  WebhooksSeeder: domain populated.');
    }
}
