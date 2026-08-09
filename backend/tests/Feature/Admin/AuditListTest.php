<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuthSession;
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
 * Plan 10 step 15 (Pest matrix, first `Admin/*` row: `Admin/AuditListTest`).
 *
 * Locks `GET /Api/Admin/AuditLogs` (App\Http\Controllers\Admin\AuditController::index)
 * end-to-end through the full admin middleware stack registered in
 * `routes/api.php` line 61: `auth:sanctum` -> `session.active` ->
 * `require.role:Admin|SuperAdmin`.
 *
 * Root cause guarded (one sentence): v0.291.0 shipped the Admin AuditLogs
 * read surface and `AuditEntryResource` was folded in during Plan 10 step 4
 * (v0.333.0-v0.340.0), but there was zero HTTP-level lock on the endpoint,
 * so a refactor that (a) dropped one of the three middlewares, (b) let the
 * `Limit` query parameter escape its 1..500 clamp (DoS surface via unbounded
 * fetch), (c) applied filters without the `ACTION_REGEX`/`TARGET_REGEX`
 * whitelist (SQL-injection-adjacent, or crash on non-string values), (d)
 * reversed the `CreatedAt DESC, AuditLogId DESC` ordering (breaking the
 * viewer's "newest first" contract), or (e) stopped decoding `PayloadJson`
 * into `Payload` (frontend regressions on LineageBadge context) would ship
 * green.
 *
 * Branches guarded:
 *   1. Bare (no Authorization header): 401 `AuthInvalidCredentials` from
 *      `require.role` short-circuit (spec 06 AC-RBAC-001).
 *   2. Authenticated but caller lacks Admin/SuperAdmin: 403 `AuthForbidden`.
 *   3. Happy path with an Admin bearer: 200 envelope, `Results` newest first
 *      (DESC), envelope shape from `AuditEntryResource` including decoded
 *      `Payload` object, and `Attributes.Count`/`Attributes.Limit` present.
 *   4. `Limit=9999` clamps to 500 in `Attributes.Limit` (never leaks past
 *      the fetch cap).
 *   5. `Action` filter with a valid catalog-shaped value returns only matches.
 *   6. `Action` filter with an injection-shaped value ("' OR 1=1 --") is
 *      silently ignored by the regex whitelist and the endpoint returns
 *      the unfiltered set (never crashes, never leaks 500).
 *
 * Fixture strategy: raw-sqlite same as `LogoutTest` (avoids Postgres-only
 * DDL). `HasRolePolicy` is swapped for the in-memory `FakeHasRolePolicy`
 * from `RoleGateTest` so we don't need `Roles`/`UserRoles` tables.
 */
final class AuditListTest extends TestCase
{
    use AssertsLaraException;

    private const EMAIL = 'admin-audit@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';

    private User $user;
    private string $sessionId;
    private string $bearer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->user = $this->makeUser();
        [$this->sessionId, $this->bearer] = $this->openSessionAndMintToken($this->user);
    }

    public function test_bare_returns_401_from_auth_guard(): void
    {
        $res = $this->getJson('/Api/Admin/AuditLogs');
        // `auth:sanctum` runs before `require.role`, so a bare request is
        // rejected by the guard, surfaced as `AuthUnauthorized` by the
        // exception handler (same shape as LogoutTest bare branch).
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_authenticated_without_admin_role_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->user->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Admin/AuditLogs');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_admin_happy_path_returns_newest_first_with_decoded_payload(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->user->getKey() => ['Admin']];
        $this->seedAuditRows();

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Admin/AuditLogs');
        $res->assertStatus(200);

        $rows = $res->json('Results');
        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(3, count($rows));

        // DESC ordering: the newest CreatedAt row must be first.
        $first = $rows[0];
        $this->assertSame('LicenseRevoked', $first['Action'], 'Newest row (most recent CreatedAt) must sort first.');
        $this->assertIsArray($first['Payload'], 'PayloadJson must be decoded into an array on the wire.');
        $this->assertSame('operator', $first['Payload']['Origin'] ?? null);

        // Envelope attributes.
        $this->assertSame(count($rows), $res->json('Attributes.Count'));
        $this->assertSame(100, $res->json('Attributes.Limit'), 'Default Limit is 100 (LIMIT_DEFAULT).');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'admin.audit.index' && ($ctx['returned'] ?? null) === count($rows))
            ->atLeast()->once();
    }

    public function test_limit_is_clamped_to_500(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->user->getKey() => ['SuperAdmin']];
        $this->seedAuditRows();

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Admin/AuditLogs?Limit=9999');
        $res->assertStatus(200);
        $this->assertSame(500, $res->json('Attributes.Limit'), 'Limit>MAX must clamp to LIMIT_MAX=500 in Attributes.');
    }

    public function test_action_filter_narrows_results(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->user->getKey() => ['Admin']];
        $this->seedAuditRows();

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Admin/AuditLogs?Action=LicenseIssued');
        $res->assertStatus(200);
        $rows = $res->json('Results');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('LicenseIssued', $row['Action'], 'Filter must apply to every returned row.');
        }
    }

    public function test_injection_shaped_filter_is_silently_ignored(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->user->getKey() => ['Admin']];
        $this->seedAuditRows();

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Admin/AuditLogs?' . http_build_query(['Action' => "' OR 1=1 --"]));
        $res->assertStatus(200);
        $rows = $res->json('Results');
        // Whitelist rejects the value, so all seeded actions come back.
        $actions = array_unique(array_column($rows, 'Action'));
        sort($actions);
        $this->assertSame(['LicenseIssued', 'LicenseRevoked', 'PrefixCreated'], $actions);
    }

    private function swapRolePolicy(): void
    {
        // Reuse the in-memory fake defined in RoleGateTest.
        require_once __DIR__ . '/../RoleGateTest.php';
        \Tests\Feature\FakeHasRolePolicy::$grants = [];
        $this->app->instance(HasRolePolicy::class, new \Tests\Feature\FakeHasRolePolicy());
    }

    private function seedAuditRows(): void
    {
        $now = Carbon::now('UTC');
        $rows = [
            ['LicenseIssued',  'License', '{"Origin":"seeder","Note":"first"}',   $now->copy()->subMinutes(10)->toDateTimeString()],
            ['PrefixCreated',  'Prefix',  '{"Origin":"seeder"}',                  $now->copy()->subMinutes(5)->toDateTimeString()],
            ['LicenseRevoked', 'License', '{"Origin":"operator","Reason":"AbuseReport"}', $now->copy()->toDateTimeString()],
        ];
        foreach ($rows as [$action, $target, $payload, $createdAt]) {
            DB::connection('root')->table('AuditLogs')->insert([
                'ActorType' => 'User',
                'ActorId' => (int) $this->user->getKey(),
                'Action' => $action,
                'TargetType' => $target,
                'TargetId' => 1,
                'RequestId' => Uuid::uuid4()->toString(),
                'PayloadJson' => $payload,
                'CreatedAt' => $createdAt,
            ]);
        }
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

    private function makeUser(): User
    {
        $user = new User();
        $user->Email = self::EMAIL;
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
