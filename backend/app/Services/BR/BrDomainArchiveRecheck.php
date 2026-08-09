<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrCryptoConstants as C;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 restore-time recheck: recomputes per-table SC-F content
 * hashes from the ARCHIVE's decompressed `scope/domain/<Name>.jsonl.zst`
 * bodies and compares them to `manifest.scope.domain.tables[].contentHash`.
 *
 * Runs strictly AFTER {@see BrImportPreflight::run()} (which decompresses
 * and hashes every chunk and populates `Chunks[].PlainSha256`) and BEFORE
 * any apply-plan step, complementing {@see BrDomainDriftCheck} which
 * only proves live-DB drift against the manifest. This recheck proves
 * ARCHIVE integrity per-table:
 *
 *   for each t in manifest.scope.domain.tables[]:
 *     path = "scope/domain/" + t.name + ".jsonl.zst"
 *     assert preflight.Chunks[path].PlainSha256 == t.contentHash
 *
 * If `manifest.chunkIndex.chunks[]` never declared a chunk at that path
 * (INV-BR-AF-5 hole) the recheck fails BackupCorrupt at
 * `/scope/domain/<name>` rule `DomainArchiveChunkMissing`. A hash
 * mismatch fails BackupCorrupt at `/scope/domain/<name>/contentHash`
 * rule `DomainArchiveContentDrift` and carries both the declared and
 * archive-computed hashes in the log envelope for postmortem.
 *
 * This service NEVER opens a DB transaction, NEVER touches shard
 * connections, and NEVER writes anywhere (INV-BR-RS-1). Success logs
 * `br.restore.domain_archive.verified`; every failure logs
 * `br.restore.domain_archive.rejected` and re-throws.
 *
 * 15-line function cap held via helper splits (`run`, `readDeclared`,
 * `indexChunks`, `assertOne`, `reject`).
 */
final class BrDomainArchiveRecheck
{
    private const ERR_CORRUPT = C::ERR_BACKUP_CORRUPT;
    private const FIELD_ROOT = '/scope/domain';
    private const RULE_SHAPE = 'DomainTablesShape';
    private const RULE_CHUNK_MISSING = 'DomainArchiveChunkMissing';
    private const RULE_CONTENT_DRIFT = 'DomainArchiveContentDrift';
    private const RULE_MANIFEST = 'DomainArchiveManifestUnreadable';
    private const REL_PREFIX = 'scope/domain/';
    private const REL_SUFFIX = '.jsonl.zst';
    private const LOG_OK = 'br.restore.domain_archive.verified';
    private const LOG_FAIL = 'br.restore.domain_archive.rejected';

    public function __construct(private readonly BrArchiveStorage $storage) {}

