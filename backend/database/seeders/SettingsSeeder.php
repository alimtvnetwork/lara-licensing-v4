<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 13 of Plan 20 (v3.3).
 * Populates global and reseller-specific settings.
 */
final class SettingsSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $this->seedGlobalSettings();
        $this->seedResellerSettings();

        $this->command?->line('  SettingsSeeder: domain populated.');
    }

    private function seedGlobalSettings(): void
    {
        $settings = [
            ['Key' => 'System.MaintenanceMode', 'Value' => 'false'],
            ['Key' => 'System.DefaultTier', 'Value' => 'Tier1'],
            ['Key' => 'Auth.TokenExpiryMinutes', 'Value' => '60'],
        ];

        foreach ($settings as $s) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "GlobalSettings" ("SettingKey", "SettingValue") VALUES (?, ?) ON CONFLICT ("SettingKey") DO NOTHING',
                [$s['Key'], $s['Value']]
            );
        }
    }

    private function seedResellerSettings(): void
    {
        $resellers = DB::connection(self::CONN_ROOT)->select('SELECT "ResellerId" FROM "Resellers" LIMIT 2');
        foreach ($resellers as $reseller) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "ResellerSettings" ("ResellerId", "SettingKey", "SettingValue") VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                [$reseller->ResellerId, 'Portal.Theme', 'Modern']
            );
        }
    }
}
