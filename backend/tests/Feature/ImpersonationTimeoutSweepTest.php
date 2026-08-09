<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ImpersonationTimeoutSweepCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 06 step 57. Locks `impersonation:timeout-sweep`
 * (ImpersonationTimeoutSweepCommand) per
 * spec/21-app/47-impersonation-server-handler.md §4:
 *
 *   AC-IMP-006a: Expired impersonation rows are closed with
 *                `EndedAt = ExpiresAt` (contractual end time, NOT NOW()).
 *   AC-IMP-006b: Both shard `AuthSessions` and Root `ImpersonationIndex`
 *                rows are stamped in the same transaction.
 *   AC-IMP-006c: One `ImpersonationEnded` audit row per closed session,
 *                RequestId prefixed `sweep-`, EndReason=Timeout.
 *   AC-IMP-006d: `AssertActiveSessionMiddleware` defence-in-depth: paired
 *                Sanctum bearer rows in `personal_access_tokens` (matched
 *                by `name = SessionId`) are deleted.
 *   AC-IMP-006e: Non-expired impersonation sessions and non-impersonation
 *                sessions with the same ExpiresAt shape are NOT touched.
 */
final class ImpersonationTimeoutSweepTest extends TestCase
{
    private const KIND_IMPERSONATION = 'Impersonation';
    private const KIND_LOGIN = 'Login';
    private const END_TIMEOUT = 'Timeout';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_expired_session_is_closed_with_contractual_ended_at_and_tokens_revoked(): void
    {
        $expiresAt = Carbon::now()->subMinutes(3)->startOfSecond();
        $this->seedAuthSession('sess-A', self::KIND_IMPERSONATION, expiresAt: $expiresAt, endedAt: null, operatorId: 10, userId: 20);
        $this->seedImpersonationIndex('sess-A', operatorId: 10, targetId: 20, expiresAt: $expiresAt, endedAt: null);
        $this->seedToken('sess-A');
        $this->seedToken('sess-A');

        $this->artisan(ImpersonationTimeoutSweepCommand::class, ['--batch' => 50])->assertExitCode(0);

        $sess = DB::connection('root')->table('AuthSessions')->where('SessionId', 'sess-A')->first();
        $this->assertNotNull($sess->EndedAt);
        $this->assertSame($expiresAt->toDateTimeString(), Carbon::parse((string) $sess->EndedAt)->toDateTimeString());
        $this->assertSame(self::END_TIMEOUT, $sess->RevokeReason);

        $idx = DB::connection('root')->table('ImpersonationIndex')->where('SessionId', 'sess-A')->first();
        $this->assertNotNull($idx->EndedAt);
        $this->assertSame(self::END_TIMEOUT, $idx->EndReason);

        $audit = DB::connection('root')->table('AuditLogs')->where('Action', 'ImpersonationEnded')->first();
        $this->assertNotNull($audit);
        $this->assertSame('sweep-sess-A', $audit->RequestId);

        $this->assertSame(0, DB::connection('root')->table('personal_access_tokens')->where('name', 'sess-A')->count());
    }

    public function test_non_expired_impersonation_is_left_untouched(): void
    {
        $expiresAt = Carbon::now()->addMinutes(5);
        $this->seedAuthSession('sess-Future', self::KIND_IMPERSONATION, $expiresAt, null, 10, 20);
        $this->seedImpersonationIndex('sess-Future', 10, 20, $expiresAt, null);

        $this->artisan(ImpersonationTimeoutSweepCommand::class, ['--batch' => 50])->assertExitCode(0);

        $sess = DB::connection('root')->table('AuthSessions')->where('SessionId', 'sess-Future')->first();
        $this->assertNull($sess->EndedAt);
        $this->assertSame(0, DB::connection('root')->table('AuditLogs')->count());
    }

