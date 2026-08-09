<?php

declare(strict_types=1);

use App\Policies\HasRolePolicy;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Pest feature suite: `require.role` gate + identity-carrying logs.
 *
 * Locks:
 *   - AC-RBAC-001: anonymous caller -> 401 AuthInvalidCredentials and
 *     `rbac.unauthenticated` warning carrying the request path.
 *   - AC-RBAC-002: authenticated caller lacking the role -> 403
 *     AuthForbidden and `rbac.forbidden` warning carrying UserId + Path
 *     + RequiredRoles (the "failing identity path" the ops runbook
 *     grep-scans for, spec 21 §04-roles §Observability).
 *   - AC-RBAC-003: authenticated caller with the role -> 200 OK and no
 *     rbac.forbidden log emission.
 *
 * Mirrors the stub-swap pattern used by tests/Feature/RoleGateTest.php
 * so we do not need the Root `UserRoles`/`Roles` schema to boot.
 */

/** In-memory HasRolePolicy stub keyed on user id -> role list. */
final class PestFakeHasRolePolicy extends HasRolePolicy
{
    /** @var array<string,list<string>> */
    public static array $grants = [];

    public function __construct() {}

    public function hasRole(string $userId, string $role): bool
    {
        return in_array($role, self::$grants[$userId] ?? [], true);
    }

    /** @param list<string> $roles */
    public function hasAnyRole(string $userId, array $roles): bool
    {
        foreach ($roles as $role) {
            if (in_array($role, self::$grants[$userId] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}

beforeEach(function (): void {
    PestFakeHasRolePolicy::$grants = [];
    $this->app->instance(HasRolePolicy::class, new PestFakeHasRolePolicy());
    Route::middleware(['require.role:Admin|SuperAdmin'])
        ->get('/api/pest-rbac-probe', fn () => response()->json(['Ok' => true]));
});

it('rejects anonymous callers with 401 and logs the unauthenticated path', function (): void {
    Log::spy();
    $res = $this->getJson('/api/pest-rbac-probe');
    $res->assertStatus(401);
    expect($res->json('Attributes.Error.ErrorCode'))->toBe('AuthInvalidCredentials');
    Log::shouldHaveReceived('warning')->withArgs(function (string $event, array $ctx): bool {
        return $event === 'rbac.unauthenticated'
            && ($ctx['Path'] ?? null) === 'api/pest-rbac-probe';
    })->once();
});

it('rejects authenticated callers missing the role and logs UserId + Path + RequiredRoles', function (): void {
    $user = new AuthUser();
    $user->id = 'user-42';
    PestFakeHasRolePolicy::$grants = ['user-42' => ['Reseller']];
    Log::spy();
    $res = $this->actingAs($user)->getJson('/api/pest-rbac-probe');
    $res->assertStatus(403);
    expect($res->json('Attributes.Error.ErrorCode'))->toBe('AuthForbidden');
    Log::shouldHaveReceived('warning')->withArgs(function (string $event, array $ctx): bool {
        return $event === 'rbac.forbidden'
            && ($ctx['UserId'] ?? null) === 'user-42'
            && ($ctx['Path'] ?? null) === 'api/pest-rbac-probe'
            && ($ctx['RequiredRoles'] ?? null) === ['Admin', 'SuperAdmin'];
    })->once();
});

it('admits callers holding any required role and emits no rbac.forbidden warning', function (): void {
    $user = new AuthUser();
    $user->id = 'user-7';
    PestFakeHasRolePolicy::$grants = ['user-7' => ['SuperAdmin']];
    Log::spy();
    $res = $this->actingAs($user)->getJson('/api/pest-rbac-probe');
    $res->assertOk();
    expect($res->json('Ok'))->toBeTrue();
    Log::shouldNotHaveReceived('warning', Mockery::on(fn ($event) => $event === 'rbac.forbidden'));
});
