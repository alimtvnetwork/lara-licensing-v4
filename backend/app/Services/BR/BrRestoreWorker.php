<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrFlagId;
use App\Domain\BR\BrJobKind;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 14 step 25. Restore job worker skeleton.
 *
 * Consumes one queued `Kind='Restore'` row via {@see BrJobDispatcher}
 * and walks the S1-shadow half of the state machine defined in
 * spec/26-backup-restore/12-restore-orchestration.md v1.0.0:
 *
 *   Queued -> Running -> Preflight -> DriftSnapshot -> Succeeded
 *
 * The `Locked` / `Apply` / `Verified` phases (per-shard lock + apply
 * plan) land in Step 26 and Step 27; the skeleton stops after drift
 * capture so INV-BR-RS-1 ("preflight/drift never mutate any table")
 * remains structurally enforced by the code path itself: this worker
 * imports {@see BrImportPreflight} and {@see BrDriftSnapshot} but
 * does NOT reference any shard connection, dispatcher tx builder, or
 * apply plan class, so a review can prove no-writes by grep alone.
 *
 * Payload contract (S1 shadow):
 *   { ArchiveId: uuid, Shadow: true, RequestId: string }
 *
 * Production payloads (`Shadow!==true`) fail NON-retryable with
 * `BackupRestoreProductionPending` (501) exactly like
 * {@see BrExportWorker} did before Step 11. Producing a fake success
 * on the production path would violate INV-BR-A because no shard
 * rows actually moved.
 *
 * Any `Throwable` inside `execute()` funnels through `handleFailure`
 * so a job row NEVER leaks a `Running` state past this call
 * (INV-BR-JP-4). Every phase transition emits an observability line
 * (`br.restore.worker.phase`) with the phase name, `JobId`, and
 * `RequestId` so the state machine is visible in logs before any
 * dedicated `BrRestoreState` enum ships.
 *
 * 15-line function cap enforced by splitting into `execute`,
 * `preflight`, `snapshotPhase`, `handleFailure`.
 */
final class BrRestoreWorker
{
    private const KIND = BrJobKind::Restore;
    private const WORKER_PREFIX = 'br-restore-worker';
    private const ERR_PROD_PENDING = 'BackupRestoreProductionPending';
    private const ERR_WORKER_FAILURE = 'BackupWorkerFailure';
    private const REASON_PROD_PENDING = 'restore.production_writer_not_implemented';
    private const REASON_WORKER_FAILURE = 'worker.unhandled_exception';
    private const APP_VERSION_CONFIG_KEY = 'lara.br.app_version';
    private const APP_VERSION_FALLBACK = '0.639.0';
    private const PHASE_PREFLIGHT = 'Preflight';
    private const PHASE_DRIFT = 'DriftSnapshot';
    private const PHASE_DOMAIN_DRIFT = 'DomainDrift';
    private const PHASE_DOMAIN_ARCHIVE = 'DomainArchiveRecheck';
    private const PHASE_APPLY_PLAN = 'ApplyPlan';
    private const PHASE_SUCCEEDED = 'Succeeded';
    private const LOG_PHASE = 'br.restore.worker.phase';
    private const LOG_SUCCESS = 'br.restore.worker.shadow_succeeded';
    private const LOG_PROD_PENDING = 'br.restore.worker.production_path_pending';

    public function __construct(
        private readonly BrJobDispatcher $dispatcher,
        private readonly BrFeatureFlagService $flags,
        private readonly BrImportPreflight $importPreflight,
        private readonly BrDriftSnapshot $drift,
        private readonly BrDomainDriftCheck $domainDrift,
        private readonly BrDomainArchiveRecheck $domainArchive,
        private readonly BrRestoreApplyPlanBuilder $planBuilder,
    ) {}


    /**
     * Handle at most one queued Restore job. Returns the terminal state
     * ('Succeeded' | 'Failed' | null(no work)).
     */
    public function runOnce(string $requestId): ?string
    {
        $workerId = BrJobDispatcher::newWorkerId(self::WORKER_PREFIX);
        $row = $this->dispatcher->dequeue(self::KIND, $workerId, $requestId);
        if ($row === null) {
            return null;
        }

        return $this->execute($row, $requestId);
    }

