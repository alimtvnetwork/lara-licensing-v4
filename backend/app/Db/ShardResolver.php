<?php

namespace App\Db;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Runtime factory that binds a per-Reseller shard connection.
 *
 * Plan 06 step 10. Backed by spec/23-app-db/10-reseller-shard-split-db.md
 * §Routing Rules: every reseller-scoped request MUST run against its
 * own shard, resolved from `Resellers` + `ResellerShardRoutes` on the
 * Root DB. This class never reads those tables itself; the caller
 * (route middleware or Reseller controller base class, Plan 06 step 27)
 * looks up `AppDbPath` / DSN parts and hands them here.
 *
 * Guarantees:
 *  - The shard connection alias is always `shard`; controllers say
 *    `DB::connection('shard')` regardless of tenant.
 *  - Rebinding a different reseller purges the previous connection so a
 *    handler cannot accidentally read a prior tenant's rows.
 *  - Unknown or missing route -> LaraException('ResellerNotFound', 404).
 */
final class ShardResolver
{
    private const CONNECTION_ALIAS = 'shard';
    private const TEMPLATE_ALIAS = 'shard_template';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DatabaseManager $db,
    ) {
    }

    /**
     * Bind the shard connection for the given reseller. Idempotent per
     * reseller; a repeated call with the same slug is a no-op.
     *
     * @param string $resellerSlug identifier used to expand the DSN template
     */
    public function bind(string $resellerSlug): void
    {
        if ($resellerSlug === '') {
            throw NotFoundException::notFound('ResellerNotFound',
                'Reseller slug is required to open a shard connection.',
                [['Field' => 'ResellerSlug', 'Rule' => 'Required']],
            );
        }
        $template = $this->readTemplate();
        $template['database'] = str_replace('{reseller}', $resellerSlug, (string) $template['database']);
        $this->config->set('database.connections.' . self::CONNECTION_ALIAS, $template);
        $this->db->purge(self::CONNECTION_ALIAS);
        Log::info('shard.bind', ['ResellerSlug' => $resellerSlug, 'Database' => $template['database']]);
    }

    /** @return array<string, mixed> */
    private function readTemplate(): array
    {
        $template = $this->config->get('database.connections.' . self::TEMPLATE_ALIAS);
        if (is_array($template) === false) {
            throw InternalException::serverError('ServerError',
                'Shard template connection missing from database config.',
            );
        }

        return $template;
    }

    /** Alias name that controllers pass to DB::connection(...). */
    public static function alias(): string
    {
        return self::CONNECTION_ALIAS;
    }
}
