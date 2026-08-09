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
 * Plan 10 step 15 (Pest matrix, fifth `Admin/*` row: Admin/AppUpdatesPublishTest).
 *
 * Locks `POST /Api/Admin/AppUpdates` (App\Http\Controllers\Admin\AppUpdateController::publish,
 * lines 219-239) end-to-end through the admin middleware stack (auth:sanctum ->
 * session.active -> require.role:Admin|SuperAdmin, routes/api.php line 155)
 * plus the `AppUpdatePublishRequest` FormRequest.
 *
 * Root cause guarded (one sentence): publish is the ONLY write path that
 * flips `AppUpdateAssets.IsFinalized` 0 -> 1 inside a single transaction and
 * inserts the paired `AppUpdates` row (spec 17 v1.3.0 §"Publish state machine"
 * line 293-294, AC-SU-DB-002), so regressions that (a) let a non-Stable
 * Channel publish (spec §"v1.0 rollout policy"), (b) dropped the
 * platform-coverage guard (partial manifest reaches CLIs), (c) dropped
 * strict monotonic version comparison (downgrade attack), (d) skipped the
 * ticket Sha256/SizeBytes cross-check (byte swap between ticket and
 * finalize), (e) left orphan `AppUpdateAssets` rows with `IsFinalized=1`
 * but a NULL `AppUpdateId` when `StoragePath` bytes are missing, or (f)
 * failed to stamp `PublishedByUserId` with `auth()->id()` or write the
 * `UpdatePublished` audit row inside the transaction, would all ship green.
 *
 * Ten branches locked:
 *   1. Bare request -> 401 AuthUnauthorized.
 *   2. Authenticated non-Admin -> 403 AuthForbidden.
 *   3. Malformed `Version` body -> 400 ValidationInvalidVersion.
 *   4. Beta channel (passes closed set, fails V1StableOnly) -> 403 UpdateChannelForbidden.
 *   5. Assets[] missing one of the three declared platforms -> 400 ValidationInputInvalid.
 *   6. Version <= existing PublishedAt Version -> 409 UpdateVersionDowngradeBlocked.
 *   7. Unknown UploadToken -> 502 UpdateAssetUploadFailed (TicketNotOpen).
 *   8. Ticket Sha256 mismatch -> 409 UpdateAssetVerificationFailed.
 *   9. Ticket StoragePath bytes missing -> 502 UpdateAssetUploadFailed (BytesMissing).
 *  10. Happy path -> 201: `AppUpdates` row inserted with `PublishedByUserId`=actor,
 *      all three `AppUpdateAssets` rows finalized (IsFinalized=1, AppUpdateId set,
 *      UploadToken cleared), and `UpdatePublished` audit row written inside
 *      the transaction.
 */
