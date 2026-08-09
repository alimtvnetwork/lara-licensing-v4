<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Domain\BR\BrJobKind;
use App\Domain\BR\BrJobState;
use App\Exceptions\LaraException;
use App\Services\BR\BrDriftSnapshot;
use App\Services\BR\BrFeatureFlagService;
use App\Services\BR\BrImportPreflight;
use App\Services\BR\BrJobDispatcher;
use App\Services\BR\BrRestoreWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Plan 14 step 25 contract tests for BrRestoreWorker.
 *
 * Locks:
 *  - Enqueue a `Kind=Restore, Shadow=true` job -> `runOnce` dequeues,
 *    runs preflight + drift snapshot (both stubbed), transitions the
 *    row to `Succeeded`, and writes the drift map into `Result`.
 *  - A `Shadow!==true` payload fails NON-retryable with
 *    `BackupRestoreProductionPending` and the row lands in `Failed`.
 *  - No `runOnce` call touches any shard connection (grep enforced by
 *    absence in the source; here we only assert the DB `BackupJobs`
 *    row moved to a terminal state).
 */
final class BrRestoreWorkerTest extends TestCase
{
    use RefreshDatabase;

    private const REQ = 'req-restore-0001';
    private const ARCHIVE = '44444444-4444-4444-8444-444444444444';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_shadow_restore_reaches_succeeded_with_drift_map(): void
    {
        $this->stubFlags();
        $this->stubPreflight();
        $this->stubDrift(true);
        $jobId = $this->enqueue(['ArchiveId' => self::ARCHIVE, 'Shadow' => true]);
        $terminal = app(BrRestoreWorker::class)->runOnce(self::REQ);
        $this->assertSame('Succeeded', $terminal);
        $row = $this->fetch($jobId);
        $this->assertSame(BrJobState::Succeeded->value, $row->State);
        $result = json_decode((string) $row->Result, true);
        $this->assertTrue($result['DriftAllMatch']);
        $this->assertSame('DriftSnapshot', $result['Phase']);
        $this->assertSame(self::ARCHIVE, $result['ArchiveId']);
    }

    public function test_production_payload_fails_non_retryable(): void
    {
        $this->stubFlags();
        $jobId = $this->enqueue(['ArchiveId' => self::ARCHIVE, 'Shadow' => false]);
        $terminal = app(BrRestoreWorker::class)->runOnce(self::REQ);
        $this->assertSame('Failed', $terminal);
        $row = $this->fetch($jobId);
        $this->assertSame(BrJobState::Failed->value, $row->State);
        $this->assertSame('BackupRestoreProductionPending', $row->ErrorCode);
    }

    private function stubFlags(): void
    {
        $mock = Mockery::mock(BrFeatureFlagService::class);
        $mock->shouldReceive('assertKillSwitchOff')->andReturnNull();
        $mock->shouldReceive('assertEnabled')->andReturnNull();
        $this->app->instance(BrFeatureFlagService::class, $mock);
    }

    private function stubPreflight(): void
    {
        $mock = Mockery::mock(BrImportPreflight::class);
        $mock->shouldReceive('run')->andReturn([
            'ArchiveId' => self::ARCHIVE,
            'ManifestSha256' => str_repeat('a', 64),
            'ChunkCount' => 7,
            'EncryptionEpoch' => 51,
            'EncryptionKid' => 'epoch-51',
            'Chunks' => [],
            'Scopes' => ['schema' => ['ContentHash' => 'x', 'ActualHash' => 'x', 'Ok' => true, 'PlainBytes' => 0]],
        ]);
        $this->app->instance(BrImportPreflight::class, $mock);
    }

    private function stubDrift(bool $allMatch): void
    {
        $mock = Mockery::mock(BrDriftSnapshot::class);
        $mock->shouldReceive('run')->andReturn([
            'ArchiveId' => self::ARCHIVE,
            'RequestId' => self::REQ,
            'AllMatch' => $allMatch,
            'Scopes' => ['schema' => ['Declared' => 'x', 'Live' => 'x', 'Match' => true, 'Skipped' => false]],
        ]);
        $this->app->instance(BrDriftSnapshot::class, $mock);
    }

    /** @param array<string, mixed> $payload */
    private function enqueue(array $payload): string
    {
        $jobId = Uuid::uuid7()->toString();
        DB::connection(BrJobDispatcher::CONN)->table('BackupJobs')->insert([
            'BackupJobId' => $jobId,
            'Kind' => BrJobKind::Restore->value,
            'State' => BrJobState::Queued->value,
            'Payload' => json_encode($payload),
            'RequestId' => self::REQ,
            'AttemptCount' => 0,
            'MaxAttempts' => 3,
            'CreatedAt' => now(),
            'UpdatedAt' => now(),
        ]);

        return $jobId;
    }

    private function fetch(string $jobId): object
    {
        /** @var object $row */
        $row = DB::connection(BrJobDispatcher::CONN)->table('BackupJobs')->where('BackupJobId', $jobId)->first();

        return $row;
    }
}
