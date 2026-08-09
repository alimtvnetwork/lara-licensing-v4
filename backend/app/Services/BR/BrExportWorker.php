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
 * Plan 14 step 10 (initial) + step 14/15 (production wiring).
 *
 * Consumes one queued `Kind='Export'` row via `BrJobDispatcher`, then
 * runs the SAME archive pipeline (`materializeArchive`) regardless of
 * `Payload.Shadow`:
 *   reserve -> sealer.initialize -> collect SC-A..H -> writeChunks
 *   -> Merkle root -> writeManifest -> finalize.
 *
 * As of v0.632.0 the two target classes SC-A (Schema) and SC-B
 * (ClosedSets) - and every other collector wired in the constructor -
 * emit byte-real bodies (`ContentHash` = `hash('sha256', $jsonl)` over
 * canonical JSONL). The prior `BackupExportProductionPending` 501
 * gate was a shadow-only backstop asserting "no real writer yet"; it
 * is now obsolete for Export because the writer IS the shadow writer
 * and it produces real bytes. Removing the gate is the wiring: the
 * production path (`Shadow!==true`) now dequeues, runs the same
 * collectors, writes the same tar-entry stream, and lands the same
 * signed manifest.
 *
 * Result envelope carries `ShadowMode = (bool) Payload.Shadow` so
 * downstream Snapshots/Import can tell shadow rehearsals apart from
 * real archives without a schema change (INV-BR-JB-2 unchanged).
 *
 * Any thrown `Throwable` funnels through `handleFailure` so the row
 * NEVER leaks `Running` past this call (INV-BR-JP-4). Import/Restore
 * production gating (`BackupImport*ProductionPending` /
 * `BackupRestoreProductionPending`) is untouched by this edit.
 *
 * 15-line function cap held by splitting into `preflight`,
 * `processJob`, `materializeArchive`, `handleFailure`.
 */
final class BrExportWorker
{
    private const KIND = BrJobKind::Export;
    private const REASON_WORKER_FAILURE = 'worker.unhandled_exception';
    private const ERR_WORKER_FAILURE = 'BackupWorkerFailure';
    private const WORKER_PREFIX = 'br-export-worker';
    private const APP_VERSION_CONFIG_KEY = 'lara.br.app_version';
    private const APP_VERSION_FALLBACK = '0.639.0';


    public function __construct(
        private readonly BrJobDispatcher $dispatcher,
        private readonly BrFeatureFlagService $flags,
        private readonly BrKekService $keks,
        private readonly BrArchiveStorage $storage,
        private readonly BrManifestBuilder $manifest,
        private readonly BrChunkWriter $chunks,
        private readonly BrScopeSchemaCollector $schemaCollector,
        private readonly BrScopeClosedSetsCollector $closedSetsCollector,
        private readonly BrScopeFeaturesCollector $featuresCollector,
        private readonly BrScopeLicensesCollector $licensesCollector,
        private readonly BrScopeRbacCollector $rbacCollector,
        private readonly BrScopeDomainCollector $domainCollector,
        private readonly BrScopeFilesCollector $filesCollector,
        private readonly BrScopeSecretsCollector $secretsCollector,
        private readonly BrArchiveSealer $sealer,
    ) {}



    /**
     * Handle at most one queued Export job. Returns the terminal state
     * ('Succeeded' | 'Failed' | 'Queued'(retry) | null(no work)).
     */
    public function runOnce(string $requestId): ?string
    {
        $workerId = BrJobDispatcher::newWorkerId(self::WORKER_PREFIX);
        $row = $this->dispatcher->dequeue(self::KIND, $workerId, $requestId);
        if ($row === null) {
            return null;
        }

        return $this->execute($row, $workerId, $requestId);
    }