final class AppUpdatesPublishTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-publish@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const PRODUCT = 'lara-cli';
    private const PLATFORMS = ['WindowsAmd64', 'LinuxAmd64', 'DarwinArm64'];

    private User $admin;
    private string $adminBearer;

    /** @var array<int,string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Force checksum-only mode: no signing key configured -> AssetSigner::isConfigured() false.
        config()->set('lara.self_update.signing_key_path', '');
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        [, $this->adminBearer] = $this->openSessionAndMintToken($this->admin);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
            if (is_file($p . '.sig')) {
                @unlink($p . '.sig');
            }
        }
        parent::tearDown();
    }

    public function test_bare_returns_401(): void
    {
        $res = $this->postJson('/Api/Admin/AppUpdates', $this->minimalBody());
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_non_admin_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $this->minimalBody());
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_malformed_version_returns_400(): void
    {
        $this->grantAdmin();
        $body = $this->minimalBody();
        $body['Version'] = 'not-a-version!';
        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $body);
        $this->assertLaraException($res, 'ValidationInvalidVersion', 400);
    }

    public function test_non_stable_channel_returns_403(): void
    {
        $this->grantAdmin();
        $body = $this->minimalBody();
        $body['Channel'] = 'Beta';
        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $body);
        $this->assertLaraException($res, 'UpdateChannelForbidden', 403);
    }

    public function test_platform_coverage_gap_returns_400(): void
    {
        $this->grantAdmin();
        $body = $this->buildBodyWithSeededTickets('2.0.0');
        // Drop one platform to break coverage.
        array_pop($body['Assets']);
        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $body);
        $this->assertLaraException($res, 'ValidationInputInvalid', 400);
    }

    public function test_version_downgrade_returns_409(): void
    {
        $this->grantAdmin();
        $this->seedPublished('3.0.0');
        $body = $this->buildBodyWithSeededTickets('2.0.0');
        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $body);
        $this->assertLaraException($res, 'UpdateVersionDowngradeBlocked', 409);
    }

    public function test_unknown_upload_token_returns_502(): void
    {
        $this->grantAdmin();
        $body = $this->buildBodyWithSeededTickets('2.0.0');
        // Overwrite the first token with a syntactically valid but unknown hex.
        $body['Assets'][0]['UploadToken'] = str_repeat('a', 64);
        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $body);
        $this->assertLaraException($res, 'UpdateAssetUploadFailed', 502);
    }

    public function test_ticket_sha_mismatch_returns_409(): void
    {
        $this->grantAdmin();
        $body = $this->buildBodyWithSeededTickets('2.0.0');
        // Flip the declared Sha256 to a valid-shape but wrong digest.
        $body['Assets'][0]['Sha256'] = str_repeat('b', 64);
        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $body);
        $this->assertLaraException($res, 'UpdateAssetVerificationFailed', 409);
    }

    public function test_missing_bytes_returns_502(): void
    {
        $this->grantAdmin();
        // Seed tickets but delete the underlying file for one of them so
        // is_file(StoragePath) fails inside assertTicketFinalizable().
        $body = $this->buildBodyWithSeededTickets('2.0.0');
        // Locate the tempfile behind Assets[0] and unlink it.
        $row = DB::connection('root')->table('AppUpdateAssets')
            ->where('UploadToken', $body['Assets'][0]['UploadToken'])
            ->first();
        $this->assertNotNull($row);
        @unlink((string) $row->StoragePath);
        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $body);
        $this->assertLaraException($res, 'UpdateAssetUploadFailed', 502);
    }

    public function test_happy_path_finalizes_assets_and_writes_audit(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];
        $body = $this->buildBodyWithSeededTickets('2.0.0');

        $res = $this->authed()->postJson('/Api/Admin/AppUpdates', $body);
        $res->assertStatus(201);
        $this->assertSame('2.0.0', $res->json('Results.0.LatestVersion'));
        $this->assertSame('Stable', $res->json('Results.0.Channel'));
        $this->assertCount(3, (array) $res->json('Results.0.Assets'));

        // AppUpdates row inserted with actor stamped (never client-supplied).
        $update = DB::connection('root')->table('AppUpdates')
            ->where('Product', self::PRODUCT)->where('Version', '2.0.0')->first();
        $this->assertNotNull($update, 'AppUpdates row must be inserted inside the publish transaction.');
        $this->assertSame((int) $this->admin->getKey(), (int) $update->PublishedByUserId, 'PublishedByUserId must be auth()->id().');
        $this->assertSame(0, (int) $update->IsYanked);

        // Every ticket flipped IsFinalized=0 -> 1, cleared UploadToken, and points to the new AppUpdateId.
        $assets = DB::connection('root')->table('AppUpdateAssets')
            ->where('Version', '2.0.0')->get();
        $this->assertCount(3, $assets, 'All three platform tickets must finalise atomically.');
        foreach ($assets as $a) {
            $this->assertSame(1, (int) $a->IsFinalized, 'IsFinalized must flip to 1 for every ticket.');
            $this->assertSame((int) $update->AppUpdateId, (int) $a->AppUpdateId, 'Finalised asset must link to the new AppUpdateId.');
            $this->assertNull($a->UploadToken, 'UploadToken must be cleared post-finalise (single-use).');
            $this->assertNotNull($a->FinalizedAt, 'FinalizedAt must be stamped inside the publish transaction.');
        }

        // Audit row written inside the same transaction.
        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'UpdatePublished')
            ->where('TargetType', 'AppUpdates')
            ->where('TargetId', (int) $update->AppUpdateId)
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must write UpdatePublished before COMMIT.');
    }

    /*
     |----------------------------------------------------------------
     | Helpers.
     */

    private function authed(): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer);
    }

    private function grantAdmin(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
    }

    /** @return array<string,mixed> */
    private function minimalBody(): array
    {
        return [
            'Product' => self::PRODUCT,
            'Channel' => 'Stable',
            'Version' => '2.0.0',
            'MinRequiredVersion' => '1.0.0',
            'ReleaseNotesUrl' => null,
            'Assets' => [
                ['Platform' => 'WindowsAmd64', 'Sha256' => str_repeat('0', 64), 'SizeBytes' => 1, 'UploadToken' => str_repeat('0', 64)],
                ['Platform' => 'LinuxAmd64', 'Sha256' => str_repeat('0', 64), 'SizeBytes' => 1, 'UploadToken' => str_repeat('0', 64)],
                ['Platform' => 'DarwinArm64', 'Sha256' => str_repeat('0', 64), 'SizeBytes' => 1, 'UploadToken' => str_repeat('0', 64)],
            ],
        ];
    }

    /**
     * Seed one open ticket per platform with real bytes on disk so
     * `assertTicketFinalizable` passes end-to-end. Returns a body ready
     * for `POST /Api/Admin/AppUpdates`.
     *
     * @return array<string,mixed>
     */
    private function buildBodyWithSeededTickets(string $version): array
    {
        $assets = [];
        foreach (self::PLATFORMS as $platform) {
            $bytes = 'lara-bin-' . $platform . '-' . $version;
            $path = tempnam(sys_get_temp_dir(), 'lara_asset_');
            file_put_contents($path, $bytes);
            $this->tempFiles[] = $path;
            $sha = hash('sha256', $bytes);
            $size = strlen($bytes);
            $token = bin2hex(random_bytes(32));
            DB::connection('root')->table('AppUpdateAssets')->insert([
                'AppUpdateId' => null,
                'Product' => self::PRODUCT,
                'Version' => $version,
                'Platform' => $platform,
                'SizeBytes' => $size,
                'Sha256' => $sha,
                'SignatureSha256' => null,
                'StoragePath' => $path,
                'SignatureStoragePath' => null,
                'IsFinalized' => 0,
                'UploadToken' => $token,
                'UploadTicketExpiresAt' => Carbon::now('UTC')->addMinutes(30)->toDateTimeString(),
                'CreatedAt' => Carbon::now('UTC')->toDateTimeString(),
                'FinalizedAt' => null,
            ]);
            $assets[] = [
                'Platform' => $platform,
                'Sha256' => $sha,
                'SizeBytes' => $size,
                'UploadToken' => $token,
            ];
        }

        return [
            'Product' => self::PRODUCT,
            'Channel' => 'Stable',
            'Version' => $version,
            'MinRequiredVersion' => '1.0.0',
            'ReleaseNotesUrl' => null,
            'Assets' => $assets,
        ];
    }

    private function seedPublished(string $version): void
    {
        $now = Carbon::now('UTC')->toDateTimeString();
        DB::connection('root')->table('AppUpdates')->insert([
            'Product' => self::PRODUCT,
            'Channel' => 'Stable',
            'Version' => $version,
            'MinRequiredVersion' => '0.1.0',
            'ReleaseNotesUrl' => null,
            'PublishedAt' => $now,
            'PublishedByUserId' => (int) $this->admin->getKey(),
            'IsYanked' => 0,
            'YankedAt' => null,
            'YankedByUserId' => null,
        ]);
    }

    private function swapRolePolicy(): void
    {
        require_once __DIR__ . '/../RoleGateTest.php';
        \Tests\Feature\FakeHasRolePolicy::$grants = [];
        $this->app->instance(HasRolePolicy::class, new \Tests\Feature\FakeHasRolePolicy());
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
        $root->statement('CREATE TABLE IF NOT EXISTS "AppUpdateAssets" (
            "AppUpdateAssetId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "AppUpdateId" INTEGER NULL,
            "Product" TEXT NULL,
            "Version" TEXT NULL,
            "Platform" TEXT NOT NULL,
            "SizeBytes" INTEGER NOT NULL,
            "Sha256" TEXT NOT NULL,
            "SignatureSha256" TEXT NULL,
            "StoragePath" TEXT NULL,
            "SignatureStoragePath" TEXT NULL,
            "IsFinalized" INTEGER NOT NULL DEFAULT 0,
            "UploadToken" TEXT NULL,
            "UploadTicketExpiresAt" TEXT NULL,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "FinalizedAt" TEXT NULL
        )');
    }
}
