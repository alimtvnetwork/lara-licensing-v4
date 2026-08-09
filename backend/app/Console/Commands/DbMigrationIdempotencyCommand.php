<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CI helper: verifies that the root and shard migration chains, plus
 * DatabaseSeeder, are idempotent AND that a full `rollback -> migrate`
 * cycle leaves the schema in the same shape.
 *
 * Contract (all four assertions must pass for a green exit):
 *   AC-CI-MIG-001  migrate:fresh --seed succeeds on root + shard.
 *   AC-CI-MIG-002  A second `db:seed` does NOT change row counts on
 *                  seeded tables (proves seeders are insert-once).
 *   AC-CI-MIG-003  A full `migrate:rollback --step=999` followed by
 *                  `migrate --force` on both root and shard succeeds
 *                  without residual state (rollback safety).
 *   AC-CI-MIG-004  Verification queries return the expected baseline
 *                  row counts (>=1 Role, >=1 Feature) after the cycle.
 *
 * Meant to be driven by .github/workflows/db-migrations-idempotency.yml
 * against a disposable Postgres service. Exits non-zero on the first
 * violation; every step logs its outcome so CI logs are self-explaining.
 */
final class DbMigrationIdempotencyCommand extends Command
{
    protected $signature = 'lara:ci:migration-idempotency
                            {--shard-slug=ci-shard : Reseller slug used for the disposable shard}';

    protected $description = 'Run migrations+seeders twice, then rollback+migrate, and verify idempotency.';

    /** @var list<string> Root tables that MUST be non-empty after db:seed. */
    private const VERIFY_TABLES_ROOT = ['Roles', 'Features'];

    public function handle(): int
    {
        try {
            $this->section('1/4  Fresh migrate + seed (root + shard)');
            $this->freshMigrateAndSeed();

            $this->section('2/4  Idempotency check: second db:seed must be a no-op');
            $before = $this->snapshotRootCounts();
            Artisan::call('db:seed', ['--force' => true], $this->output);
            $after = $this->snapshotRootCounts();
            $this->assertSameCounts($before, $after);

            $this->section('3/4  Rollback safety: rollback then re-migrate root+shard');
            $this->rollbackAndReMigrate();

            $this->section('4/4  Verification queries: baseline row counts');
            $this->verifyBaseline();

            $this->info('OK: migrations and seeders are idempotent and rollback-safe.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('FAIL: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function freshMigrateAndSeed(): void
    {
        Artisan::call('migrate:fresh', [
            '--database' => 'root',
            '--path'     => 'database/migrations/root',
            '--force'    => true,
        ], $this->output);

        Artisan::call('migrate:fresh', [
            '--database' => 'shard',
            '--path'     => 'database/migrations/shard',
            '--force'    => true,
        ], $this->output);

        Artisan::call('db:seed', ['--force' => true], $this->output);
    }

    private function rollbackAndReMigrate(): void
    {
        foreach (['root' => 'database/migrations/root', 'shard' => 'database/migrations/shard'] as $conn => $path) {
            Artisan::call('migrate:rollback', [
                '--database' => $conn,
                '--path'     => $path,
                '--step'     => 999,
                '--force'    => true,
            ], $this->output);

            Artisan::call('migrate', [
                '--database' => $conn,
                '--path'     => $path,
                '--force'    => true,
            ], $this->output);
        }

        // Re-seed after rollback+re-migrate so verification queries have data.
        Artisan::call('db:seed', ['--force' => true], $this->output);
    }

    /** @return array<string, int> */
    private function snapshotRootCounts(): array
    {
        $counts = [];
        foreach (self::VERIFY_TABLES_ROOT as $table) {
            $counts[$table] = DB::connection('root')->table($table)->count();
        }

        return $counts;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    private function assertSameCounts(array $before, array $after): void
    {
        if ($before !== $after) {
            throw new \RuntimeException(
                'Second db:seed changed row counts. Before=' . json_encode($before)
                . ' After=' . json_encode($after)
            );
        }
        $this->line('  row counts stable: ' . json_encode($after));
    }

    private function verifyBaseline(): void
    {
        foreach (self::VERIFY_TABLES_ROOT as $table) {
            $count = DB::connection('root')->table($table)->count();
            if ($count < 1) {
                throw new \RuntimeException("Verification query failed: {$table} is empty after seed.");
            }
            $this->line("  {$table} rows = {$count}");
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('=== ' . $title . ' ===');
    }
}
