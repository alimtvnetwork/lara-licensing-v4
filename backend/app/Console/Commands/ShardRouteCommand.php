<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Db\ShardResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Plan 06 step 23. Print the resolved shard DSN for a Reseller.
 *
 * Read-only diagnostic used by health checks and by operators verifying
 * that Root routing (`Resellers` + `ResellerShardRoutes`) is consistent
 * with the DSN template in `config/database.connections.shard_template`.
 * Per spec/23-app-db/10-reseller-shard-split-db.md §Routing Rules the
 * template is expanded with the `ResellerSlug` and MUST match the
 * `AppDbPath` recorded on the route row; a mismatch means Root and the
 * config file have drifted and is reported as a hard failure.
 *
 * Argument accepts either the numeric ResellerId or the ResellerSlug so
 * operators can copy either identifier from Admin views. Output prints
 * one `key=value` pair per line to keep the command grep-friendly for
 * shell scripts. Password/host secrets are never printed; only
 * `database`, `host`, `port`, `AppDbPath`, `ShardStatus`, and
 * `MatchesTemplate` appear. AC-SHARD-004 (route diagnostic is
 * secret-safe) is locked here.
 */
final class ShardRouteCommand extends Command
{
    protected $signature = 'lara:shard:route {reseller : ResellerId or ResellerSlug}';
    protected $description = 'Print the resolved shard DSN for a Reseller.';

    private const KEY_DATABASE = 'database';
    private const KEY_HOST = 'host';
    private const KEY_PORT = 'port';
    private const TEMPLATE_TOKEN = '{reseller}';

    public function handle(): int
    {
        try {
            $row = $this->loadReseller((string) $this->argument('reseller'));
            if ($row === null) {
                $this->error('No Reseller found for the given identifier.');

                return self::FAILURE;
            }
            $this->printRoute($row);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('shard.route.failed: ' . $e->getMessage());
            report($e);

            return self::FAILURE;
        }
    }

    private function loadReseller(string $identifier): ?object
    {
        $sql = 'SELECT r."ResellerId", r."ResellerSlug", rsr."AppDbPath", rsr."ShardStatus"
                  FROM "Resellers" r
                  JOIN "ResellerShardRoutes" rsr ON rsr."ResellerId" = r."ResellerId"
                 WHERE r."ResellerSlug" = ? OR r."ResellerId"::text = ?';

        return DB::connection('root')->selectOne($sql, [$identifier, $identifier]);
    }

    private function printRoute(object $row): void
    {
        $template = $this->readTemplate();
        $expected = str_replace(self::TEMPLATE_TOKEN, (string) $row->ResellerSlug, (string) $template[self::KEY_DATABASE]);
        $matches = $expected === (string) $row->AppDbPath;
        $this->line('ResellerId=' . $row->ResellerId);
        $this->line('ResellerSlug=' . $row->ResellerSlug);
        $this->line('ShardStatus=' . $row->ShardStatus);
        $this->line('AppDbPath=' . $row->AppDbPath);
        $this->line(self::KEY_DATABASE . '=' . $expected);
        $this->line(self::KEY_HOST . '=' . (string) ($template[self::KEY_HOST] ?? ''));
        $this->line(self::KEY_PORT . '=' . (string) ($template[self::KEY_PORT] ?? ''));
        $this->line('MatchesTemplate=' . ($matches ? 'true' : 'false'));
        $this->line('ConnectionAlias=' . ShardResolver::alias());
    }

    /** @return array<string, mixed> */
    private function readTemplate(): array
    {
        $template = config('database.connections.shard_template');
        if (is_array($template) === false) {
            throw new \RuntimeException('database.connections.shard_template missing or not an array.');
        }

        return $template;
    }
}
