<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 14 of Plan 20 (v3.3).
 * Domain-specific seeder for Product Catalog (spec 17).
 */
final class ProductCatalogSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $products = [
            ['Code' => 'lara-cli', 'Name' => 'Lara CLI', 'Description' => 'Primary command line interface'],
            ['Code' => 'lara-sdk', 'Name' => 'Lara SDK', 'Description' => 'Software Development Kit'],
        ];

        foreach ($products as $p) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "Products" ("ProductCode", "ProductName", "Description") VALUES (?, ?, ?) ON CONFLICT ("ProductCode") DO NOTHING',
                [$p['Code'], $p['Name'], $p['Description']]
            );
        }

        $this->command?->line('  ProductCatalogSeeder: domain populated.');
    }
}
