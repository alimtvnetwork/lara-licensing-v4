<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrCryptoConstants as C;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 restore preflight guard for SC-F "domain tables" drift.
 *
 * Normative sources:
 *  - spec/26-backup-restore/07-manifest-schema.md §"`scope` Shape"
 *    (`manifest.scope.domain = {contentHash, tables:[{name, rowCount,
 *    contentHash}]}`).
 *  - spec/26-backup-restore/12-restore-orchestration.md §"Preflight"
 *    (INV-BR-RS-1 preflight/drift never mutate any table; INV-BR-RS-2
 *    verify all invariants BEFORE any DB tx opens).
 *  - spec/26-backup-restore/08-archive-format.md INV-BR-AF-5 (per-table
 *    SC-F entries appear alphabetically; deviation is BackupCorrupt).
 *  - INV-BR-MS-2 (every `scope.*.contentHash` hashes the class's real
 *    bytes).
 *
 * Runs strictly AFTER {@see BrImportPreflight} (which verifies the
 * top-level aggregate `scope.domain.contentHash`) and BEFORE any
 * apply-plan step. Per-table drift can hide inside an aggregate hash
 * if a future collector edit changes aggregation, so this check
 * pins each declared `manifest.scope.domain.tables[i]` against a
 * fresh live invocation of {@see BrScopeDomainCollector} and reports
 * exact per-table divergence.
 *
 * Failure model (strict, no swallowing):
 *  - `scope.domain.tables` missing / not a list => BackupCorrupt (422)
 *    at `/scope/domain/tables` rule `DomainTablesShape`.
 *  - Declared table count != live table count => BackupCorrupt at
 *    `/scope/domain/tables` rule `DomainTableCountMismatch`.
 *  - Declared table absent from live => BackupCorrupt at
 *    `/scope/domain/<name>` rule `DomainTableMissingInLive`.
 *  - Live table absent from manifest => BackupCorrupt at
 *    `/scope/domain/<name>` rule `DomainTableExtraInLive`.
 *  - Declared `contentHash` != live => BackupCorrupt at
 *    `/scope/domain/<name>/contentHash` rule `DomainTableContentDrift`.
 *  - Declared `rowCount` != live => BackupCorrupt at
 *    `/scope/domain/<name>/rowCount` rule `DomainTableRowCountDrift`.
 *
 * This service NEVER opens a DB transaction and NEVER writes anywhere
 * (INV-BR-RS-1). The success log `br.restore.domain_drift.verified`
 * carries archive id, declared/live table counts, aggregate hash, and
 * request id; every failure logs `br.restore.domain_drift.rejected`
 * with the offending rule and re-throws.
 *
 * 15-line function cap held via helper splits.
 */
final class BrDomainDriftCheck
{
    private const ERR_CORRUPT = C::ERR_BACKUP_CORRUPT;
    private const FIELD_ROOT = '/scope/domain';
    private const FIELD_TABLES = '/scope/domain/tables';
    private const RULE_SHAPE = 'DomainTablesShape';
    private const RULE_COUNT = 'DomainTableCountMismatch';
    private const RULE_MISSING = 'DomainTableMissingInLive';
    private const RULE_EXTRA = 'DomainTableExtraInLive';
    private const RULE_CONTENT_DRIFT = 'DomainTableContentDrift';
    private const RULE_ROW_DRIFT = 'DomainTableRowCountDrift';
    private const RULE_MANIFEST = 'DomainDriftManifestUnreadable';
    private const LOG_OK = 'br.restore.domain_drift.verified';
    private const LOG_FAIL = 'br.restore.domain_drift.rejected';

    public function __construct(
        private readonly BrScopeDomainCollector $domain,
        private readonly BrArchiveStorage $storage,
    ) {}

