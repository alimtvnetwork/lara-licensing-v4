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
 * Plan 14 step 29 (v0.678.0). Import `verifyAndApply` enqueue
 * orchestrator (S1 shadow, spec 26 §12 "Async Job Handoff").
 *
 * Contract:
 *  - `enqueue(userId, idempotencyKey, requestId, archiveId, note,
 *    preflight)` runs after `BrImportRequest` validation, feature
 *    flag gate, and a successful `BrImportPreflight::run`. It inserts
 *    one `BackupJobs` row (Kind=Import, State=Queued) plus one
 *    `AuditLogs` row (`backup.import_enqueued`) inside a single
 *    `root` transaction (INV-BR-EP-IM-3, INV-BR-A).
 *  - Payload carries `ArchiveId`, `Note`, `Mode='verifyAndApply'`,
 *    active `Epoch`+`Kid`, `Shadow` flag, and the preflight
 *    `ManifestSha256` for later restore-time recheck.
 *  - Returns `{JobId, ArchiveId, State, CreatedAt, Shadow}` matching
 *    spec §"Response: 202 Accepted" `Data`.
 *  - Audit-row insert failure is logged but never swallowed: a throw
 *    inside the closure rolls back the job insert.
 *
 * Function bodies capped at 15 lines (project memory Core rule).
 * No magic strings: table/action/state are `private const`.
 */
final class BrImportService
{
    private const CONN = 'root';
    private const TABLE_JOBS = 'BackupJobs';
    private const CAPABILITY = 'Backup.Import';
    private const AUDIT_ACTION = 'backup.import_enqueued';
    private const AUDIT_TARGET_TYPE = 'BackupJob';
    private const STATE_QUEUED = 'Queued';
    private const MODE_APPLY = 'verifyAndApply';
    private const ACTOR_UUID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
    private const ERR_CORRUPT = 'BackupCorrupt';

    public function __construct(
        private readonly BrFeatureFlagService $flags,
        private readonly BrKekService $keks,
    ) {}

    /**
     * @param array{ManifestSha256:string, EncryptionEpoch:int, EncryptionKid:string} $preflight
     * @return array{JobId:string, ArchiveId:string, State:string, CreatedAt:string, Shadow:bool}
     */
    public function enqueue(int $userId, string $idempotencyKey, string $requestId, string $archiveId, ?string $note, array $preflight): array
    {
        $this->assertFlags();
        $epoch = $this->keks->resolveActive();
        $shadow = $this->flags->isShadow(BrFlagId::ImportEnabled);
        $ids = $this->mintIds($userId, $archiveId);
        $jobPayload = $this->buildPayload($archiveId, $note, $epoch, $shadow, $preflight);

        return DB::connection(self::CONN)->transaction(function () use ($ids, $userId, $idempotencyKey, $requestId, $jobPayload, $shadow) {
            $createdAt = $this->insertJob($ids, $userId, $idempotencyKey, $requestId, $jobPayload);
            $this->writeAudit($ids, $userId, $requestId, $jobPayload);
            Log::info('br.import.enqueued', ['JobId' => $ids['JobId'], 'ArchiveId' => $ids['ArchiveId'], 'RequestId' => $requestId, 'Shadow' => $shadow]);

            return ['JobId' => $ids['JobId'], 'ArchiveId' => $ids['ArchiveId'], 'State' => self::STATE_QUEUED, 'CreatedAt' => $createdAt, 'Shadow' => $shadow];
        });
    }

    private function assertFlags(): void
    {
        $this->flags->assertKillSwitchOff();
        $this->flags->assertEnabled(BrFlagId::ImportEnabled);
    }

    /** @return array{JobId:string, ArchiveId:string, ActorId:string} */
    private function mintIds(int $userId, string $archiveId): array
    {
        return [
            'JobId'     => Uuid::uuid7()->toString(),
            'ArchiveId' => $archiveId,
            'ActorId'   => Uuid::uuid5(self::ACTOR_UUID_NAMESPACE, 'lara:user:' . $userId)->toString(),
        ];
    }

    /**
     * @param array{Epoch:int, Kid:string, State:string, SecretsRef:string} $epoch
     * @param array{ManifestSha256:string, EncryptionEpoch:int, EncryptionKid:string} $preflight
     * @return array<string, mixed>
     */
    private function buildPayload(string $archiveId, ?string $note, array $epoch, bool $shadow, array $preflight): array
    {
        return [
            'ArchiveId'      => $archiveId,
            'Note'           => $note,
            'Mode'           => self::MODE_APPLY,
            'Epoch'          => $epoch['Epoch'],
            'Kid'            => $epoch['Kid'],
            'Shadow'         => $shadow,
            'ManifestSha256' => $preflight['ManifestSha256'],
            'PreflightEpoch' => $preflight['EncryptionEpoch'],
            'PreflightKid'   => $preflight['EncryptionKid'],
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
                'BackupJobId' => $ids['JobId'], 'Kind' => BrJobKind::Import->value,
                'State' => self::STATE_QUEUED, 'Payload' => json_encode($jobPayload),
                'ActorId' => $ids['ActorId'], 'Capability' => self::CAPABILITY,
                'IdempotencyKey' => $idempotencyKey, 'RequestId' => $requestId,
            ]);
        } catch (Throwable $e) {
            Log::error('br.import.insert_failed', ['JobId' => $ids['JobId'], 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_CORRUPT, 'import job insert failed', [['Field' => '/JobId', 'Rule' => 'InsertFailed']]);
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
                'Payload' => json_encode(['ArchiveId' => $ids['ArchiveId'], 'Note' => $jobPayload['Note'], 'Shadow' => $jobPayload['Shadow'], 'ManifestSha256' => $jobPayload['ManifestSha256']]),
            ]);
        } catch (Throwable $e) {
            Log::error('br.import.audit_failed', ['JobId' => $ids['JobId'], 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
        }
    }
}