    /**
     * Load `manifest.json` for the given archive and run the recheck
     * against the preflight `Chunks[]` list.
     *
     * @param  array{Chunks: list<array<string,mixed>>, ...}  $preflight
     * @return array{ArchiveId:string, TableCount:int, Tables: list<array{name:string, declaredHash:string, archiveHash:string, plainBytes:int}>}
     */
    public function runForArchive(string $archiveId, array $preflight, string $requestId): array
    {
        $abs = $this->storage->path($archiveId) . DIRECTORY_SEPARATOR . 'manifest.json';
        if (is_file($abs) === false) {
            $this->reject($archiveId, '/manifest.json', self::RULE_MANIFEST, 'manifest.json missing for archive recheck', $requestId);
        }
        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode((string) file_get_contents($abs), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->reject($archiveId, '/manifest.json', self::RULE_MANIFEST, 'manifest.json unreadable: ' . $e->getMessage(), $requestId);
        }

        return $this->run($archiveId, $manifest, $preflight, $requestId);
    }


    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{Chunks: list<array{Path:string, PlainSha256:string, PlainBytes:int}>, ...}  $preflight
     * @return array{
     *   ArchiveId:string, TableCount:int,
     *   Tables: list<array{name:string, declaredHash:string, archiveHash:string, plainBytes:int}>
     * }
     */
    public function run(string $archiveId, array $manifest, array $preflight, string $requestId): array
    {
        $declared = $this->readDeclared($manifest, $archiveId, $requestId);
        $chunkIndex = $this->indexChunks($preflight['Chunks'] ?? []);
        $out = [];
        foreach ($declared as $t) {
            $out[] = $this->assertOne($archiveId, $t, $chunkIndex, $requestId);
        }
        Log::info(self::LOG_OK, ['ArchiveId' => $archiveId, 'TableCount' => count($out), 'RequestId' => $requestId]);

        return ['ArchiveId' => $archiveId, 'TableCount' => count($out), 'Tables' => $out];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{name:string, contentHash:string}>
     */
    private function readDeclared(array $manifest, string $archiveId, string $requestId): array
    {
        $slot = $manifest['scope']['domain']['tables'] ?? null;
        if (! is_array($slot) || ! array_is_list($slot)) {
            $this->reject($archiveId, self::FIELD_ROOT . '/tables', self::RULE_SHAPE, 'manifest.scope.domain.tables missing or not a list', $requestId);
        }
        $out = [];
        foreach ((array) $slot as $row) {
            $name = (string) (is_array($row) ? ($row['name'] ?? '') : '');
            $hash = (string) (is_array($row) ? ($row['contentHash'] ?? '') : '');
            if ($name === '' || $hash === '') {
                $this->reject($archiveId, self::FIELD_ROOT . '/tables', self::RULE_SHAPE, 'scope.domain.tables[] entry missing name/contentHash', $requestId);
            }
            $out[] = ['name' => $name, 'contentHash' => $hash];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @return array<string, array{PlainSha256:string, PlainBytes:int}>
     */
    private function indexChunks(array $chunks): array
    {
        $idx = [];
        foreach ($chunks as $c) {
            $path = (string) ($c['Path'] ?? '');
            if ($path === '') {
                continue;
            }
            $idx[$path] = ['PlainSha256' => (string) ($c['PlainSha256'] ?? ''), 'PlainBytes' => (int) ($c['PlainBytes'] ?? 0)];
        }

        return $idx;
    }

    /**
     * @param  array{name:string, contentHash:string}  $t
     * @param  array<string, array{PlainSha256:string, PlainBytes:int}>  $chunkIndex
     * @return array{name:string, declaredHash:string, archiveHash:string, plainBytes:int}
     */
    private function assertOne(string $archiveId, array $t, array $chunkIndex, string $requestId): array
    {
        $relPath = self::REL_PREFIX . $t['name'] . self::REL_SUFFIX;
        if (isset($chunkIndex[$relPath]) === false) {
            $this->reject($archiveId, self::FIELD_ROOT . '/' . $t['name'], self::RULE_CHUNK_MISSING, 'archive chunk missing for domain table: ' . $t['name'], $requestId);
        }
        $entry = $chunkIndex[$relPath];
        if (! hash_equals($t['contentHash'], (string) $entry['PlainSha256'])) {
            Log::warning(self::LOG_FAIL, ['ArchiveId' => $archiveId, 'Table' => $t['name'], 'Declared' => $t['contentHash'], 'Archive' => $entry['PlainSha256'], 'Rule' => self::RULE_CONTENT_DRIFT, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_CORRUPT, 'archive content drift on domain table: ' . $t['name'], [['Field' => self::FIELD_ROOT . '/' . $t['name'] . '/contentHash', 'Rule' => self::RULE_CONTENT_DRIFT]]);
        }

        return ['name' => $t['name'], 'declaredHash' => $t['contentHash'], 'archiveHash' => (string) $entry['PlainSha256'], 'plainBytes' => (int) $entry['PlainBytes']];
    }

    private function reject(string $archiveId, string $field, string $rule, string $message, string $requestId): never
    {
        Log::warning(self::LOG_FAIL, ['ArchiveId' => $archiveId, 'Field' => $field, 'Rule' => $rule, 'Reason' => $message, 'RequestId' => $requestId]);
        throw InternalException::custom(self::ERR_CORRUPT, $message, [['Field' => $field, 'Rule' => $rule]]);
    }
}
