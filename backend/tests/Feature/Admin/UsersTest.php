<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Policies\HasRolePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Pest matrix, Admin/* row 4: Admin/UsersTest).
 *
 * Locks the Root user-directory + role-assignment surface end-to-end
 * through the admin stack (auth:sanctum -> session.active ->
 * require.role:Admin|SuperAdmin, routes/api.php lines 111-123) plus
 * the EtagMiddleware If-Match gate on PATCH (bootstrap/app.php line 45,
 * scope `PATCH:api/admin/users/`):
 *   GET    /Api/Admin/Users
 *   GET    /Api/Admin/Users/{UserId}
 *   POST   /Api/Admin/Users
 *   PATCH  /Api/Admin/Users/{UserId}                 (If-Match required)
 *   POST   /Api/Admin/Users/{UserId}/Roles
 *   DELETE /Api/Admin/Users/{UserId}/Roles/{RoleName}
 *
 * Root cause guarded (one sentence): the Root user directory is the
 * privilege-issuing surface (create operators, assign Admin/SuperAdmin,
 * revoke roles) but no HTTP-level lock existed, so regressions that
 * (a) skipped the require.role gate and let non-Admin callers list or
 * mutate users (privilege-escalation surface), (b) dropped the If-Match
 * middleware on PATCH (silent last-write-wins on the sensitive
 * Email/IsActive/TenantId fields), (c) let the store handler bypass the
 * unique-Email UserConflict mapping (dumping a raw QueryException with
 * the SQL to the client), (d) dropped the assertNotLastAdmin guard on
 * revokeRole (locking every operator out of Admin globally: spec 19
 * §"last-admin"), or (e) failed to audit RoleGranted/RoleRevoked (loss
 * of forensics on the only global-privilege mutation) could all ship
 * green.
 *
 * Branches guarded:
 *   1. GET /Users bare              -> 401 AuthUnauthorized
 *   2. GET /Users as non-Admin      -> 403 AuthForbidden
 *   3. GET /Users happy (Admin)     -> 200, seeded row present
 *   4. GET /Users/{unknown}         -> 404 UserNotFound
 *   5. POST /Users happy            -> 201, password hashed (never echoed),
 *                                       IsActive defaults to true
 *   6. POST /Users duplicate Email  -> 409 UserConflict (unique-mapped)
 *   7. PATCH /Users/{id} no If-Match -> 428 PreconditionRequired
 *   8. PATCH /Users/{id} stale hash -> 412 PreconditionFailed
 *   9. PATCH /Users/{id} happy      -> 200, Email updated after fresh ETag
 *  10. POST /Users/{id}/Roles happy -> 201, UserRoles row + RoleGranted audit
 *  11. DELETE /Users/{id}/Roles/Admin when only one Admin exists
 *                                    -> 409 AuthzLastAdminProtected
 */
