<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Plan 06 step 21, revised Plan 10 step 9. Demo reseller records for
 * local development.
 *
 * Inserts a demo Reseller + ResellerShardRoute + Prefix into the Root
 * DB so end-to-end shard-provisioning flows have a target to exercise.
 * Guarded by APP_ENV: refuses to run in production per spec/23-app-db
 * §Provisioning Lifecycle, which requires production shards be created
 * through the Admin Console, not seed data.
 *
 * Idempotent: uses ON CONFLICT DO NOTHING keyed on unique slugs and
 * prefix values. Safe to run repeatedly during local iteration.
 *
 * Plan 10 step 9: the initial `ShardStatus` value is now read from
 * `config('lara.shard_statuses.initial')` instead of an inline SQL
 * literal, so the closed set has one source of truth. The seeder
 * asserts the value is a member of the declared set and fails fast
 * with a named message if config drifts from the migration CHECK.
 */
final class ShardSeeder extends Seeder
{
    private const CONN = 'root';

    private const ALLOWED_ENVS = ['local', 'development', 'testing'];

    private const DEMO_RESELLERS = [
        [
            'name'    => 'Demo Reseller One',
            'slug'    => 'demo-one',
            'email'   => 'ops+demo-one@example.test',
            'db_path' => 'shard_demo_one',
            'prefix'  => 'DEMO01',
        ],
        [
            'name'    => 'Demo Reseller Two',
            'slug'    => 'demo-two',
            'email'   => 'ops+demo-two@example.test',
            'db_path' => 'shard_demo_two',
            'prefix'  => 'DEMO02',
        ],
    ];

    public function run(): void
    {
        $env = (string) config('app.env', 'production');
        if (! in_array($env, self::ALLOWED_ENVS, true)) {
            throw new RuntimeException(
                "ShardSeeder: refusing to run under APP_ENV='{$env}'. "
                . 'Demo records are dev-only; use the Admin Console to provision production shards.'
            );
        }

        $initialStatus = $this->resolveInitialStatus();

        foreach (self::DEMO_RESELLERS as $row) {
            $this->seedOne($row, $initialStatus);
        }
    }

    private function resolveInitialStatus(): string
    {
        $members = config('lara.shard_statuses.members');
        $initial = config('lara.shard_statuses.initial');
        if (! is_array($members) || $members === [] || ! is_string($initial) || $initial === '') {
            throw new RuntimeException(
                "ShardSeeder: config('lara.shard_statuses.initial' | '.members') must be non-empty."
            );
        }
        if (! in_array($initial, $members, true)) {
            throw new RuntimeException(
                "ShardSeeder: initial status '{$initial}' is not a declared shard_statuses member."
            );
        }

        return $initial;
    }

    /**
     * @param  array{name:string,slug:string,email:string,db_path:string,prefix:string}  $row
     */
    private function seedOne(array $row, string $initialStatus): void
    {
        DB::connection(self::CONN)->statement(
            'INSERT INTO "Resellers" ("ResellerName","ResellerSlug","ContactEmail")
             VALUES (?,?,?) ON CONFLICT ("ResellerSlug") DO NOTHING',
            [$row['name'], $row['slug'], $row['email']],
        );

        $resellerId = DB::connection(self::CONN)->selectOne(
            'SELECT "ResellerId" FROM "Resellers" WHERE "ResellerSlug" = ?',
            [$row['slug']],
        )?->ResellerId;

        if ($resellerId === null) {
            throw new RuntimeException("ShardSeeder: Reseller '{$row['slug']}' missing after insert.");
        }

        DB::connection(self::CONN)->statement(
            'INSERT INTO "ResellerShardRoutes" ("ResellerId","AppDbPath","ShardStatus")
             VALUES (?,?,?) ON CONFLICT ("ResellerId") DO NOTHING',
            [$resellerId, $row['db_path'], $initialStatus],
        );

        DB::connection(self::CONN)->statement(
            'INSERT INTO "Prefixes" ("ResellerId","PrefixValue")
             VALUES (?,?) ON CONFLICT ("PrefixValue") DO NOTHING',
            [$resellerId, $row['prefix']],
        );

        $this->command?->line(
            "  demo reseller seeded: {$row['slug']} (prefix {$row['prefix']}, status {$initialStatus})"
        );
    }
}