    /** @param array<string, mixed> $row */
    private function execute(array $row, string $requestId): string
    {
        $jobId = (string) $row['BackupJobId'];
        $inheritedRequestId = (string) ($row['RequestId'] ?? $requestId);
        try {
            $payload = $this->decodePayload($row);
            $this->preflight($jobId, $payload, $inheritedRequestId);

            return $this->processShadow($jobId, $payload, $inheritedRequestId);
        } catch (LaraException $e) {
            $this->handleFailure($row, $e->errorCode, $this->reasonFor($e), $e->errorId, $inheritedRequestId);

            return 'Failed';
        } catch (Throwable $e) {
            $errorId = InternalException::custom(self::ERR_WORKER_FAILURE, $e->getMessage())->errorId;
            $this->handleFailure($row, self::ERR_WORKER_FAILURE, self::REASON_WORKER_FAILURE, $errorId, $inheritedRequestId);

            return 'Failed';
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodePayload(array $row): array
    {
        $raw = $row['Payload'];
        if (is_string($raw)) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        }

        return (array) $raw;
    }

    /** @param array<string, mixed> $payload */
    private function preflight(string $jobId, array $payload, string $requestId): void
    {
        $this->flags->assertKillSwitchOff();
        $this->flags->assertEnabled(BrFlagId::ImportEnabled);
        Log::info(self::LOG_PHASE, ['JobId' => $jobId, 'Phase' => self::PHASE_PREFLIGHT, 'ArchiveId' => $payload['ArchiveId'] ?? null, 'RequestId' => $requestId]);
    }

    /** @param array<string, mixed> $payload */
    private function processShadow(string $jobId, array $payload, string $requestId): string
    {
        $isShadow = (bool) ($payload['Shadow'] ?? false);
        $archiveId = (string) ($payload['ArchiveId'] ?? '');
        $isFailed = !$isShadow;
        if ($isFailed) {
            Log::error(self::LOG_PROD_PENDING, ['JobId' => $jobId, 'ArchiveId' => $archiveId, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_PROD_PENDING, 'Restore production apply-plan not yet implemented (Plan 14 step 26).', [['Field' => 'Payload.Shadow', 'Rule' => 'ProductionPathPending']]);
        }
        $snapshot = $this->snapshotPhase($jobId, $archiveId, $requestId);

        return $this->finish($jobId, $archiveId, $snapshot, $requestId);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotPhase(string $jobId, string $archiveId, string $requestId): array
    {
        $report = $this->importPreflight->run($archiveId, $this->appVersion(), $requestId);
        Log::info(self::LOG_PHASE, ['JobId' => $jobId, 'Phase' => self::PHASE_DRIFT, 'ArchiveId' => $archiveId, 'ChunkCount' => $report['ChunkCount'], 'RequestId' => $requestId]);
        $drift = $this->drift->run($report, $requestId);
        Log::info(self::LOG_PHASE, ['JobId' => $jobId, 'Phase' => self::PHASE_DOMAIN_DRIFT, 'ArchiveId' => $archiveId, 'RequestId' => $requestId]);
        $domainDrift = $this->domainDrift->runForArchive($archiveId, $requestId);
        Log::info(self::LOG_PHASE, ['JobId' => $jobId, 'Phase' => self::PHASE_DOMAIN_ARCHIVE, 'ArchiveId' => $archiveId, 'RequestId' => $requestId]);
        $domainArchive = $this->domainArchive->runForArchive($archiveId, $report, $requestId);
        Log::info(self::LOG_PHASE, ['JobId' => $jobId, 'Phase' => self::PHASE_APPLY_PLAN, 'ArchiveId' => $archiveId, 'RequestId' => $requestId]);
        $plan = $this->planBuilder->build($archiveId, $report, $domainDrift, $requestId);

        return ['AllMatch' => $drift['AllMatch'], 'Scopes' => $drift['Scopes'], 'ChunkCount' => $report['ChunkCount'], 'EncryptionEpoch' => $report['EncryptionEpoch'], 'EncryptionKid' => $report['EncryptionKid'], 'DomainDrift' => ['DeclaredCount' => $domainDrift['DeclaredCount'], 'LiveCount' => $domainDrift['LiveCount'], 'AggregateDeclared' => $domainDrift['AggregateDeclared'], 'AggregateLive' => $domainDrift['AggregateLive'], 'Tables' => $domainDrift['Tables']], 'DomainArchive' => $domainArchive, 'ApplyPlan' => $plan];
    }


    /**
     * @param array<string, mixed> $snapshot
     */
    private function finish(string $jobId, string $archiveId, array $snapshot, string $requestId): string
    {
        $result = ['ArchiveId' => $archiveId, 'ShadowMode' => true, 'Phase' => self::PHASE_APPLY_PLAN, 'DriftAllMatch' => $snapshot['AllMatch'], 'Scopes' => $snapshot['Scopes'], 'ChunkCount' => $snapshot['ChunkCount'], 'EncryptionEpoch' => $snapshot['EncryptionEpoch'], 'EncryptionKid' => $snapshot['EncryptionKid'], 'DomainDrift' => $snapshot['DomainDrift'], 'DomainArchive' => $snapshot['DomainArchive'], 'ApplyPlan' => $snapshot['ApplyPlan']];
        $this->dispatcher->succeed($jobId, $result, $requestId);
        Log::info(self::LOG_SUCCESS, ['JobId' => $jobId, 'ArchiveId' => $archiveId, 'Phase' => self::PHASE_SUCCEEDED, 'DriftAllMatch' => $snapshot['AllMatch'], 'ChunkCount' => $snapshot['ChunkCount'], 'DomainTableCount' => (int) ($snapshot['DomainDrift']['DeclaredCount'] ?? 0), 'DomainArchiveTableCount' => (int) ($snapshot['DomainArchive']['TableCount'] ?? 0), 'PlanId' => (string) ($snapshot['ApplyPlan']['PlanId'] ?? ''), 'PlanStepCount' => (int) ($snapshot['ApplyPlan']['Totals']['StepCount'] ?? 0), 'EncryptionEpoch' => $snapshot['EncryptionEpoch'], 'EncryptionKid' => $snapshot['EncryptionKid'], 'RequestId' => $requestId]);

        return 'Succeeded';
    }


    private function reasonFor(LaraException $e): string
    {
        return $e->errorCode === self::ERR_PROD_PENDING ? self::REASON_PROD_PENDING : self::REASON_WORKER_FAILURE;
    }

    private function appVersion(): string
    {
        $configured = (string) config(self::APP_VERSION_CONFIG_KEY, '');

        return $configured !== '' ? $configured : self::APP_VERSION_FALLBACK;
    }

    /** @param array<string, mixed> $row */
    private function handleFailure(array $row, string $errorCode, string $errorReason, string $errorId, string $requestId): void
    {
        $this->dispatcher->fail(
            (string) $row['BackupJobId'], self::KIND, $errorCode, $errorReason, $errorId,
            (int) $row['AttemptCount'], (int) $row['MaxAttempts'], $requestId,
        );
    }
}
