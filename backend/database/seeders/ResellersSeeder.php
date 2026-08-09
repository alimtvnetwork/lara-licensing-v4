<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 6 of Plan 20 (v3.3).
 * Domain-specific seeder for Resellers and related Root DB structures.
 */
final class ResellersSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $resellers = [
            ['Name' => 'Acme Corp', 'Slug' => 'acme-corp', 'Email' => 'ops@acme.test', 'Prefix' => 'ACME'],
            ['Name' => 'Globex', 'Slug' => 'globex', 'Email' => 'admin@globex.test', 'Prefix' => 'GLBX'],
            ['Name' => 'Hooli', 'Slug' => 'hooli', 'Email' => 'ceo@hooli.test', 'Prefix' => 'HOLI'],
        ];

        foreach ($resellers as $r) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "Resellers" ("ResellerName","ResellerSlug","ContactEmail") VALUES (?,?,?) ON CONFLICT ("ResellerSlug") DO NOTHING',
                [$r['Name'], $r['Slug'], $r['Email']]
            );
            
            $row = DB::connection(self::CONN_ROOT)->selectOne('SELECT "ResellerId" FROM "Resellers" WHERE "ResellerSlug" = ?', [$r['Slug']]);
            $resellerId = $row->ResellerId;
            
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "ResellerShardRoutes" ("ResellerId","AppDbPath","ShardStatus") VALUES (?,?,\'Active\') ON CONFLICT ("ResellerId") DO NOTHING',
                [$resellerId, 'shard_' . str_replace('-', '_', $r['Slug'])]
            );
            
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "Prefixes" ("ResellerId","PrefixValue") VALUES (?,?) ON CONFLICT ("PrefixValue") DO NOTHING',
                [$resellerId, $r['Prefix']]
            );
        }

        $this->command?->line('  ResellersSeeder: domain populated.');
    }
}
