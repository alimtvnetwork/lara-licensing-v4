<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuthSession;
use App\Models\User;
use App\Policies\HasRolePolicy;
use App\Services\RuntimeConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 16 step 60 (v0.566.0). HTTP-level lock for the runtime-config
 * admin surface introduced in v0.563.0:
 *   GET /Api/Admin/RuntimeConfig
 *   PUT /Api/Admin/RuntimeConfig
 * through auth:sanctum -> session.active -> require.role:Admin|SuperAdmin
 * plus the controller's SuperAdmin-only mutation gate.
 *
 * Root cause guarded (one sentence): `RuntimeConfigController` +
 * `RuntimeConfigService` were unit-tested at the service seam only
 * (`backend/tests/Unit/Services/RuntimeConfigServiceTest.php`), so the
 * HTTP layer -- `require.role` gate, `SuperAdmin` mutation gate, `If-Match`
 * presence check, `RuntimeConfigConflict` on stale ETag, `RuntimeConfigModeMismatch`
 * from FormRequest -> service handoff, atomic-write idempotency, and the
 * `RuntimeConfigUpdated` audit row -- could regress green.
 *
 * Branches guarded:
 *   1. GET bare                                -> 401 AuthInvalidCredentials
 *   2. GET as non-Admin                        -> 403 AuthForbidden
 *   3. GET happy                               -> 200 + strong ETag + PascalCase body
 *   4. PUT bare                                -> 401 AuthInvalidCredentials
 *   5. PUT as Admin (not SuperAdmin)           -> 403 RuntimeConfigForbidden
 *   6. PUT missing If-Match                    -> 428 PreconditionRequired
 *   7. PUT stale If-Match                      -> 412 RuntimeConfigConflict
 *   8. PUT Mode=preview + ApiBaseUrl non-null  -> 422 RuntimeConfigModeMismatch
 *   9. PUT happy                               -> 200, file rewritten, ETag rotates,
 *                                                RuntimeConfigUpdated audit row
 *  10. PUT idempotency: re-submit old If-Match after happy -> 412 RuntimeConfigConflict
 */
