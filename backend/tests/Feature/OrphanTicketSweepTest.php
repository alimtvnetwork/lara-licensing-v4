<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SelfUpdateOrphanTicketSweepCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 06 step 56. Locks `retention:sweep-orphan-tickets`
 * (SelfUpdateOrphanTicketSweepCommand) per spec/21-app/17-self-update-endpoint.md
 * v1.3.0 §"Upload ticket expiry":
 *
 *   AC-SUS-001: Expired un-finalized rows are deleted, partial upload
 *               bytes on disk are unlinked, and BytesReclaimed reflects
 *               the file size.
 *   AC-SUS-002: Finalized rows are NEVER swept even when
 *               UploadTicketExpiresAt is in the past (retention policy
 *               applies to orphans only, not published assets).
 *   AC-SUS-003: Non-expired un-finalized rows (ticket still valid) are
 *               left in place.
 *   AC-SUS-004: One `UpdateAssetTicketExpired` audit row per swept
 *               ticket is inserted with RequestId prefixed `sweep-`.
 *   AC-SUS-005: `--batch` bounds a single run: with 3 expired rows and
 *               batch=1, exactly one row is removed per invocation.
 */
final class OrphanTicketSweepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_expired_orphan_is_deleted_and_bytes_unlinked(): void
    {
        $storage = sys_get_temp_dir() . '/lara-sweep-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($storage, 'orphan-bytes');
        $this->seedAsset(1, 'aaaaaaaa', Carbon::now()->subMinute(), finalized: 0, storage: $storage);

        $this->artisan(SelfUpdateOrphanTicketSweepCommand::class, ['--batch' => 50])->assertExitCode(0);

        $this->assertSame(0, DB::connection('root')->table('AppUpdateAssets')->count());
        $this->assertFileDoesNotExist($storage);
        $audit = DB::connection('root')->table('AuditLogs')->where('Action', 'UpdateAssetTicketExpired')->first();
        $this->assertNotNull($audit);
        $this->assertStringStartsWith('sweep-', (string) $audit->RequestId);
    }

    public function test_finalized_rows_are_never_swept(): void
    {
        // Expired but finalized — protected by the IsFinalized=0 filter.
        $this->seedAsset(2, 'bbbbbbbb', Carbon::now()->subDay(), finalized: 1, storage: null);
        $this->artisan(SelfUpdateOrphanTicketSweepCommand::class, ['--batch' => 50])->assertExitCode(0);
        $this->assertSame(1, DB::connection('root')->table('AppUpdateAssets')->count());
        $this->assertSame(0, DB::connection('root')->table('AuditLogs')->count());
    }

    public function test_non_expired_orphan_is_left_in_place(): void
    {
        $this->seedAsset(3, 'cccccccc', Carbon::now()->addHour(), finalized: 0, storage: null);
        $this->artisan(SelfUpdateOrphanTicketSweepCommand::class, ['--batch' => 50])->assertExitCode(0);
        $this->assertSame(1, DB::connection('root')->table('AppUpdateAssets')->count());
    }

    public function test_batch_option_bounds_a_single_run(): void
    {
        $this->seedAsset(4, 'dddddddd', Carbon::now()->subMinute(), finalized: 0, storage: null);
        $this->seedAsset(5, 'eeeeeeee', Carbon::now()->subMinute(), finalized: 0, storage: null);
        $this->seedAsset(6, 'ffffffff', Carbon::now()->subMinute(), finalized: 0, storage: null);
        $this->artisan(SelfUpdateOrphanTicketSweepCommand::class, ['--batch' => 1])->assertExitCode(0);
        $this->assertSame(2, DB::connection('root')->table('AppUpdateAssets')->count());
        $this->assertSame(1, DB::connection('root')->table('AuditLogs')->count());
    }

    private function seedAsset(int $id, string $token, Carbon $expires, int $finalized, ?string $storage): void
    {
        DB::connection('root')->table('AppUpdateAssets')->insert([
            'AppUpdateAssetId' => $id,
            'AppUpdateId' => $id,
            'Product' => 'lara-cli',
            'Version' => '1.0.' . $id,
            'Platform' => 'WindowsAmd64',
            'SizeBytes' => 12,
            'Sha256' => str_repeat('0', 64),
            'SignatureSha256' => null,
            'StoragePath' => $storage,
            'SignatureStoragePath' => null,
            'IsFinalized' => $finalized,
            'UploadToken' => str_pad($token, 64, $token),
            'UploadTicketExpiresAt' => $expires->toDateTimeString(),
            'CreatedAt' => Carbon::now()->toDateTimeString(),
            'FinalizedAt' => $finalized ? Carbon::now()->toDateTimeString() : null,
        ]);
    }

    private function createTables(): void
    {
        DB::connection('root')->statement(
            'CREATE TABLE IF NOT EXISTS "AppUpdateAssets" (
                "AppUpdateAssetId" INTEGER PRIMARY KEY,
                "AppUpdateId" INTEGER NOT NULL,
                "Product" TEXT NOT NULL,
                "Version" TEXT NOT NULL,
                "Platform" TEXT NOT NULL,
                "SizeBytes" INTEGER NOT NULL,
                "Sha256" TEXT NOT NULL,
                "SignatureSha256" TEXT NULL,
                "StoragePath" TEXT NULL,
                "SignatureStoragePath" TEXT NULL,
                "IsFinalized" INTEGER NOT NULL DEFAULT 0,
                "UploadToken" TEXT NOT NULL,
                "UploadTicketExpiresAt" TEXT NULL,
                "CreatedAt" TEXT NOT NULL,
                "FinalizedAt" TEXT NULL
            )'
        );
        DB::connection('root')->statement(
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
    }
}
