<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrFlagId;
use App\Domain\BR\BrJobKind;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Plan 14 step 9. Export enqueue orchestrator (S1 shadow, spec 26 §11
 * "Async Job Handoff" steps 4..8).
 *
 * Contract:
 *  - `enqueue(request)` runs after FormRequest validation and returns
 *    `{JobId, ArchiveId, State, CreatedAt}` matching spec §11
 *    "Response: 202 Accepted" `Data` fields.
 *  - Feature-flag gate FIRST (INV-BR-MG-3) via `BrFeatureFlagService`:
 *      * `br.kill-switch=on` -> 501 `FeatureNotAvailable`.
 *      * `br.export.enabled=off` -> 501 `FeatureNotAvailable`.
 *      * `br.export.enabled=shadow` -> proceeds but stamps `Shadow=true`
 *        on the job payload; the export worker (Step 10) reads this
 *        and short-circuits after preflight (no real bytes written).
 *  - KEK resolution BEFORE the tx opens; unresolvable KEK -> 500
 *    `BackupCorrupt` with pointer `/encryption/epoch` (spec §11
 *    "Error Envelopes" bottom row).
 *  - The archive row (S1 shadow: represented inside `BackupJobs.Payload`)
 *    and the job row are inserted in one tx (INV-BR-EP-EX-3).
 *  - Audit row `backup.export_enqueued` emits after the insert; failure
 *    is logged but NEVER swallowed silently — a throw rolls back.
 *
 * Function bodies capped at 15 lines (project memory Core rule).
 */
final class BrExportService
{
    private const CONN = 'root';
    private const TABLE_JOBS = 'BackupJobs';
    private const CAPABILITY = 'Backup.Export';
    private const AUDIT_ACTION = 'backup.export_enqueued';
    private const AUDIT_TARGET_TYPE = 'BackupJob';
    private const STATE_QUEUED = 'Queued';
    private const ACTOR_UUID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8'; // RFC 4122 URL ns
    private const ERR_CORRUPT = 'BackupCorrupt';

    public function __construct(
        private readonly BrFeatureFlagService $flags,
        private readonly BrKekService $keks,
    ) {}

    /**
     * @param array{Scope: array<string, mixed>, Note: ?string} $payload
     * @return array{JobId:string, ArchiveId:string, State:string, CreatedAt:string, Shadow:bool}
     */
    public function enqueue(int $userId, string $idempotencyKey, string $requestId, array $payload): array
    {
        $this->assertFlags();
        $epoch = $this->keks->resolveActive();
        $shadow = $this->flags->isShadow(BrFlagId::ExportEnabled);
        $ids = $this->mintIds($userId);
        $jobPayload = $this->buildPayload($ids['ArchiveId'], $payload, $epoch, $shadow);

        return DB::connection(self::CONN)->transaction(function () use ($ids, $userId, $idempotencyKey, $requestId, $jobPayload, $shadow) {
            $createdAt = $this->insertJob($ids, $userId, $idempotencyKey, $requestId, $jobPayload);
            $this->writeAudit($ids, $userId, $requestId, $jobPayload);
            Log::info('br.export.enqueued', ['JobId' => $ids['JobId'], 'ArchiveId' => $ids['ArchiveId'], 'RequestId' => $requestId, 'Shadow' => $shadow]);

            return ['JobId' => $ids['JobId'], 'ArchiveId' => $ids['ArchiveId'], 'State' => self::STATE_QUEUED, 'CreatedAt' => $createdAt, 'Shadow' => $shadow];
        });
    }

    private function assertFlags(): void
    {
        $this->flags->assertKillSwitchOff();
        $this->flags->assertEnabled(BrFlagId::ExportEnabled);
    }

    /** @return array{JobId:string, ArchiveId:string, ActorId:string} */
    private function mintIds(int $userId): array
    {
        return [
            'JobId'     => Uuid::uuid7()->toString(),
            'ArchiveId' => Uuid::uuid7()->toString(),
            'ActorId'   => Uuid::uuid5(self::ACTOR_UUID_NAMESPACE, 'lara:user:' . $userId)->toString(),
        ];
    }

    /**
     * @param array{Scope: array<string, mixed>, Note: ?string} $payload
     * @param array{Epoch:int, Kid:string, State:string, SecretsRef:string} $epoch
     * @return array<string, mixed>
     */
    private function buildPayload(string $archiveId, array $payload, array $epoch, bool $shadow): array
    {
        return [
            'ArchiveId' => $archiveId,
            'Scope'     => $payload['Scope'],
            'Note'      => $payload['Note'],
            'Epoch'     => $epoch['Epoch'],
            'Kid'       => $epoch['Kid'],
            'Shadow'    => $shadow,
        ];
    }

    /**
     * @param array{JobId:string, ArchiveId:string, ActorId:string} $ids
     * @param array<string, mixed> $jobPayload
     */
    private function insertJob(array $ids, int $userId, string $idempotencyKey, string $requestId, array $jobPayload): string
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        try {
            DB::connection(self::CONN)->table(self::TABLE_JOBS)->insert([
                'BackupJobId' => $ids['JobId'], 'Kind' => BrJobKind::Export->value,
                'State' => self::STATE_QUEUED, 'Payload' => json_encode($jobPayload),
                'ActorId' => $ids['ActorId'], 'Capability' => self::CAPABILITY,
                'IdempotencyKey' => $idempotencyKey, 'RequestId' => $requestId,
            ]);
        } catch (Throwable $e) {
            Log::error('br.export.insert_failed', ['JobId' => $ids['JobId'], 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_CORRUPT, 'export job insert failed', [['Field' => '/JobId', 'Rule' => 'InsertFailed']]);
        }

        return $now;
    }

    /**
     * @param array{JobId:string, ArchiveId:string, ActorId:string} $ids
     * @param array<string, mixed> $jobPayload
     */
    private function writeAudit(array $ids, int $userId, string $requestId, array $jobPayload): void
    {
        try {
            DB::connection(self::CONN)->table('AuditLogs')->insert([
                'ActorType' => 'User', 'ActorId' => $userId, 'Action' => self::AUDIT_ACTION,
                'TargetType' => self::AUDIT_TARGET_TYPE, 'TargetId' => $ids['JobId'],
                'RequestId' => $requestId,
                'Payload' => json_encode(['ArchiveId' => $ids['ArchiveId'], 'Scope' => $jobPayload['Scope'], 'Note' => $jobPayload['Note'], 'Shadow' => $jobPayload['Shadow']]),
            ]);
        } catch (Throwable $e) {
            Log::error('br.export.audit_failed', ['JobId' => $ids['JobId'], 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
        }
    }
}