final class UsersTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-users@example.test';
    private const OTHER_EMAIL = 'other-users@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const NEW_USER_PASSWORD = 'FreshTargetPass!2026';

    private User $admin;
    private User $other;
    private string $adminSessionId;
    private string $adminBearer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        $this->other = $this->makeUser(self::OTHER_EMAIL);
        [$this->adminSessionId, $this->adminBearer] = $this->openSessionAndMintToken($this->admin);
    }

    public function test_index_bare_returns_401(): void
    {
        $res = $this->getJson('/Api/Admin/Users');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_index_without_admin_role_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_index_happy_path_returns_seeded_rows(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users');
        $res->assertStatus(200);
        $emails = array_column($res->json('Results'), 'Email');
        $this->assertContains(self::ADMIN_EMAIL, $emails);
        $this->assertContains(self::OTHER_EMAIL, $emails);
        // PasswordHash must NEVER appear on the wire (model $hidden + UserResource).
        foreach ($res->json('Results') as $row) {
            $this->assertArrayNotHasKey('PasswordHash', $row, 'PasswordHash must be suppressed from GET responses.');
        }
    }

    public function test_show_unknown_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users/9999999');
        $this->assertLaraException($res, 'UserNotFound', 404);
    }

    public function test_store_happy_path_hashes_password_and_defaults_is_active(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];
        $newEmail = 'fresh-user@example.test';
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Users', [
                'Email' => $newEmail,
                'Password' => self::NEW_USER_PASSWORD,
                // TenantId omitted -> null (default)
                // IsActive omitted -> defaults to true
            ]);
        $res->assertStatus(201);
        $this->assertSame($newEmail, $res->json('Results.0.Email'));
        $this->assertTrue((bool) $res->json('Results.0.IsActive'), 'IsActive must default to true when omitted.');
        $this->assertArrayNotHasKey('PasswordHash', (array) $res->json('Results.0'));
        $this->assertArrayNotHasKey('Password', (array) $res->json('Results.0'));

        // Password hashed in DB, never stored plaintext (security-critical).
        $row = DB::connection('root')->table('Users')->where('Email', $newEmail)->first();
        $this->assertNotNull($row);
        $this->assertNotSame(self::NEW_USER_PASSWORD, $row->PasswordHash, 'PasswordHash must not equal plaintext.');
        $this->assertTrue(Hash::check(self::NEW_USER_PASSWORD, (string) $row->PasswordHash), 'Password must be verifiable via Hash::check.');
    }

    public function test_store_duplicate_email_returns_409_user_conflict(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Users', [
                'Email' => self::OTHER_EMAIL,
                'Password' => self::NEW_USER_PASSWORD,
            ]);
        $this->assertLaraException($res, 'UserConflict', 409);
    }

    public function test_update_without_if_match_returns_428_precondition_required(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->patchJson('/Api/Admin/Users/' . $this->other->getKey(), [
                'IsActive' => false,
            ]);
        $this->assertLaraException($res, 'PreconditionRequired', 428);
    }

    public function test_update_with_stale_if_match_returns_412(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];
        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminBearer,
            'If-Match' => '"deadbeef0000000000000000000000000000000000000000000000000000dead"',
        ])->patchJson('/Api/Admin/Users/' . $this->other->getKey(), [
            'IsActive' => false,
        ]);
        $this->assertLaraException($res, 'PreconditionFailed', 412);
    }

    public function test_update_happy_path_with_fresh_etag(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];

        // Fetch fresh ETag from GET single-resource endpoint (EtagMiddleware attaches on GET JSON).
        $show = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users/' . $this->other->getKey());
        $show->assertStatus(200);
        $etag = $show->headers->get('ETag');
        $this->assertNotEmpty($etag, 'GET must return an ETag header via EtagMiddleware::attachEtag.');

        $newEmail = 'other-users-renamed@example.test';
        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminBearer,
            'If-Match' => (string) $etag,
        ])->patchJson('/Api/Admin/Users/' . $this->other->getKey(), [
            'Email' => $newEmail,
        ]);
        $res->assertStatus(200);
        $this->assertSame($newEmail, $res->json('Results.0.Email'));
        $row = DB::connection('root')->table('Users')->where('UserId', $this->other->getKey())->first();
        $this->assertSame($newEmail, $row->Email);
    }

    public function test_assign_role_happy_path_writes_audit(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Users/' . $this->other->getKey() . '/Roles', [
                'RoleName' => 'Reseller',
            ]);
        $res->assertStatus(201);
        $this->assertSame('Reseller', $res->json('Results.0.RoleName'));

        $roleId = (int) DB::connection('root')->table('Roles')->where('RoleName', 'Reseller')->value('RoleId');
        $rowCount = DB::connection('root')->table('UserRoles')
            ->where('UserId', $this->other->getKey())
            ->where('RoleId', $roleId)
            ->count();
        $this->assertSame(1, $rowCount, 'UserRoles row must be inserted for the granted role.');

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'RoleGranted')
            ->where('TargetType', 'Users')
            ->where('TargetId', (int) $this->other->getKey())
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record RoleGranted.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'user.role.rolegranted' && ($ctx['roleName'] ?? null) === 'Reseller')
            ->atLeast()->once();
    }

    public function test_revoke_last_admin_is_rejected_with_409(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];

        // Seed exactly ONE Admin role globally on $other. The last-admin guard
        // (UserController::assertNotLastAdmin) must block removal.
        $adminRoleId = (int) DB::connection('root')->table('Roles')->where('RoleName', 'Admin')->value('RoleId');
        DB::connection('root')->table('UserRoles')->insert([
            'UserId' => $this->other->getKey(),
            'RoleId' => $adminRoleId,
            'CreatedAt' => Carbon::now(),
        ]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->deleteJson('/Api/Admin/Users/' . $this->other->getKey() . '/Roles/Admin');
        $this->assertLaraException($res, 'AuthzLastAdminProtected', 409);

        // Row is still present (no side effect on refused delete).
        $stillThere = DB::connection('root')->table('UserRoles')
            ->where('UserId', $this->other->getKey())
            ->where('RoleId', $adminRoleId)
            ->count();
        $this->assertSame(1, $stillThere, 'Last-admin refusal must not delete the row.');
    }

    private function swapRolePolicy(): void
    {
        require_once __DIR__ . '/../RoleGateTest.php';
        \Tests\Feature\FakeHasRolePolicy::$grants = [];
        $this->app->instance(HasRolePolicy::class, new \Tests\Feature\FakeHasRolePolicy());
    }

    private function createRootFixtures(): void
    {
        $root = DB::connection('root');
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
        $root->statement('CREATE TABLE IF NOT EXISTS "AuthSessions" (
            "SessionId" TEXT PRIMARY KEY,
            "UserId" INTEGER NOT NULL,
            "Kind" TEXT NOT NULL,
            "ImpersonatorUserId" INTEGER NULL,
            "ParentSessionId" TEXT NULL,
            "CreatedAt" TEXT NOT NULL,
            "ExpiresAt" TEXT NOT NULL,
            "EndedAt" TEXT NULL,
            "RevokeReason" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "personal_access_tokens" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT,
            "tokenable_type" TEXT NOT NULL,
            "tokenable_id" INTEGER NOT NULL,
            "name" TEXT NOT NULL,
            "token" TEXT NOT NULL UNIQUE,
            "abilities" TEXT NULL,
            "last_used_at" TEXT NULL,
            "expires_at" TEXT NULL,
            "created_at" TEXT NULL,
            "updated_at" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "AuditLogs" (
            "AuditLogId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ActorType" TEXT NOT NULL,
            "ActorId" INTEGER NULL,
            "Action" TEXT NOT NULL,
            "TargetType" TEXT NOT NULL,
            "TargetId" INTEGER NULL,
            "RequestId" TEXT NOT NULL,
            "PayloadJson" TEXT NULL,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "Roles" (
            "RoleId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "RoleName" TEXT NOT NULL UNIQUE
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "UserRoles" (
            "UserRoleId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "UserId" INTEGER NOT NULL,
            "RoleId" INTEGER NOT NULL,
            "CreatedAt" TEXT NOT NULL,
            UNIQUE("UserId","RoleId")
        )');
        // Seed the closed-set roles the controller checks against.
        foreach (['Admin', 'SuperAdmin', 'Reseller'] as $roleName) {
            $exists = $root->table('Roles')->where('RoleName', $roleName)->exists();
            if (!$exists) {
                $root->table('Roles')->insert(['RoleName' => $roleName]);
            }
        }
    }

    private function makeUser(string $email): User
    {
        $user = new User();
        $user->Email = $email;
        $user->PasswordHash = Hash::make(self::PASSWORD);
        $user->TenantId = null;
        $user->IsActive = true;
        $user->save();

        return $user->refresh();
    }

    /** @return array{0:string,1:string} */
    private function openSessionAndMintToken(User $user): array
    {
        $sessionId = Uuid::uuid4()->toString();
        $now = Carbon::now();
        $row = new \App\Models\AuthSession();
        $row->SessionId = $sessionId;
        $row->UserId = (int) $user->getKey();
        $row->Kind = \App\Models\AuthSession::KIND_NORMAL;
        $row->ImpersonatorUserId = null;
        $row->ParentSessionId = null;
        $row->CreatedAt = $now;
        $row->ExpiresAt = $now->copy()->addMinutes(60);
        $row->EndedAt = null;
        $row->RevokeReason = null;
        $row->save();
        $token = $user->createToken($sessionId);

        return [$sessionId, $token->plainTextToken];
    }
}
