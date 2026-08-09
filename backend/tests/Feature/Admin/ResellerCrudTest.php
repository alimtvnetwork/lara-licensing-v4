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
 * Plan 10 step 15 (Admin/* row: Admin/ResellerCrudTest).
 *
 * Root cause guarded (one sentence): `Admin\ResellerController` is the
 * Root tenancy registry (create/rename/deactivate resellers that every
 * shard is keyed by) but had no HTTP-level lock, so regressions in the
 * `require.role` gate, `ResellerStoreRequest`/`ResellerUpdateRequest`
 * validation, the unique-conflict mapping (`ResellerName`/`ResellerSlug`
 * -> 409 `ResellerConflict` instead of a raw QueryException), the
 * `EtagMiddleware` If-Match gate on PATCH (silent last-write-wins on
 * `ContactEmail`/`IsActive`/`ResellerName`), or the
 * `ResellerCreated`/`ResellerUpdated` audit rows could all ship green.
 *
 * Branches guarded (10):
 *   1.  GET   bare                    -> 401 AuthUnauthorized
 *   2.  GET   non-Admin caller        -> 403 AuthForbidden
 *   3.  GET   happy (Admin)           -> 200 with seeded reseller
 *   4.  GET   unknown slug            -> 404 ResellerNotFound
 *   5.  POST  malformed slug          -> 400 ValidationFailed
 *   6.  POST  happy                   -> 201 + `ResellerCreated` audit
 *   7.  POST  duplicate slug          -> 409 ResellerConflict (unique map)
 *   8.  PATCH without If-Match        -> 428 PreconditionRequired
 *   9.  PATCH stale If-Match          -> 412 PreconditionFailed
 *   10. PATCH happy (fresh ETag)      -> 200 + `ResellerUpdated` audit
 */
final class ResellerCrudTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-reseller@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const SEED_SLUG = 'acme';
    private const SEED_NAME = 'Acme';
    private const NEW_SLUG = 'beta-corp';
    private const NEW_NAME = 'Beta Corp';

    private User $admin;
    private int $resellerId;
    private string $adminBearer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        $this->adminBearer = $this->openSessionAndMintToken($this->admin);
        $this->resellerId = $this->seedReseller(self::SEED_SLUG, self::SEED_NAME);
    }

    public function test_index_bare_returns_401(): void
    {
        $res = $this->getJson('/Api/Admin/Resellers');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_index_non_admin_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Resellers');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_index_happy_path_lists_seeded_reseller(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Resellers');
        $res->assertStatus(200);
        $slugs = array_column($res->json('Results'), 'ResellerSlug');
        $this->assertContains(self::SEED_SLUG, $slugs);
    }

    public function test_show_unknown_slug_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Resellers/no-such-slug');
        $this->assertLaraException($res, 'ResellerNotFound', 404);
    }

    public function test_store_malformed_slug_returns_400(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Resellers', [
                'ResellerName' => 'Bad',
                'ResellerSlug' => 'Bad Slug!!!', // fails SLUG_REGEX
                'ContactEmail' => 'ops@bad.test',
            ]);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_store_happy_path_writes_audit(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Resellers', [
                'ResellerName' => self::NEW_NAME,
                'ResellerSlug' => self::NEW_SLUG,
                'ContactEmail' => 'ops@beta.test',
                'IsActive' => true,
            ]);
        $res->assertStatus(201);
        $this->assertSame(self::NEW_SLUG, $res->json('Results.0.ResellerSlug'));

        $row = DB::connection('root')->table('Resellers')
            ->where('ResellerSlug', self::NEW_SLUG)->first();
        $this->assertNotNull($row);

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'ResellerCreated')
            ->where('TargetType', 'Resellers')
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record ResellerCreated.');
    }

    public function test_store_duplicate_slug_returns_409_reseller_conflict(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Resellers', [
                'ResellerName' => 'Different Name',
                'ResellerSlug' => self::SEED_SLUG, // duplicate
                'ContactEmail' => 'ops@dup.test',
            ]);
        $this->assertLaraException($res, 'ResellerConflict', 409);
    }

    public function test_patch_without_if_match_returns_428(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->patchJson('/Api/Admin/Resellers/' . self::SEED_SLUG, [
                'ContactEmail' => 'renamed@acme.test',
            ]);
        $this->assertLaraException($res, 'PreconditionRequired', 428);
    }

    public function test_patch_stale_if_match_returns_412(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminBearer,
            'If-Match' => '"0000000000000000000000000000000000000000000000000000000000000000"',
        ])->patchJson('/Api/Admin/Resellers/' . self::SEED_SLUG, [
            'ContactEmail' => 'renamed@acme.test',
        ]);
        $this->assertLaraException($res, 'PreconditionFailed', 412);
    }

    public function test_patch_happy_path_with_fresh_etag_writes_audit(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $showRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Resellers/' . self::SEED_SLUG);
        $showRes->assertStatus(200);
        $etag = $showRes->headers->get('ETag');
        $this->assertNotEmpty($etag, 'GET must emit ETag header via EtagMiddleware.');

        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminBearer,
            'If-Match' => $etag,
        ])->patchJson('/Api/Admin/Resellers/' . self::SEED_SLUG, [
            'ContactEmail' => 'renamed@acme.test',
        ]);
        $res->assertStatus(200);
        $this->assertSame('renamed@acme.test', $res->json('Results.0.ContactEmail'));

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'ResellerUpdated')
            ->where('TargetType', 'Resellers')
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record ResellerUpdated.');
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
            "TargetId" TEXT NULL,
            "RequestId" TEXT NOT NULL,
            "PayloadJson" TEXT NULL,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "Resellers" (
            "ResellerId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ResellerName" TEXT NOT NULL UNIQUE,
            "ResellerSlug" TEXT NOT NULL UNIQUE,
            "ContactEmail" TEXT NOT NULL,
            "IsActive" INTEGER NOT NULL DEFAULT 1,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "UpdatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "DeletedAt" TEXT NULL
        )');
    }

    private function seedReseller(string $slug, string $name): int
    {
        return (int) DB::connection('root')->table('Resellers')->insertGetId([
            'ResellerName' => $name,
            'ResellerSlug' => $slug,
            'ContactEmail' => 'ops@' . $slug . '.test',
            'IsActive' => 1,
        ]);
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

    private function openSessionAndMintToken(User $user): string
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

        return $token->plainTextToken;
    }
}
