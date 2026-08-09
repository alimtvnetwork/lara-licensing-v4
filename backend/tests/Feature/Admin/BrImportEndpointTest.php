<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\BR\BrFlagId;
use App\Http\Requests\Admin\BrImportRequest;
use App\Models\User;
use App\Policies\HasRolePolicy;
use App\Services\BR\BrImportPreflight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 14 step 28 (v0.677.0). Contract test for
 * `POST /Api/Admin/Backup/Imports` (verifyOnly slice, shipped v0.676.0).
 *
 * Root cause guarded (one sentence): the shadow Import endpoint routes
 * through `auth:sanctum` + `session.active` + `require.role:Admin|
 * SuperAdmin` + `IdempotencyKeyMiddleware` (`api/admin/backup/imports`
 * prefix) into `BrImportController@store`, which requires
 * `BrImportRequest` validation, `BrFeatureFlagService::assertKillSwitchOff`
 * + `assertEnabled(ImportEnabled)`, and `BrImportPreflight::run` (INV-BR-
 * RS-1: preflight never mutates). Any drift in that stack (missing
 * idempotency prefix, mis-wired closed set on `Mode`, ULID regex,
 * feature-flag gate ordering) would ship green without this lock.
 *
 * Branches guarded (5):
 *   1. Missing Idempotency-Key             -> 400 IdempotencyKeyRequired
 *   2. verifyAndApply mode                 -> 400 ValidationFailed
 *                                             (rule ModeNotYetSupported)
 *   3. Invalid ArchiveId (not ULID)        -> 400 ValidationFailed
 *   4. verifyOnly happy path (stubbed)     -> 200 + envelope carries
 *                                             ArchiveId, ManifestSha256,
 *                                             ChunkCount, Scopes, and
 *                                             Attributes.Mode=verifyOnly
 *   5. ImportEnabled flag = off            -> 501 FeatureNotAvailable
 *
 * `BrImportPreflight` is bound to an in-memory stub so this suite does
 * not need KEK material, a real archive on disk, or a valid manifest.
 */
final class BrImportEndpointTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-br-import@example.test';
    private const PASSWORD    = 'CorrectHorse!Battery42';
    private const VALID_ULID  = '01HZY9K3B2C4D6E8F0G2H4J6K8';
    private const IDEM_KEY    = 'k-brimp-11111111-2222-3333-4444-555555555555';

    private User $admin;
    private string $bearer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
        $this->seedFeatureFlags(importValue: 'on');
        $this->swapRolePolicy();
        $this->bindFakePreflight();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        $this->bearer = $this->openSessionAndMintToken($this->admin);
        \Tests\Feature\FakeHasRolePolicy::$grants =
            [(string) $this->admin->getKey() => ['Admin']];
    }

    public function test_missing_idempotency_key_returns_400(): void
    {
        Log::spy();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->postJson('/Api/Admin/Backup/Imports', $this->validBody());
        $this->assertLaraException($res, 'IdempotencyKeyRequired', 400);
    }

    public function test_verify_and_apply_enqueues_job_and_returns_202(): void
    {
        Log::spy();
        $this->bindFakeImportService();
        $body = $this->validBody();
        $body['Mode'] = BrImportRequest::MODE_VERIFY_AND_APPLY;
        $res = $this->withHeaders($this->authHeaders())
            ->postJson('/Api/Admin/Backup/Imports', $body);
        $res->assertStatus(202);
        $row = (array) $res->json('Results.0');
        $this->assertSame('job-brimp-fake', (string) ($row['JobId'] ?? ''));
        $this->assertSame(self::VALID_ULID, (string) ($row['ArchiveId'] ?? ''));
        $this->assertSame('Queued', (string) ($row['State'] ?? ''));
        $this->assertFalse((bool) ($row['Shadow'] ?? true));
        $this->assertSame(
            '/Api/Admin/Backup/Jobs/job-brimp-fake',
            (string) $res->headers->get('Location'),
        );
        $this->assertSame(
            BrImportRequest::MODE_VERIFY_AND_APPLY,
            (string) $res->json('Attributes.Mode'),
        );
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($m) => $m === 'br.import.endpoint.enqueued')
            ->atLeast()->once();
    }

    public function test_invalid_ulid_archive_id_returns_400_validation(): void
    {
        $body = $this->validBody();
        $body['ArchiveId'] = 'not-a-ulid';
        $res = $this->withHeaders($this->authHeaders())
            ->postJson('/Api/Admin/Backup/Imports', $body);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_verify_only_happy_path_returns_200_with_report(): void
    {
        Log::spy();
        $res = $this->withHeaders($this->authHeaders())
            ->postJson('/Api/Admin/Backup/Imports', $this->validBody());
        $res->assertStatus(200);
        $row = (array) $res->json('Results.0');
        $this->assertSame(self::VALID_ULID, (string) ($row['ArchiveId'] ?? ''));
        $this->assertSame('sha256-of-manifest', (string) ($row['ManifestSha256'] ?? ''));
        $this->assertSame(3, (int) ($row['ChunkCount'] ?? 0));
        $this->assertSame(7, (int) ($row['EncryptionEpoch'] ?? 0));
        $this->assertIsArray($row['Scopes'] ?? null);
        $this->assertSame(
            BrImportRequest::MODE_VERIFY_ONLY,
            (string) $res->json('Attributes.Mode'),
        );
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($m) => $m === 'br.import.endpoint.verified')
            ->atLeast()->once();
    }

    public function test_import_disabled_flag_returns_feature_not_available(): void
    {
        DB::connection('root')->table('FeatureFlags')
            ->where('FlagId', BrFlagId::ImportEnabled->value)
            ->update(['Value' => 'off']);
        // Purge cache: the service memoises within a request, but each
        // HTTP call boots a fresh container binding.
        $res = $this->withHeaders($this->authHeaders())
            ->postJson('/Api/Admin/Backup/Imports', $this->validBody());
        $this->assertLaraException($res, 'FeatureNotAvailable', 501);
    }

    /** @return array{ArchiveId:string, Mode:string, Note:string} */
    private function validBody(): array
    {
        return [
            'ArchiveId' => self::VALID_ULID,
            'Mode'      => BrImportRequest::MODE_VERIFY_ONLY,
            'Note'      => 'contract-test',
        ];
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return [
            'Authorization'   => 'Bearer ' . $this->bearer,
            'Idempotency-Key' => self::IDEM_KEY,
            'X-Request-Id'    => 'req-brimp-test-0001',
        ];
    }

    private function bindFakePreflight(): void
    {
        $this->app->instance(BrImportPreflight::class, new FakeBrImportPreflight());
    }

    private function bindFakeImportService(): void
    {
        $this->app->instance(\App\Services\BR\BrImportService::class, new FakeBrImportService());
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
        $root->statement('CREATE TABLE IF NOT EXISTS "IdempotencyRecords" (
            "IdempotencyRecordId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "Endpoint" TEXT NOT NULL,
            "ActorId" TEXT NOT NULL,
            "IdempotencyKey" TEXT NOT NULL,
            "BodyHash" TEXT NOT NULL,
            "ResponseStatus" INTEGER NOT NULL,
            "ResponseHeadersJson" TEXT NOT NULL,
            "ResponseBody" TEXT NOT NULL,
            "CreatedAt" TEXT NOT NULL,
            "ExpiresAt" TEXT NOT NULL,
            UNIQUE ("Endpoint", "ActorId", "IdempotencyKey")
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "FeatureFlags" (
            "FeatureFlagId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "FlagId" TEXT NOT NULL UNIQUE,
            "Value" TEXT NOT NULL
        )');
    }

    private function seedFeatureFlags(string $importValue): void
    {
        $rows = [
            ['FlagId' => BrFlagId::KillSwitch->value,     'Value' => 'off'],
            ['FlagId' => BrFlagId::ImportEnabled->value,  'Value' => $importValue],
        ];
        DB::connection('root')->table('FeatureFlags')->insert($rows);
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

        return $user->createToken($sessionId)->plainTextToken;
    }
}