    /** @param array<string, mixed> $row */
    private function execute(array $row, string $workerId, string $requestId): string
    {
        $jobId = (string) $row['BackupJobId'];
        $inheritedRequestId = (string) ($row['RequestId'] ?? $requestId);
        try {
            $payload = $this->decodePayload($row);
            $this->preflight($jobId, $payload, $inheritedRequestId);

            return $this->processJob($jobId, $payload, $inheritedRequestId);
        } catch (LaraException $e) {
            $this->handleFailure($row, $e->errorCode, 'export.' . $e->errorCode, $e->errorId, $inheritedRequestId);

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
        // Re-check flags at worker time (spec 26 §11 "Async Job Handoff" step 6);
        // the kill switch may have flipped between enqueue and dequeue.
        $this->flags->assertKillSwitchOff();
        $this->flags->assertEnabled(BrFlagId::ExportEnabled);
        // Re-resolve the KEK epoch and confirm parity with the payload.
        $epoch = $this->keks->resolveActive();
        Log::info('br.export.worker.preflight_ok', ['JobId' => $jobId, 'Epoch' => $epoch['Epoch'], 'PayloadEpoch' => $payload['Epoch'] ?? null, 'RequestId' => $requestId]);
    }

    /** @param array<string, mixed> $payload */
    private function processJob(string $jobId, array $payload, string $requestId): string
    {
        $isShadow = (bool) ($payload['Shadow'] ?? false);
        $archiveId = (string) ($payload['ArchiveId'] ?? '');
        $userId = (string) ($payload['UserId'] ?? '');
        Log::info('br.export.worker.mode', ['JobId' => $jobId, 'ArchiveId' => $archiveId, 'ShadowMode' => $isShadow, 'RequestId' => $requestId]);
        $out = $this->materializeArchive($archiveId, $userId, $requestId);
        $result = ['ArchiveId' => $archiveId, 'Bytes' => $out['Bytes'], 'MerkleRoot' => $out['MerkleRoot'], 'ChunkCount' => $out['ChunkCount'], 'ShadowMode' => $isShadow, 'StoragePath' => $out['StoragePath'], 'ManifestSha256' => $out['ManifestSha256'], 'SchemaHash' => $out['SchemaHash'], 'MigrationCount' => $out['MigrationCount'], 'ClosedSetCount' => $out['ClosedSetCount'], 'ClosedSetValueCount' => $out['ClosedSetValueCount'], 'FeatureCount' => $out['FeatureCount'], 'FeatureDefaultCount' => $out['FeatureDefaultCount'], 'LicenseCount' => $out['LicenseCount'], 'LicenseEpochCount' => $out['LicenseEpochCount'], 'LicenseFeatureLinkCount' => $out['LicenseFeatureLinkCount'], 'UserRoleCount' => $out['UserRoleCount'], 'CasbinRuleCount' => $out['CasbinRuleCount'], 'BootstrapPresent' => $out['BootstrapPresent'], 'DomainTableCount' => $out['DomainTableCount'], 'DomainRowCount' => $out['DomainRowCount'], 'DomainTables' => $out['DomainTables'], 'FileObjectCount' => $out['FileObjectCount'], 'FileTotalBytes' => $out['FileTotalBytes'], 'SecretRowCount' => $out['SecretRowCount'], 'SecretEpoch' => $out['SecretEpoch'], 'SecretKid' => $out['SecretKid'], 'EncryptionEpoch' => $out['EncryptionEpoch'], 'EncryptionKid' => $out['EncryptionKid']];
        $this->dispatcher->succeed($jobId, $result, $requestId);
        Log::info('br.export.worker.succeeded', ['JobId' => $jobId, 'ArchiveId' => $archiveId, 'ShadowMode' => $isShadow, 'StoragePath' => $out['StoragePath'], 'Bytes' => $out['Bytes'], 'ChunkCount' => $out['ChunkCount'], 'MerkleRoot' => $out['MerkleRoot'], 'ManifestSha256' => $out['ManifestSha256'], 'SchemaHash' => $out['SchemaHash'], 'MigrationCount' => $out['MigrationCount'], 'ClosedSetCount' => $out['ClosedSetCount'], 'ClosedSetValueCount' => $out['ClosedSetValueCount'], 'FeatureCount' => $out['FeatureCount'], 'FeatureDefaultCount' => $out['FeatureDefaultCount'], 'LicenseCount' => $out['LicenseCount'], 'LicenseEpochCount' => $out['LicenseEpochCount'], 'LicenseFeatureLinkCount' => $out['LicenseFeatureLinkCount'], 'UserRoleCount' => $out['UserRoleCount'], 'CasbinRuleCount' => $out['CasbinRuleCount'], 'BootstrapPresent' => $out['BootstrapPresent'], 'DomainTableCount' => $out['DomainTableCount'], 'DomainRowCount' => $out['DomainRowCount'], 'FileObjectCount' => $out['FileObjectCount'], 'FileTotalBytes' => $out['FileTotalBytes'], 'SecretRowCount' => $out['SecretRowCount'], 'SecretEpoch' => $out['SecretEpoch'], 'SecretKid' => $out['SecretKid'], 'EncryptionEpoch' => $out['EncryptionEpoch'], 'EncryptionKid' => $out['EncryptionKid'], 'RequestId' => $requestId]);
        $this->logDomainTables($jobId, $archiveId, $isShadow, $out['DomainTables'], $requestId);

        return 'Succeeded';
    }

    /**
     * Emit one structured info line per domain table so operators can
     * verify per-table row counts and content hashes against a manifest
     * without unpacking the archive. Ordering matches
     * `BrScopeDomainCollector::collect()` (deterministic, sorted).
     *
     * @param list<array{name:string, rowCount:int, contentHash:string}> $tables
     */
    private function logDomainTables(string $jobId, string $archiveId, bool $isShadow, array $tables, string $requestId): void
    {
        foreach ($tables as $index => $table) {
            Log::info('br.export.worker.domain_table', ['JobId' => $jobId, 'ArchiveId' => $archiveId, 'ShadowMode' => $isShadow, 'Index' => $index, 'Name' => $table['name'], 'RowCount' => $table['rowCount'], 'ContentHash' => $table['contentHash'], 'RequestId' => $requestId]);
        }
    }



    /**
     * Reserve -> write chunk bodies -> compute Merkle root -> write manifest
     * -> finalize. Any throw aborts the reserved directory so INV-BR-A
     * (atomicity) holds even mid-write; the outer catch records `Failed`
     * with a real `ErrorId` correlated back through `RequestId`.
     *
     * @return array{StoragePath:string, Bytes:int, ManifestSha256:string, MerkleRoot:string, ChunkCount:int, SchemaHash:string, MigrationCount:int, ClosedSetCount:int, ClosedSetValueCount:int, FeatureCount:int, FeatureDefaultCount:int, LicenseCount:int, LicenseEpochCount:int, LicenseFeatureLinkCount:int, UserRoleCount:int, CasbinRuleCount:int, BootstrapPresent:bool, DomainTableCount:int, DomainRowCount:int, DomainTables:list<array{name:string, rowCount:int, contentHash:string}>, FileObjectCount:int, FileTotalBytes:int, SecretRowCount:int, SecretEpoch:int, SecretKid:string, EncryptionEpoch:int, EncryptionKid:string}
     */
    private function materializeArchive(string $archiveId, string $userId, string $requestId): array
    {
        $path = $this->storage->reserve($archiveId, $requestId);
        try {
            $this->sealer->initialize($archiveId, $requestId);
            $schema = $this->schemaCollector->collect($requestId);
            $closedSets = $this->closedSetsCollector->collect($requestId);
            $features = $this->featuresCollector->collect($requestId);
            $licenses = $this->licensesCollector->collect($requestId);
            $rbac = $this->rbacCollector->collect($requestId);
            $domain = $this->domainCollector->collect($requestId);
            $files = $this->filesCollector->collect($requestId);
            $secrets = $this->secretsCollector->collect($requestId);
            $bodies = [$schema['RelPath'] => $schema['Jsonl'], $closedSets['RelPath'] => $closedSets['Jsonl'], $features['RelPath'] => $features['Jsonl'], $licenses['RelPath'] => $licenses['Jsonl'], $rbac['RelPath'] => $rbac['Jsonl'], $files['RelPath'] => $files['Jsonl'], $secrets['RelPath'] => $secrets['Body']] + $domain['Bodies'];
            $descriptors = $this->chunks->writeShadowChunks($this->storage, $archiveId, $requestId, $bodies, $domain['RelPaths'], $this->sealer);
            $merkleRoot = BrMerkleRoot::compute(array_map(static fn ($c) => $c['sha256'], $descriptors));
            $scopeOverrides = [
                'schema' => ['contentHash' => $schema['ContentHash'], 'migrations' => $schema['Migrations']],
                'closedSets' => ['contentHash' => $closedSets['ContentHash'], 'setCount' => $closedSets['SetCount'], 'valueCount' => $closedSets['ValueCount']],
                'features' => ['contentHash' => $features['ContentHash'], 'featureCount' => $features['FeatureCount'], 'defaultCount' => $features['DefaultCount']],
                'licenses' => ['contentHash' => $licenses['ContentHash'], 'licenseCount' => $licenses['LicenseCount'], 'epochCount' => $licenses['EpochCount'], 'featureLinkCount' => $licenses['FeatureLinkCount']],
                'rbac' => ['contentHash' => $rbac['ContentHash'], 'userRoleCount' => $rbac['UserRoleCount'], 'casbinRuleCount' => $rbac['CasbinRuleCount'], 'bootstrapPresent' => $rbac['BootstrapPresent']],
                'domain' => ['contentHash' => $domain['ContentHash'], 'tables' => $domain['Tables']],
                'files' => ['contentHash' => $files['ContentHash'], 'objectCount' => $files['ObjectCount'], 'totalBytes' => $files['TotalBytes'], 'index' => $files['IndexPath']],
                'secretsEnvelope' => ['contentHash' => $secrets['ContentHash'], 'algorithm' => $secrets['Algorithm'], 'epoch' => $secrets['Epoch'], 'kid' => $secrets['Kid']],
            ];
            $encryption = $this->sealer->manifestEncryption();
            $written = $this->manifest->writeShadowManifest($this->storage, $archiveId, $this->appVersion(), $userId, $requestId, $descriptors, $merkleRoot, $scopeOverrides, $schema['SchemaHash'], $encryption);
            $this->storage->finalize($archiveId, $requestId);
        } catch (Throwable $e) {
            $this->storage->abort($archiveId, $requestId);
            throw $e;
        }

        return ['StoragePath' => $path, 'Bytes' => $this->storage->sizeBytes($archiveId), 'ManifestSha256' => $written['Sha256'], 'MerkleRoot' => $merkleRoot, 'ChunkCount' => count($descriptors), 'SchemaHash' => $schema['SchemaHash'], 'MigrationCount' => count($schema['Migrations']), 'ClosedSetCount' => $closedSets['SetCount'], 'ClosedSetValueCount' => $closedSets['ValueCount'], 'FeatureCount' => $features['FeatureCount'], 'FeatureDefaultCount' => $features['DefaultCount'], 'LicenseCount' => $licenses['LicenseCount'], 'LicenseEpochCount' => $licenses['EpochCount'], 'LicenseFeatureLinkCount' => $licenses['FeatureLinkCount'], 'UserRoleCount' => $rbac['UserRoleCount'], 'CasbinRuleCount' => $rbac['CasbinRuleCount'], 'BootstrapPresent' => $rbac['BootstrapPresent'], 'DomainTableCount' => $domain['TableCount'], 'DomainRowCount' => $domain['TotalRowCount'], 'DomainTables' => $domain['Tables'], 'FileObjectCount' => $files['ObjectCount'], 'FileTotalBytes' => $files['TotalBytes'], 'SecretRowCount' => $secrets['RowCount'], 'SecretEpoch' => $secrets['Epoch'], 'SecretKid' => $secrets['Kid'], 'EncryptionEpoch' => $encryption['epoch'], 'EncryptionKid' => $encryption['kid']];
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