    /**
     * Load `manifest.json` for the given archive and run per-table
     * drift checks. Fails BackupCorrupt if the manifest is unreadable
     * (should not happen in practice: preflight ran first).
     */
    public function runForArchive(string $archiveId, string $requestId): array
    {
        $abs = $this->storage->path($archiveId) . DIRECTORY_SEPARATOR . 'manifest.json';
        if (is_file($abs) === false) {
            $this->reject($archiveId, '/manifest.json', self::RULE_MANIFEST, 'manifest.json missing for domain drift check', $requestId);
        }
        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode((string) file_get_contents($abs), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->reject($archiveId, '/manifest.json', self::RULE_MANIFEST, 'manifest.json unreadable: ' . $e->getMessage(), $requestId);
        }

        return $this->run($archiveId, $manifest, $requestId);
    }


    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *   ArchiveId:string, RequestId:string, DeclaredCount:int, LiveCount:int,
     *   AggregateDeclared:string, AggregateLive:string,
     *   Tables: list<array{name:string, declaredRowCount:int, liveRowCount:int, declaredHash:string, liveHash:string}>
     * }
     */
    public function run(string $archiveId, array $manifest, string $requestId): array
    {
        $declared = $this->readDeclaredTables($manifest, $archiveId, $requestId);
        $live = $this->domain->collect($requestId);
        $liveIndex = $this->indexLive($live['Tables']);
        $this->assertCount($declared, $liveIndex, $archiveId, $requestId);
        $tables = $this->comparePairs($declared, $liveIndex, $archiveId, $requestId);
        Log::info(self::LOG_OK, ['ArchiveId' => $archiveId, 'DeclaredCount' => count($declared), 'LiveCount' => count($liveIndex), 'AggregateLive' => (string) $live['ContentHash'], 'RequestId' => $requestId]);
        $aggregateDeclared = (string) (($manifest['scope']['domain']['contentHash']) ?? '');

        return ['ArchiveId' => $archiveId, 'RequestId' => $requestId, 'DeclaredCount' => count($declared), 'LiveCount' => count($liveIndex), 'AggregateDeclared' => $aggregateDeclared, 'AggregateLive' => (string) $live['ContentHash'], 'Tables' => $tables];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{name:string, rowCount:int, contentHash:string}>
     */
    private function readDeclaredTables(array $manifest, string $archiveId, string $requestId): array
    {
        $slot = $manifest['scope']['domain']['tables'] ?? null;
        if (! is_array($slot) || ! array_is_list($slot)) {
            $this->reject($archiveId, self::FIELD_TABLES, self::RULE_SHAPE, 'manifest.scope.domain.tables missing or not a list', $requestId);
        }
        $out = [];
        foreach ((array) $slot as $row) {
            $name = (string) (is_array($row) ? ($row['name'] ?? '') : '');
            $rowCount = (int) (is_array($row) ? ($row['rowCount'] ?? -1) : -1);
            $hash = (string) (is_array($row) ? ($row['contentHash'] ?? '') : '');
            if ($name === '' || $hash === '' || $rowCount < 0) {
                $this->reject($archiveId, self::FIELD_TABLES, self::RULE_SHAPE, 'scope.domain.tables[] entry missing name/rowCount/contentHash', $requestId);
            }
            $out[] = ['name' => $name, 'rowCount' => $rowCount, 'contentHash' => $hash];
        }

        return $out;
    }

    /**
     * @param  list<array{name:string, rowCount:int, contentHash:string}>  $live
     * @return array<string, array{rowCount:int, contentHash:string}>
     */
    private function indexLive(array $live): array
    {
        $index = [];
        foreach ($live as $t) {
            $index[$t['name']] = ['rowCount' => $t['rowCount'], 'contentHash' => $t['contentHash']];
        }

        return $index;
    }

    /**
     * @param  list<array{name:string, rowCount:int, contentHash:string}>  $declared
     * @param  array<string, array{rowCount:int, contentHash:string}>  $liveIndex
     */
    private function assertCount(array $declared, array $liveIndex, string $archiveId, string $requestId): void
    {
        if (count($declared) !== count($liveIndex)) {
            $this->reject($archiveId, self::FIELD_TABLES, self::RULE_COUNT, 'declared table count (' . count($declared) . ') != live count (' . count($liveIndex) . ')', $requestId);
        }
    }

    /**
     * @param  list<array{name:string, rowCount:int, contentHash:string}>  $declared
     * @param  array<string, array{rowCount:int, contentHash:string}>  $liveIndex
     * @return list<array{name:string, declaredRowCount:int, liveRowCount:int, declaredHash:string, liveHash:string}>
     */
    private function comparePairs(array $declared, array $liveIndex, string $archiveId, string $requestId): array
    {
        $declaredNames = [];
        $out = [];
        foreach ($declared as $t) {
            $declaredNames[$t['name']] = true;
            $out[] = $this->compareOne($t, $liveIndex, $archiveId, $requestId);
        }
        foreach (array_keys($liveIndex) as $name) {
            if (isset($declaredNames[$name]) === false) {
                $this->reject($archiveId, self::FIELD_ROOT . '/' . $name, self::RULE_EXTRA, 'live domain table not present in manifest: ' . $name, $requestId);
            }
        }

        return $out;
    }

    /**
     * @param  array{name:string, rowCount:int, contentHash:string}  $decl
     * @param  array<string, array{rowCount:int, contentHash:string}>  $liveIndex
     * @return array{name:string, declaredRowCount:int, liveRowCount:int, declaredHash:string, liveHash:string}
     */
    private function compareOne(array $decl, array $liveIndex, string $archiveId, string $requestId): array
    {
        $name = $decl['name'];
        if (isset($liveIndex[$name]) === false) {
            $this->reject($archiveId, self::FIELD_ROOT . '/' . $name, self::RULE_MISSING, 'declared domain table missing from live: ' . $name, $requestId);
        }
        $live = $liveIndex[$name];
        if ((int) $live['rowCount'] !== $decl['rowCount']) {
            $this->reject($archiveId, self::FIELD_ROOT . '/' . $name . '/rowCount', self::RULE_ROW_DRIFT, 'rowCount drift on ' . $name . ' declared=' . $decl['rowCount'] . ' live=' . (int) $live['rowCount'], $requestId);
        }
        if (! hash_equals($decl['contentHash'], (string) $live['contentHash'])) {
            $this->reject($archiveId, self::FIELD_ROOT . '/' . $name . '/contentHash', self::RULE_CONTENT_DRIFT, 'contentHash drift on ' . $name, $requestId);
        }

        return ['name' => $name, 'declaredRowCount' => $decl['rowCount'], 'liveRowCount' => (int) $live['rowCount'], 'declaredHash' => $decl['contentHash'], 'liveHash' => (string) $live['contentHash']];
    }

    private function reject(string $archiveId, string $field, string $rule, string $message, string $requestId): never
    {
        Log::warning(self::LOG_FAIL, ['ArchiveId' => $archiveId, 'Field' => $field, 'Rule' => $rule, 'Reason' => $message, 'RequestId' => $requestId]);
        throw InternalException::custom(self::ERR_CORRUPT, $message, [['Field' => $field, 'Rule' => $rule]]);
    }
}