    public function test_non_impersonation_session_is_filtered_out_by_kind(): void
    {
        // Login sessions can also expire but must not be closed by this sweep.
        $this->seedAuthSession('sess-Login', self::KIND_LOGIN, Carbon::now()->subHour(), null, null, 20);
        $this->artisan(ImpersonationTimeoutSweepCommand::class, ['--batch' => 50])->assertExitCode(0);
        $sess = DB::connection('root')->table('AuthSessions')->where('SessionId', 'sess-Login')->first();
        $this->assertNull($sess->EndedAt);
    }

    private function seedAuthSession(string $id, string $kind, Carbon $expiresAt, ?Carbon $endedAt, ?int $operatorId, int $userId): void
    {
        DB::connection('root')->table('AuthSessions')->insert([
            'SessionId' => $id,
            'Kind' => $kind,
            'UserId' => $userId,
            'ImpersonatorUserId' => $operatorId,
            'ExpiresAt' => $expiresAt->toDateTimeString(),
            'EndedAt' => $endedAt?->toDateTimeString(),
            'RevokeReason' => null,
            'CreatedAt' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function seedImpersonationIndex(string $sessionId, int $operatorId, int $targetId, Carbon $expiresAt, ?Carbon $endedAt): void
    {
        DB::connection('root')->table('ImpersonationIndex')->insert([
            'SessionId' => $sessionId,
            'ImpersonatorUserId' => $operatorId,
            'TargetUserId' => $targetId,
            'ExpiresAt' => $expiresAt->toDateTimeString(),
            'EndedAt' => $endedAt?->toDateTimeString(),
            'EndReason' => null,
        ]);
    }

    private function seedToken(string $sessionId): void
    {
        DB::connection('root')->table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => 10,
            'name' => $sessionId,
            'token' => bin2hex(random_bytes(20)),
            'abilities' => '["*"]',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function createTables(): void
    {
        $root = DB::connection('root');
        $root->statement(
            'CREATE TABLE IF NOT EXISTS "AuthSessions" (
                "SessionId" TEXT PRIMARY KEY,
                "Kind" TEXT NOT NULL,
                "UserId" INTEGER NULL,
                "ImpersonatorUserId" INTEGER NULL,
                "ExpiresAt" TEXT NOT NULL,
                "EndedAt" TEXT NULL,
                "RevokeReason" TEXT NULL,
                "CreatedAt" TEXT NOT NULL
            )'
        );
        $root->statement(
            'CREATE TABLE IF NOT EXISTS "ImpersonationIndex" (
                "SessionId" TEXT PRIMARY KEY,
                "ImpersonatorUserId" INTEGER NOT NULL,
                "TargetUserId" INTEGER NOT NULL,
                "ExpiresAt" TEXT NOT NULL,
                "EndedAt" TEXT NULL,
                "EndReason" TEXT NULL
            )'
        );
        $root->statement(
            'CREATE TABLE IF NOT EXISTS "AuditLogs" (
                "AuditLogId" INTEGER PRIMARY KEY AUTOINCREMENT,
                "ActorType" TEXT NOT NULL,
                "ActorId" INTEGER NULL,
                "Action" TEXT NOT NULL,
                "TargetType" TEXT NOT NULL,
                "TargetId" TEXT NULL,
                "RequestId" TEXT NOT NULL,
                "PayloadJson" TEXT NULL,
                "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $root->statement(
            'CREATE TABLE IF NOT EXISTS "Resellers" (
                "ResellerId" INTEGER PRIMARY KEY,
                "ResellerSlug" TEXT NOT NULL
            )'
        );
        $root->statement(
            'CREATE TABLE IF NOT EXISTS "personal_access_tokens" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "tokenable_type" TEXT NOT NULL,
                "tokenable_id" INTEGER NOT NULL,
                "name" TEXT NOT NULL,
                "token" TEXT NOT NULL,
                "abilities" TEXT NULL,
                "last_used_at" TEXT NULL,
                "expires_at" TEXT NULL,
                "created_at" TEXT NULL,
                "updated_at" TEXT NULL
            )'
        );
    }
}
