<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Plan 10 step 7. Root aggregator.
 *
 * Composes the seeder chain in a fixed order so a fresh DB reaches the
 * documented baseline with a single `db:seed` invocation:
 *
 *   1. RootSeeder          - Roles catalog + delegated FeatureCatalog.
 *   2. FeatureCatalogSeeder - Feature registry parity (safe re-run).
 *   3. ClosedSetsSeeder    - Category, Tier, Environment enums (step 10).
 *   4. RolesSeeder         - user_roles enum parity (step 11).
 *   5. ShardSeeder         - dev-only demo shard fixtures.
 *   6. E2EFixturesSeeder   - test-env demo License/Serial (step 12).
 *
 * Every seeder in the chain is idempotent (`ON CONFLICT DO NOTHING` /
 * `updateOrCreate`) per Plan 10 step 6 (`MigrationsAreIdempotentTest`),
 * so a double `db:seed` is a no-op after the first pass. Members that
 * do not yet exist on disk are skipped with a log line so this file
 * lands ahead of steps 10-12 without breaking the current suite; the
 * skip is loud, never silent.
 */
final class DatabaseSeeder extends Seeder
{
    /** @var list<class-string<Seeder>|string> */
    private const CHAIN = [
        RootSeeder::class,
        FeatureCatalogSeeder::class,
        'Database\\Seeders\\ClosedSetsSeeder',
        'Database\\Seeders\\RolesSeeder',
        ShardSeeder::class,
        'Database\\Seeders\\E2EFixturesSeeder',
    ];

    public function run(): void
    {
        foreach (self::CHAIN as $seeder) {
            $this->callOrSkip($seeder);
        }
    }

    private function callOrSkip(string $seeder): void
    {
        if (! class_exists($seeder)) {
            $msg = "DatabaseSeeder: skipping {$seeder} (not yet implemented).";
            Log::info($msg);
            $this->command?->line("  {$msg}");

            return;
        }
        $this->command?->line("  DatabaseSeeder -> {$seeder}");
        $this->call($seeder);
    }
}
