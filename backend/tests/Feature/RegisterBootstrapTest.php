<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthSessionService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Bootstrap registration contract.
 *
 * Locks:
 *   - First POST /Api/Auth/Register on an empty Users table creates the
 *     user, grants SuperAdmin, and returns a session envelope (201).
 *   - Second POST returns AuthRegistrationClosed (403) even with valid
 *     credentials.
 *   - Payloads that fail FormRequest rules return 422 without touching
 *     the Users table.
 *
 * We swap AuthSessionService for a stub so the test does not need the
 * Root AuthSessions schema, personal_access_tokens table, or Sanctum
 * boot state. The controller's contract we care about here is the
 * bootstrap-once gate + SuperAdmin role assignment.
 */
final class RegisterBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootIdentityFixture();
        $this->bindStubAuthSessionService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_first_registration_creates_super_admin(): void
    {
        $res = $this->postJson('/Api/Auth/Register', [
            'Email' => 'root@example.test',
            'Password' => 'BootstrapPass!234',
        ]);

        $res->assertStatus(201);
        $body = $res->json();
        $this->assertSame(['SuperAdmin'], $body['Results'][0]['Roles']);

        $userCount = (int) DB::connection('root')->table('Users')->count();
        $this->assertSame(1, $userCount);

        $roleId = (int) DB::connection('root')->table('Roles')->where('RoleName', 'SuperAdmin')->value('RoleId');
        $granted = DB::connection('root')->table('UserRoles')
            ->where('RoleId', $roleId)
            ->count();
        $this->assertSame(1, $granted);
    }

    public function test_second_registration_is_closed(): void
    {
        $this->postJson('/Api/Auth/Register', [
            'Email' => 'root@example.test',
            'Password' => 'BootstrapPass!234',
        ])->assertStatus(201);

        $second = $this->postJson('/Api/Auth/Register', [
            'Email' => 'second@example.test',
            'Password' => 'AnotherPass!2345',
        ]);
        $second->assertStatus(403);
        $this->assertSame(
            'AuthRegistrationClosed',
            $second->json('Attributes.Error.ErrorCode'),
        );
        $this->assertSame(1, (int) DB::connection('root')->table('Users')->count());
    }

    public function test_short_password_is_rejected_without_side_effects(): void
    {
        $res = $this->postJson('/Api/Auth/Register', [
            'Email' => 'root@example.test',
            'Password' => 'short',
        ]);
        $res->assertStatus(422);
        $this->assertSame(0, (int) DB::connection('root')->table('Users')->count());
    }

    private function bindStubAuthSessionService(): void
    {
        $stub = Mockery::mock(AuthSessionService::class);
        $stub->shouldReceive('openNormal')->andReturnUsing(function ($user) {
            $row = new \App\Models\AuthSession();
            $row->SessionId = '00000000-0000-4000-8000-000000000000';
            $row->UserId = (int) $user->getKey();
            $row->Kind = \App\Models\AuthSession::KIND_NORMAL;
            $row->ExpiresAt = \Illuminate\Support\Carbon::now()->addHours(8);

            return $row;
        });
        $this->app->instance(AuthSessionService::class, $stub);

        // createToken() on the User model needs personal_access_tokens; we
        // stub the model to short-circuit it.
        \App\Models\User::macro('createToken', function (string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null) {
            return new class {
                public string $plainTextToken = 'stub-token';
            };
        });
    }

    private function createRootIdentityFixture(): void
    {
        $root = DB::connection('root');
        $root->statement('CREATE TABLE IF NOT EXISTS "Roles" (
            "RoleId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "RoleName" TEXT NOT NULL UNIQUE,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "UpdatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "Users" (
            "UserId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "Email" TEXT NOT NULL UNIQUE,
            "PasswordHash" TEXT NOT NULL,
            "TenantId" INTEGER NULL,
            "IsActive" INTEGER NOT NULL DEFAULT 1,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "UpdatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "DeletedAt" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "UserRoles" (
            "UserRoleId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "UserId" INTEGER NOT NULL,
            "RoleId" INTEGER NOT NULL,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE ("UserId", "RoleId")
        )');
        foreach (['SuperAdmin', 'Admin', 'Reseller', 'AppBuilder', 'EndUser'] as $role) {
            $root->table('Roles')->insertOrIgnore(['RoleName' => $role]);
        }
    }
}
