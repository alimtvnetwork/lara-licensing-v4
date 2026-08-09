<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use Tests\TestCase;

/**
 * Plan 06 step 27 (AC-SHARD-001..003).
 *
 * Locks the invariant that `ShardResolver::bind($slug)` clones
 * `database.connections.shard_template` verbatim into
 * `database.connections.shard`, substituting only `{reseller}` inside
 * the `database` DSN component. Other keys (driver, host, port,
 * username, password, sslmode, search_path) must pass through byte for
 * byte so ops can trust the shard connection is the template plus the
 * tenant name, nothing else.
 *
 * Covers:
 *   - Happy path: expanded DSN matches template with placeholder filled.
 *   - Rebind: swapping tenant swaps only the database field.
 *   - Empty slug: LaraException('ResellerNotFound').
 *   - Missing template: LaraException('ServerError').
 */
final class ShardResolverTest extends TestCase
{
    /** @var array<string, mixed> */
    private const TEMPLATE = [
        'driver' => 'pgsql',
        'host' => 'shards.internal',
        'port' => '5432',
        'database' => 'lara_shard_{reseller}',
        'username' => 'lara',
        'password' => 'secret',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'require',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.shard_template', self::TEMPLATE);
        config()->set('database.connections.shard', null);
    }

    public function test_bind_expands_only_the_database_placeholder(): void
    {
        $this->app->make(ShardResolver::class)->bind('acme');
        $resolved = config('database.connections.shard');
        $expected = self::TEMPLATE;
        $expected['database'] = 'lara_shard_acme';
        $this->assertSame($expected, $resolved);
    }

    public function test_rebind_replaces_only_the_database_field(): void
    {
        $resolver = $this->app->make(ShardResolver::class);
        $resolver->bind('acme');
        $resolver->bind('globex');
        $resolved = config('database.connections.shard');
        $expected = self::TEMPLATE;
        $expected['database'] = 'lara_shard_globex';
        $this->assertSame($expected, $resolved);
    }

    public function test_empty_slug_raises_reseller_not_found(): void
    {
        $this->expectException(LaraException::class);
        $this->expectExceptionMessageMatches('/Reseller slug is required/');
        $this->app->make(ShardResolver::class)->bind('');
    }

    public function test_missing_template_raises_server_error(): void
    {
        config()->set('database.connections.shard_template', null);
        $this->expectException(LaraException::class);
        $this->expectExceptionMessageMatches('/Shard template connection missing/');
        $this->app->make(ShardResolver::class)->bind('acme');
    }
}
