<?php

namespace App\Console\Commands;

use App\Db\ShardResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 06 step 12. Provision a per-Reseller shard database.
 *
 * Executes the state machine documented in
 * spec/23-app-db/10-reseller-shard-split-db.md §Provisioning Lifecycle:
 *
 *   1. Verify a `Resellers` row exists for the slug and locate the
 *      matching `ResellerShardRoutes` row.
 *   2. Move `ShardStatus` to `Provisioning` (idempotent if already there).
 *   3. `CREATE DATABASE` on the Postgres server named after the DSN
 *      template substitution (harmless if the database already exists).
 *   4. Bind the shard connection through `ShardResolver` and run
 *      migrations from `database/migrations/shard/`. Forward-only per
 *      spec/04-database-conventions/03-orm-and-views.md.
 *   5. On success, set `ShardStatus=Active`, `SchemaVersion=<git ver>`,
 *      `LastMigratedAt=NOW()`, clear `LastError`.
 *   6. On failure, set `ShardStatus=Failed`, record the error in
 *      `LastError` AND in Root `AuditLogs`, and exit non-zero.
 *
 * Retry is idempotent by ResellerId: rerunning on a `Failed` shard is
 * expected and safe. Rerunning on an `Active` shard re-runs pending
 * migrations only (`migrate` is a no-op when the frontier matches).
 *
 * ACs locked: AC-SHARD-001 (one shard per reseller), AC-SHARD-002
 * (retry idempotent), AC-SHARD-003 (failure recorded in Root audit).
 */
final class ShardProvisionCommand extends Command
{
    protected $signature = 'lara:shard:provision {slug : Reseller slug (Resellers.ResellerSlug)}';
    protected $description = 'Create + migrate the App-tier shard for a Reseller.';

    public function handle(ShardResolver $resolver): int
    {
        $slug = (string) $this->argument('slug');
        $route = $this->loadRoute($slug);
        if ($route === null) {
            $this->error("No Resellers row with ResellerSlug={$slug}.");

            return self::FAILURE;
        }

        $this->markProvisioning((int) $route->ResellerShardRouteId);
        try {
            $this->createDatabaseIfMissing($slug);
            $resolver->bind($slug);
            Artisan::call('migrate', [
                '--database' => ShardResolver::alias(),
                '--path' => 'database/migrations/shard',
                '--force' => true,
            ], $this->output);
            $this->markActive((int) $route->ResellerShardRouteId);
            $this->info("Shard for {$slug} is Active.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->markFailed((int) $route->ResellerShardRouteId, $e->getMessage());
            $this->writeRootAudit($slug, $e);
            Log::error('shard.provision.failed', ['ResellerSlug' => $slug, 'Message' => $e->getMessage()]);
            $this->error("Shard for {$slug} failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function loadRoute(string $slug): ?object
    {
        return DB::connection('root')->selectOne('
            SELECT r."ResellerId", rsr."ResellerShardRouteId", rsr."ShardStatus"
              FROM "Resellers" r
              JOIN "ResellerShardRoutes" rsr ON rsr."ResellerId" = r."ResellerId"
             WHERE r."ResellerSlug" = ?
        ', [$slug]);
    }

    private function markProvisioning(int $routeId): void
    {
        DB::connection('root')->update('
            UPDATE "ResellerShardRoutes"
               SET "ShardStatus" = \'Provisioning\', "LastError" = NULL, "UpdatedAt" = NOW()
             WHERE "ResellerShardRouteId" = ?
        ', [$routeId]);
    }

    private function markActive(int $routeId): void
    {
        DB::connection('root')->update('
            UPDATE "ResellerShardRoutes"
               SET "ShardStatus"    = \'Active\',
                   "SchemaVersion"  = ?,
                   "LastMigratedAt" = NOW(),
                   "LastError"      = NULL,
                   "UpdatedAt"      = NOW()
             WHERE "ResellerShardRouteId" = ?
        ', [config('app.version', '0.203.0'), $routeId]);
    }

    private function markFailed(int $routeId, string $message): void
    {
        DB::connection('root')->update('
            UPDATE "ResellerShardRoutes"
               SET "ShardStatus" = \'Failed\', "LastError" = ?, "UpdatedAt" = NOW()
             WHERE "ResellerShardRouteId" = ?
        ', [substr($message, 0, 4000), $routeId]);
    }

    /**
     * `CREATE DATABASE ... IF NOT EXISTS` is not supported by Postgres,
     * so we probe `pg_database` first. The connecting role must have
     * CREATEDB, otherwise this raises and lands in markFailed.
     */
    private function createDatabaseIfMissing(string $slug): void
    {
        $template = config('database.connections.shard_template.database');
        $dbName = str_replace('{reseller}', $slug, (string) $template);
        $exists = DB::connection('root')->selectOne('SELECT 1 AS x FROM pg_database WHERE datname = ?', [$dbName]);
        if ($exists) {
            return;
        }
        // Identifier is composed from a slug already validated by CHECK
        // `^[a-z][a-z0-9-]{2,63}$` plus the config template, both trusted.
        DB::connection('root')->statement('CREATE DATABASE "' . str_replace('"', '', $dbName) . '"');
    }

    private function writeRootAudit(string $slug, Throwable $e): void
    {
        DB::connection('root')->insert('
            INSERT INTO "AuditLogs"
                ("ActorType","ActorId","Action","TargetType","TargetId","RequestId","PayloadJson")
            VALUES
                (\'System\', NULL, \'ShardProvisionFailed\', \'Resellers\', NULL, ?, ?::jsonb)
        ', [
            (string) (request()?->headers->get('X-Request-Id') ?? bin2hex(random_bytes(8))),
            json_encode(['ResellerSlug' => $slug, 'Message' => substr($e->getMessage(), 0, 500)]),
        ]);
    }
}