/**
 * Stub for {@see BrImportPreflight}. Returns a deterministic report so
 * the endpoint contract can be verified without KEK material, an
 * on-disk archive, or a valid manifest. The real service is exercised
 * by `Tests\Feature\BR\BrImportPreflightTest`.
 */
final class FakeBrImportPreflight extends BrImportPreflight
{
    public function __construct()
    {
        // Skip parent constructor: real deps (storage/validator/reader/
        // KEKs/crypto) are not needed for the endpoint contract.
    }

    public function run(string $archiveId, string $currentAppVersion, string $requestId): array
    {
        return [
            'ArchiveId'       => $archiveId,
            'ManifestSha256'  => 'sha256-of-manifest',
            'ChunkCount'      => 3,
            'EncryptionEpoch' => 7,
            'EncryptionKid'   => 'kid-test',
            'Chunks'          => [],
            'Scopes'          => [
                'schema'   => ['ContentHash' => 'h', 'ActualHash' => 'h', 'Ok' => true, 'PlainBytes' => 10],
                'features' => ['ContentHash' => 'h', 'ActualHash' => 'h', 'Ok' => true, 'PlainBytes' => 20],
            ],
        ];
    }
}

/**
 * Stub for {@see \App\Services\BR\BrImportService}. Returns a
 * deterministic enqueue result so the endpoint contract can be
 * verified without a real BackupJobs table on the SQLite root
 * connection. Real service is exercised by
 * `Tests\Feature\BR\BrImportServiceTest` (follow-up step).
 */
final class FakeBrImportService extends \App\Services\BR\BrImportService
{
    public function __construct()
    {
        // Skip parent constructor: flags + keks not needed for endpoint contract.
    }

    public function enqueue(int $userId, string $idempotencyKey, string $requestId, string $archiveId, ?string $note, array $preflight): array
    {
        return [
            'JobId'     => 'job-brimp-fake',
            'ArchiveId' => $archiveId,
            'State'     => 'Queued',
            'CreatedAt' => '2026-07-21T00:00:00.000Z',
            'Shadow'    => false,
        ];
    }
}