final class RuntimeConfigTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-runtime@example.test';
    private const SUPER_EMAIL = 'super-runtime@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';

    private User $admin;
    private User $super;
    private string $adminBearer;
    private string $superBearer;
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        $this->super = $this->makeUser(self::SUPER_EMAIL);
        [, $this->adminBearer] = $this->openSessionAndMintToken($this->admin);
        [, $this->superBearer] = $this->openSessionAndMintToken($this->super);
        $this->tmpPath = $this->seedRuntimeConfigFile();
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpPath);
        @unlink($this->tmpPath . '.lock');
        @unlink($this->tmpPath . '.tmp');
        parent::tearDown();
    }

    public function test_show_bare_returns_401(): void
    {
        $res = $this->getJson('/Api/Admin/RuntimeConfig');
        $this->assertLaraException($res, 'AuthInvalidCredentials', 401);
    }

    public function test_show_without_admin_role_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/RuntimeConfig');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_show_happy_returns_body_and_etag(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/RuntimeConfig');
        $res->assertStatus(200);
        $etag = $res->headers->get('ETag');
        $this->assertNotNull($etag, 'GET must set strong ETag header.');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $etag);
        $this->assertSame('preview', $res->json('Results.0.Mode'));
        $this->assertNull($res->json('Results.0.ApiBaseUrl'));
        $this->assertTrue((bool) $res->json('Results.0.AllowRuntimeToggle'));
    }

    public function test_update_bare_returns_401(): void
    {
        $res = $this->putJson('/Api/Admin/RuntimeConfig', ['Mode' => 'preview']);
        $this->assertLaraException($res, 'AuthInvalidCredentials', 401);
    }

    public function test_update_as_admin_without_super_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $etag = $this->readEtag($this->adminBearer);
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminBearer, 'If-Match' => $etag])
            ->putJson('/Api/Admin/RuntimeConfig', ['PreviewSeed' => 'empty']);
        $this->assertLaraException($res, 'RuntimeConfigForbidden', 403);
    }

    public function test_update_missing_if_match_returns_428(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->super->getKey() => ['SuperAdmin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->superBearer)
            ->putJson('/Api/Admin/RuntimeConfig', ['PreviewSeed' => 'empty']);
        $this->assertLaraException($res, 'PreconditionRequired', 428);
    }

    public function test_update_stale_if_match_returns_412_conflict(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->super->getKey() => ['SuperAdmin']];
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $this->superBearer, 'If-Match' => '"' . str_repeat('0', 64) . '"'])
            ->putJson('/Api/Admin/RuntimeConfig', ['PreviewSeed' => 'empty']);
        $this->assertLaraException($res, 'RuntimeConfigConflict', 412);
    }

    public function test_update_mode_preview_with_api_base_url_returns_422(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->super->getKey() => ['SuperAdmin']];
        $etag = $this->readEtag($this->superBearer);
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $this->superBearer, 'If-Match' => $etag])
            ->putJson('/Api/Admin/RuntimeConfig', ['Mode' => 'preview', 'ApiBaseUrl' => 'https://api.example.test']);
        $this->assertLaraException($res, 'RuntimeConfigModeMismatch', 422);
    }

    public function test_update_happy_rotates_etag_writes_file_and_audits(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->super->getKey() => ['SuperAdmin']];
        $etag = $this->readEtag($this->superBearer);
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $this->superBearer, 'If-Match' => $etag])
            ->putJson('/Api/Admin/RuntimeConfig', ['PreviewSeed' => 'empty']);
        $res->assertStatus(200);
        $newEtag = $res->headers->get('ETag');
        $this->assertNotNull($newEtag);
        $this->assertNotSame($etag, $newEtag, 'ETag must rotate after successful update.');
        $this->assertSame('empty', $res->json('Results.0.PreviewSeed'));

        $decoded = json_decode((string) file_get_contents($this->tmpPath), true);
        $this->assertIsArray($decoded);
        $this->assertSame('empty', $decoded['PreviewSeed']);

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'RuntimeConfigUpdated')
            ->where('TargetType', 'RuntimeConfig')
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record RuntimeConfigUpdated.');
    }

    public function test_update_replaying_old_if_match_returns_412(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->super->getKey() => ['SuperAdmin']];
        $etag = $this->readEtag($this->superBearer);
        $ok = $this->withHeaders(['Authorization' => 'Bearer ' . $this->superBearer, 'If-Match' => $etag])
            ->putJson('/Api/Admin/RuntimeConfig', ['PreviewSeed' => 'empty']);
        $ok->assertStatus(200);
        $replay = $this->withHeaders(['Authorization' => 'Bearer ' . $this->superBearer, 'If-Match' => $etag])
            ->putJson('/Api/Admin/RuntimeConfig', ['PreviewSeed' => 'error']);
        $this->assertLaraException($replay, 'RuntimeConfigConflict', 412);
    }

    private function readEtag(string $bearer): string
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $bearer)->getJson('/Api/Admin/RuntimeConfig');
        $res->assertStatus(200);

        return (string) $res->headers->get('ETag');
    }

    private function seedRuntimeConfigFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'runtime-config-') . '.json';
        $seed = [
            'Version' => '0.566.0',
            'Mode' => 'preview',
            'ApiBaseUrl' => null,
            'PreviewSeed' => 'default',
            'UpdatedAt' => '2026-07-20T12:00:00Z',
            'AllowRuntimeToggle' => true,
        ];
        file_put_contents($path, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        Config::set('lara.runtime_config_path', $path);
        $this->app->forgetInstance(RuntimeConfigService::class);
        $this->app->singleton(RuntimeConfigService::class, static fn () => new RuntimeConfigService($path));

        return $path;
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
