<?php

declare(strict_types=1);

use App\Db\ShardResolver;
use App\Http\Middleware\ShardBindingMiddleware;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Pest feature suite: reseller tenant isolation at the shard-binding seam.
 *
 * Locks (spec 23-app-db/10 §Routing Rules, spec 21-app/04-roles.md
 * §Reseller row-scope, Plan 06 step 27):
 *
 *   AC-SHARD-011: A Reseller-role caller with TenantId=A hitting any
 *     `/Api/Reseller/*` route rebinds the `shard` connection to
 *     Reseller A's slug and exposes ResellerId=A on request attributes.
 *
 *   AC-SHARD-012: A Reseller-role caller with TenantId=B hitting the
 *     same handler in an interleaved request rebinds to Reseller B's
 *     slug (no leak of the previous tenant's binding across requests).
 *
 *   AC-SHARD-013: A caller whose Users.TenantId is null / 0 (Root-only
 *     staff) hitting a reseller route is rejected with 403 AuthForbidden
 *     and RequiredScope=ResellerTenant. The shard connection MUST NOT
 *     be bound in this case.
 *
 *   AC-SHARD-014: A caller whose TenantId points to a non-existent
 *     Reseller row is rejected with 404 ResellerNotFound. Binding
 *     MUST NOT run.
 *
 * The test intercepts ShardResolver via a spy subclass so we do not
 * need a real shard database. What we care about here is the middleware
 * contract: which slug did we bind, in what order, and did we bind at
 * all when the caller was unauthorised.
 */

/**
 * Fake user carrying a TenantId column, matching the shape
 * ShardBindingMiddleware expects (see Middleware/ShardBindingMiddleware.php:67).
 */
final class PestShardUser extends AuthUser
{
    public int $TenantId = 0;
}

/**
 * Spy resolver. Captures every bind() call in-order without touching
 * the config or any real DB. Extends the real ShardResolver so the
 * container swap is type-compatible.
 */
final class PestSpyShardResolver extends ShardResolver
{
    /** @var list<string> */
    public static array $binds = [];

    public function __construct() {}

    public function bind(string $resellerSlug): void
    {
        self::$binds[] = $resellerSlug;
    }
}

beforeEach(function (): void {
    PestSpyShardResolver::$binds = [];
    $this->app->instance(ShardResolver::class, new PestSpyShardResolver());

    DB::connection('root')->statement('DROP TABLE IF EXISTS "Resellers"');
    DB::connection('root')->statement(
        'CREATE TABLE "Resellers" (
            "ResellerId" INTEGER PRIMARY KEY,
            "ResellerSlug" TEXT NOT NULL UNIQUE
        )'
    );
    DB::connection('root')->table('Resellers')->insert([
        ['ResellerId' => 10, 'ResellerSlug' => 'acme'],
        ['ResellerId' => 20, 'ResellerSlug' => 'globex'],
    ]);

    // Probe route protected only by the shard-binding middleware. We
    // deliberately skip auth:sanctum + require.role here: authentication
    // failure is covered by RbacEnforcementPest; this suite isolates the
    // shard-binding behaviour under an already-authenticated user.
    Route::middleware([ShardBindingMiddleware::class])
        ->get('/api/pest-shard-probe', function (\Illuminate\Http\Request $r) {
            return response()->json([
                'ResellerId' => $r->attributes->get('ResellerId'),
                'ResellerSlug' => $r->attributes->get('ResellerSlug'),
            ]);
        });
});

it('binds the caller shard to their own tenant slug', function (): void {
    $userA = new PestShardUser();
    $userA->id = 100;
    $userA->TenantId = 10;

    $res = $this->actingAs($userA)->getJson('/api/pest-shard-probe');

    $res->assertOk();
    expect($res->json('Results.0.ResellerId'))->toBe(10);
    expect($res->json('Results.0.ResellerSlug'))->toBe('acme');
    expect(PestSpyShardResolver::$binds)->toBe(['acme']);
});

it('rebinds to the second tenant on the next request without leaking the first slug', function (): void {
    $userA = new PestShardUser();
    $userA->id = 100;
    $userA->TenantId = 10;
    $userB = new PestShardUser();
    $userB->id = 200;
    $userB->TenantId = 20;

    $this->actingAs($userA)->getJson('/api/pest-shard-probe')->assertOk();
    $second = $this->actingAs($userB)->getJson('/api/pest-shard-probe');

    $second->assertOk();
    expect($second->json('Results.0.ResellerId'))->toBe(20);
    expect($second->json('Results.0.ResellerSlug'))->toBe('globex');
    // Order matters: each request must rebind. A single-element trace
    // here would mean the second request reused the first binding.
    expect(PestSpyShardResolver::$binds)->toBe(['acme', 'globex']);
});

it('rejects Root-only staff hitting a reseller route with 403 AuthForbidden and never binds a shard', function (): void {
    $staff = new PestShardUser();
    $staff->id = 300;
    $staff->TenantId = 0;

    $res = $this->actingAs($staff)->getJson('/api/pest-shard-probe');

    $res->assertStatus(403);
    expect($res->json('Attributes.Error.ErrorCode'))->toBe('AuthForbidden');
    expect(PestSpyShardResolver::$binds)->toBe([]);
});

it('returns 404 ResellerNotFound when TenantId has no Reseller row and never binds a shard', function (): void {
    $orphan = new PestShardUser();
    $orphan->id = 400;
    $orphan->TenantId = 9999;

    $res = $this->actingAs($orphan)->getJson('/api/pest-shard-probe');

    $res->assertStatus(404);
    expect($res->json('Attributes.Error.ErrorCode'))->toBe('ResellerNotFound');
    expect(PestSpyShardResolver::$binds)->toBe([]);
});

it('rejects anonymous callers with 401 AuthInvalidCredentials and never binds a shard', function (): void {
    $res = $this->getJson('/api/pest-shard-probe');

    $res->assertStatus(401);
    expect($res->json('Attributes.Error.ErrorCode'))->toBe('AuthInvalidCredentials');
    expect(PestSpyShardResolver::$binds)->toBe([]);
});
