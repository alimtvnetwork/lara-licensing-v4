<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Policies\HasRolePolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 06 step 50 (AC-RBAC-001..003).
 *
 * Locks the `require.role` middleware contract without needing the full
 * Root schema (Users, Roles, UserRoles). We swap `HasRolePolicy` in the
 * container with an in-memory stub keyed on user id -> role list, and
 * register a throwaway `GET /api/role-probe` protected by
 * `require.role:Admin|SuperAdmin`. Three cases are locked:
 *
 *   - No authenticated caller           -> AuthInvalidCredentials (401)
 *   - Authenticated caller missing role -> AuthForbidden (403)
 *   - Authenticated caller with role    -> 200 OK
 *
 * If a future refactor drops the `AuthInvalidCredentials` short-circuit
 * or the `AuthForbidden` mapping, this test fails red.
 */
final class RoleGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(HasRolePolicy::class, new FakeHasRolePolicy());
        Route::middleware(['require.role:Admin|SuperAdmin'])
            ->get('/api/role-probe', fn () => response()->json(['Ok' => true]));
    }

    public function test_no_auth_returns_401(): void
    {
        $res = $this->getJson('/api/role-probe');
        $res->assertStatus(401);
        $this->assertSame('AuthInvalidCredentials', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_wrong_role_returns_403(): void
    {
        $user = $this->makeUser('user-1');
        FakeHasRolePolicy::$grants = ['user-1' => ['Reseller']];
        $res = $this->actingAs($user)->getJson('/api/role-probe');
        $res->assertStatus(403);
        $this->assertSame('AuthForbidden', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_matching_role_returns_200(): void
    {
        $user = $this->makeUser('user-2');
        FakeHasRolePolicy::$grants = ['user-2' => ['Admin']];
        $res = $this->actingAs($user)->getJson('/api/role-probe');
        $res->assertStatus(200);
        $this->assertTrue((bool) $res->json('Ok'));
    }

    private function makeUser(string $id): Authenticatable
    {
        $user = new AuthUser();
        $user->id = $id;

        return $user;
    }
}

/**
 * In-memory stub keyed on user id -> list of role names. Replaces the
 * production Root-DB backed policy so this suite does not require the
 * `UserRoles`/`Roles` schema to boot.
 */
final class FakeHasRolePolicy extends HasRolePolicy
{
    /** @var array<string,list<string>> */
    public static array $grants = [];

    public function __construct()
    {
        // Skip parent constructor (no DB dependency).
    }

    public function hasRole(string $userId, string $role): bool
    {
        return in_array($role, self::$grants[$userId] ?? [], true);
    }

    /** @param list<string> $roles */
    public function hasAnyRole(string $userId, array $roles): bool
    {
        $held = self::$grants[$userId] ?? [];
        foreach ($roles as $role) {
            if (in_array($role, $held, true)) {
                return true;
            }
        }

        return false;
    }
}
