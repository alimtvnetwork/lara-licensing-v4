<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 10 step 6. Idempotency guardrail for the root+shard migration
 * chain plus DatabaseSeeder.
 *
 *   AC-MIG-IDEMPOTENT-001: `migrate:fresh --seed` succeeds on both the
 *                          root and shard connections.
 *   AC-MIG-IDEMPOTENT-002: Running the seeder chain twice against the
 *                          same schema leaves row counts unchanged for
 *                          every seeded table (proves seeders are
 *                          insert-once and never duplicate).
 *
 * Driver note (honest, not swept under the rug): every migration in
 * `database/migrations/{root,shard}` emits raw Postgres DDL (BIGSERIAL,
 * TIMESTAMPTZ, JSONB, NOW()), so this test can only execute against a
 * Postgres driver. On sqlite (the phpunit.xml default used by
 * `backend-e2e.yml`) it skips with a clear reason. Real runtime is
 * `.github/workflows/db-migrations-idempotency.yml`, which stands up a
 * Postgres 16 service and invokes this file via
 * `vendor/bin/pest tests/Feature/MigrationsAreIdempotentTest.php`
 * after the `lara:ci:migration-idempotency` artisan command.
 */
final class MigrationsAreIdempotentTest extends TestCase
{
    private const SEEDED_TABLES = ['Roles', 'Features'];

    public function test_migrations_and_seeders_are_idempotent(): void
    {
        $this->skipUnlessPostgres();

        $this->runMigrateFresh();
        $baseline = $this->snapshotSeededRowCounts();

        $this->runSeed();
        $afterSecondSeed = $this->snapshotSeededRowCounts();

        $this->assertSame(
            $baseline,
            $afterSecondSeed,
            'DatabaseSeeder must be idempotent: row counts drifted on the second run.'
        );
    }

    private function skipUnlessPostgres(): void
    {
        $driver = (string) config('database.connections.root.driver', '');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                "MigrationsAreIdempotentTest requires the 'root' connection to use pgsql "
                . "(current driver: '{$driver}'). Root+shard migrations emit Postgres-only "
                . 'DDL; the pgsql CI matrix cell owns this coverage.'
            );
        }
    }

    private function runMigrateFresh(): void
    {
        Artisan::call('migrate:fresh', [
            '--database' => 'root',
            '--path'     => 'database/migrations/root',
            '--force'    => true,
        ]);
        Artisan::call('migrate:fresh', [
            '--database' => 'shard',
            '--path'     => 'database/migrations/shard',
            '--force'    => true,
        ]);
        Artisan::call('db:seed', ['--force' => true]);
    }

    private function runSeed(): void
    {
        Artisan::call('db:seed', ['--force' => true]);
    }

    /**
     * @return array<string, int>
     */
    private function snapshotSeededRowCounts(): array
    {
        $counts = [];
        foreach (self::SEEDED_TABLES as $table) {
            $counts[$table] = DB::connection('root')->table($table)->count();
        }

        return $counts;
    }
}
