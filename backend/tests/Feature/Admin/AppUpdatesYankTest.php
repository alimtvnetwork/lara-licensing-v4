<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuthSession;
use App\Models\User;
use App\Policies\HasRolePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Pest matrix, Admin/* row 3: Admin/AppUpdatesYankTest).
 *
 * Locks `POST /Api/Admin/AppUpdates/{Version}/Yank`
 * (App\Http\Controllers\Admin\AppUpdateController::yank, lines 385-434)
 * end-to-end through the full admin middleware stack (auth:sanctum ->
 * session.active -> require.role:Admin|SuperAdmin, routes/api.php line
 * 157) plus the `AppUpdateYankRequest` FormRequest binding.
 *
 * Root cause guarded (one sentence): the yank flow is the ONLY
 * destructive AppUpdate operation (spec 17 v1.3.0 §"Yank flow" line 303
 * + §"Admin invariants" §10: "no un-yank endpoint, irreversible in v1"),
 * and while it has FormRequest validation and a transactional handler,
 * zero HTTP-level lock exists, so regressions that (a) let malformed
 * Version bypass the regex, (b) let already-yanked rows re-yank (double
 * audit write, stale `YankedAt`), (c) dropped the transactional audit
 * row, or (d) failed to stamp `YankedByUserId` with the actor
 * (auth()->id()) leaving forensics blank, would all ship green.
 *
 * Branches guarded:
 *   1. Bare request -> 401 AuthUnauthorized (guard).
 *   2. Authenticated non-Admin -> 403 AuthForbidden.
 *   3. Malformed Version path segment -> 422 ValidationFailed (regex).
 *   4. Unknown (Product, Channel=Stable, Version) -> 404 UpdateAssetNotFound.
 *   5. Already-yanked row -> 409 ValidationConflict (`AlreadyYanked` rule).
 *   6. Happy path: IsYanked=1, YankedAt stamped, YankedByUserId=actor,
 *      `UpdateYanked` audit row written, envelope shape correct.
 */
final class AppUpdatesYankTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-yank@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const PRODUCT = 'lara-cli';

    private User $admin;
    private string $adminBearer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        [, $this->adminBearer] = $this->openSessionAndMintToken($this->admin);
    }

    public function test_bare_returns_401(): void
    {
        $res = $this->postJson('/Api/Admin/AppUpdates/1.2.3/Yank');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_non_admin_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/AppUpdates/1.2.3/Yank');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_malformed_version_fails_validation(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        // "not.a.version!" fails VERSION_REGEX in AppUpdateYankRequest.
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/AppUpdates/not-a-version!/Yank');
        $this->assertLaraException($res, 'ValidationFailed', 422);
    }

    public function test_unknown_version_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/AppUpdates/9.9.9/Yank');
        $this->assertLaraException($res, 'UpdateAssetNotFound', 404);
    }

    public function test_already_yanked_returns_409(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $this->seedUpdate('1.0.0', isYanked: 1);
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/AppUpdates/1.0.0/Yank');
        $this->assertLaraException($res, 'ValidationConflict', 409);
    }

    public function test_happy_path_stamps_actor_and_writes_audit(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];
        $updateId = $this->seedUpdate('2.0.0', isYanked: 0);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/AppUpdates/2.0.0/Yank');
        $res->assertStatus(200);
        $this->assertSame('2.0.0', $res->json('Results.0.Version'));
        $this->assertSame(1, $res->json('Results.0.IsYanked'));
        $this->assertNotNull($res->json('Results.0.YankedAt'));

        // Row stamped: IsYanked, YankedAt, YankedByUserId=actor.
        $row = DB::connection('root')->table('AppUpdates')->where('AppUpdateId', $updateId)->first();
        $this->assertSame(1, (int) $row->IsYanked, 'IsYanked must flip to 1.');
        $this->assertNotNull($row->YankedAt, 'YankedAt must be stamped inside the transaction.');
        $this->assertSame((int) $this->admin->getKey(), (int) $row->YankedByUserId, 'YankedByUserId must be the acting admin (auth()->id()), never a client-supplied field.');

        // Audit row `UpdateYanked` written inside the same transaction.
        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'UpdateYanked')
            ->where('TargetType', 'AppUpdates')
            ->where('TargetId', $updateId)
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must write UpdateYanked before COMMIT.');
    }

    private function swapRolePolicy(): void
    {
        require_once __DIR__ . '/../RoleGateTest.php';
        \Tests\Feature\FakeHasRolePolicy::$grants = [];
        $this->app->instance(HasRolePolicy::class, new \Tests\Feature\FakeHasRolePolicy());
    }

    private function seedUpdate(string $version, int $isYanked): int
    {
        $now = Carbon::now('UTC')->toDateTimeString();

        return (int) DB::connection('root')->table('AppUpdates')->insertGetId([
            'Product' => self::PRODUCT,
            'Channel' => 'Stable',
            'Version' => $version,
            'MinRequiredVersion' => '0.1.0',
            'ReleaseNotesUrl' => null,
            'PublishedAt' => $now,
            'PublishedByUserId' => (int) $this->admin->getKey(),
            'IsYanked' => $isYanked,
            'YankedAt' => $isYanked === 1 ? $now : null,
            'YankedByUserId' => $isYanked === 1 ? (int) $this->admin->getKey() : null,
        ]);
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
        $root->statement('CREATE TABLE IF NOT EXISTS "AppUpdates" (
            "AppUpdateId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "Product" TEXT NOT NULL,
            "Channel" TEXT NOT NULL,
            "Version" TEXT NOT NULL,
            "MinRequiredVersion" TEXT NOT NULL,
            "ReleaseNotesUrl" TEXT NULL,
            "PublishedAt" TEXT NOT NULL,
            "PublishedByUserId" INTEGER NOT NULL,
            "IsYanked" INTEGER NOT NULL DEFAULT 0,
            "YankedAt" TEXT NULL,
            "YankedByUserId" INTEGER NULL
        )');
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
        $row = new AuthSession();
        $row->SessionId = $sessionId;
        $row->UserId = (int) $user->getKey();
        $row->Kind = AuthSession::KIND_NORMAL;
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
