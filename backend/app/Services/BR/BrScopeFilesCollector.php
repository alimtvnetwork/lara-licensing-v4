<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Plan 14 step 20. SC-H "File objects index" collector for the S1
 * shadow Export path.
 *
 * Normative sources:
 *  - spec/26-backup-restore/05-scope-catalog.md §"SC-H File objects"
 *    (selector = every object-storage key referenced by any row in
 *    `SC-F` via a `file_id`-style column, resolved to
 *    `{ bucket, path, sha256, size }`; per-object restore boundary;
 *    restore rank 8; INV-BR-SC-5 content-addressed by SHA-256).
 *  - spec/26-backup-restore/07-manifest-schema.md §"`scope` Shape"
 *    (`manifest.scope.files = {contentHash, objectCount, totalBytes,
 *    index}` where `index` names the chunk that holds the JSONL body).
 *  - spec/26-backup-restore/08-archive-format.md §"Entry Order" line 80
 *    (`scope/files/index.jsonl.zst` carries `{sha256, bucket, path,
 *    bytes}` per line; INV-BR-AF-1 pins position between SC-G and the
 *    per-body shards). SC-H bodies are out of scope for step 20;
 *    encryption sealing (step 21) wires them.
 *  - INV-BR-MS-2 (every `scope.*.contentHash` hashes the class's real
 *    bytes; empty placeholders violate the validator once shipped).
 *
 * Selector implementation: config-driven `lara.br.file_sources`. Each
 * source entry declares `{table, sha256Column, pathColumn, bytesColumn,
 * bucket, whereColumn?, whereValue?}` so a new Root table with file
 * references (e.g. future BackupSnapshots artifacts) is added in
 * config, not code. The Root default set covers finalized
 * `AppUpdateAssets` rows only; signatures do not carry an explicit
 * byte count so they are not indexed until the schema pins one.
 *
 * Determinism:
 *  - Iterate sources in the order declared (already stable across
 *    config reloads because `config()` preserves insertion order).
 *  - Per-row assembly: alpha-sorted keys `{bucket, bytes, path,
 *    sha256}` -> compact JSON; JSONL lines sorted by canonical string
 *    bytes so archive layout is stable regardless of DB scan order.
 *  - Dedupe by `sha256` keeping the first encountered entry per
 *    INV-BR-SC-5; a subsequent conflicting bucket+path for the same
 *    sha256 is logged and dropped, not rejected, because SC-H is
 *    content-addressed and identical bytes MAY appear at multiple
 *    logical paths.
 *
 * Failure model (strict, no swallowing):
 *  - Configured source table absent => `BackupStorageFailure` (500) at
 *    `/scope/files/<name>` rule `FileSourceTableMissing`.
 *  - Configured source column absent on the table => `BackupStorageFailure`
 *    at `/scope/files/<name>/<column>` rule `FileSourceColumnMissing`.
 *  - Unreadable source => `BackupStorageFailure` at `/scope/files/<name>`
 *    rule `FileSourceUnreadable`.
 *  - A row has null/empty `sha256` OR null/empty `path` OR non-positive
 *    `bytes` => `BackupCorrupt` (422) at `/scope/files/<name>/<pk>` rule
 *    `FileEntryIncomplete`.
 *
 * 15-line function cap held by splitting into `resolveSources`,
 * `collectOne`, `readRows`, `mapRow`, `canonicalizeIndex`, `aggregate`.
 */
final class BrScopeFilesCollector
{
    private const CONN_ROOT = 'root';
    private const REL_PATH_INDEX = 'scope/files/index.jsonl.zst';
    private const CONFIG_SOURCES = 'lara.br.file_sources';

    private const KEY_TABLE = 'table';
    private const KEY_SHA256 = 'sha256Column';
    private const KEY_PATH = 'pathColumn';
    private const KEY_BYTES = 'bytesColumn';
    private const KEY_BUCKET = 'bucket';
    private const KEY_WHERE_COL = 'whereColumn';
    private const KEY_WHERE_VAL = 'whereValue';

    private const ERR_UNREADABLE = 'BackupStorageFailure';
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const RULE_TABLE_MISSING = 'FileSourceTableMissing';
    private const RULE_COLUMN_MISSING = 'FileSourceColumnMissing';
    private const RULE_UNREADABLE = 'FileSourceUnreadable';
    private const RULE_INCOMPLETE = 'FileEntryIncomplete';
    private const FIELD_ROOT = '/scope/files';

    private const LOG_COLLECTED = 'br.export.scope.files.collected';
    private const LOG_SOURCE = 'br.export.scope.files.source';
    private const LOG_TABLE_MISSING = 'br.export.scope.files.table_missing';
    private const LOG_COLUMN_MISSING = 'br.export.scope.files.column_missing';
    private const LOG_SOURCE_UNREADABLE = 'br.export.scope.files.source_unreadable';
    private const LOG_DUP_SKIPPED = 'br.export.scope.files.duplicate_sha256_skipped';
    private const LOG_INCOMPLETE = 'br.export.scope.files.entry_incomplete';

    /**
     * Root default SC-H sources. `AppUpdateAssets` (Plan 08 self-update
     * plane) stores `Sha256`, `StoragePath`, `SizeBytes` for every
     * finalized asset; that is the only Root-scope table today with
     * indexable file references. Signatures carry no byte count so
     * they are excluded until the migration pins one.
     *
     * @var list<array<string, string|int>>
     */
    private const DEFAULT_SOURCES = [
        [
            self::KEY_TABLE => 'AppUpdateAssets',
            self::KEY_SHA256 => 'Sha256',
            self::KEY_PATH => 'StoragePath',
            self::KEY_BYTES => 'SizeBytes',
            self::KEY_BUCKET => 'app-updates',
            self::KEY_WHERE_COL => 'IsFinalized',
            self::KEY_WHERE_VAL => 1,
        ],
    ];

    /**
     * Collect SC-H index rows and return the JSONL body plus the
     * manifest slot fields (`objectCount`, `totalBytes`, aggregate
     * `contentHash`, chunk `index` path).
     *
     * @return array{
     *   Jsonl: string,
     *   RelPath: string,
     *   ContentHash: string,
     *   ObjectCount: int,
     *   TotalBytes: int,
     *   IndexPath: string
     * }
     */
    public function collect(string $requestId): array
    {
        $sources = $this->resolveSources();
        $entries = [];
        foreach ($sources as $src) {
            foreach ($this->collectOne($src, $requestId) as $entry) {
                $sha = $entry['sha256'];
                if (isset($entries[$sha])) {
                    Log::info(self::LOG_DUP_SKIPPED, ['Sha256' => $sha, 'Table' => $src[self::KEY_TABLE], 'RequestId' => $requestId]);
                    continue;
                }
                $entries[$sha] = $entry;
            }
        }

        return $this->aggregate($entries, $requestId);
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function resolveSources(): array
    {
        /** @var list<array<string, string|int>> $configured */
        $configured = (array) config(self::CONFIG_SOURCES, self::DEFAULT_SOURCES);

        return array_values($configured);
    }

    /**
     * @param  array<string, string|int>  $src
     * @return list<array{bucket:string, bytes:int, path:string, sha256:string}>
     */
    private function collectOne(array $src, string $requestId): array
    {
        $table = (string) $src[self::KEY_TABLE];
        if (! Schema::connection(self::CONN_ROOT)->hasTable($table)) {
            Log::error(self::LOG_TABLE_MISSING, ['Table' => $table, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'File-source table missing at Export time: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_TABLE_MISSING]]);
        }
        $this->assertColumns($table, $src, $requestId);
        $rows = $this->readRows($table, $src, $requestId);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapRow($row, $src);
        }
        Log::info(self::LOG_SOURCE, ['Table' => $table, 'RowCount' => count($out), 'RequestId' => $requestId]);

        return $out;
    }

    /**
     * @param  array<string, string|int>  $src
     */
    private function assertColumns(string $table, array $src, string $requestId): void
    {
        $needed = [self::KEY_SHA256, self::KEY_PATH, self::KEY_BYTES];
        if (isset($src[self::KEY_WHERE_COL])) {
            $needed[] = self::KEY_WHERE_COL;
        }
        foreach ($needed as $k) {
            $col = (string) $src[$k];
            if (! Schema::connection(self::CONN_ROOT)->hasColumn($table, $col)) {
                Log::error(self::LOG_COLUMN_MISSING, ['Table' => $table, 'Column' => $col, 'RequestId' => $requestId]);
                throw InternalException::custom(self::ERR_UNREADABLE, 'File-source column missing on ' . $table . ': ' . $col, [['Field' => self::FIELD_ROOT . '/' . $table . '/' . $col, 'Rule' => self::RULE_COLUMN_MISSING]]);
            }
        }
    }

    /**
     * @param  array<string, string|int>  $src
     * @return list<object>
     */
    private function readRows(string $table, array $src, string $requestId): array
    {
        try {
            $q = DB::connection(self::CONN_ROOT)->table($table);
            if (isset($src[self::KEY_WHERE_COL])) {
                $q = $q->where((string) $src[self::KEY_WHERE_COL], '=', $src[self::KEY_WHERE_VAL] ?? null);
            }
            $rows = $q->orderBy((string) $src[self::KEY_SHA256])->get([(string) $src[self::KEY_SHA256], (string) $src[self::KEY_PATH], (string) $src[self::KEY_BYTES]]);
        } catch (Throwable $e) {
            Log::error(self::LOG_SOURCE_UNREADABLE, ['Table' => $table, 'Reason' => $e->getMessage(), 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'File-source table unreadable at Export time: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_UNREADABLE]]);
        }

        return $rows->all();
    }

    /**
     * @param  object  $row
     * @param  array<string, string|int>  $src
     * @return array{bucket:string, bytes:int, path:string, sha256:string}
     */
    private function mapRow(object $row, array $src): array
    {
        $table = (string) $src[self::KEY_TABLE];
        $sha = (string) ($row->{(string) $src[self::KEY_SHA256]} ?? '');
        $path = (string) ($row->{(string) $src[self::KEY_PATH]} ?? '');
        $bytes = (int) ($row->{(string) $src[self::KEY_BYTES]} ?? 0);
        if ($sha === '' || $path === '' || $bytes <= 0) {
            Log::error(self::LOG_INCOMPLETE, ['Table' => $table, 'Sha256' => $sha, 'Path' => $path, 'Bytes' => $bytes]);
            throw InternalException::custom(self::ERR_CORRUPT, 'File-index entry incomplete in table: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_INCOMPLETE]]);
        }

        return ['bucket' => (string) $src[self::KEY_BUCKET], 'bytes' => $bytes, 'path' => $path, 'sha256' => $sha];
    }

    /**
     * @param  array<string, array{bucket:string, bytes:int, path:string, sha256:string}>  $entries
     * @return array{Jsonl:string, RelPath:string, ContentHash:string, ObjectCount:int, TotalBytes:int, IndexPath:string}
     */
    private function aggregate(array $entries, string $requestId): array
    {
        $jsonl = $this->canonicalizeIndex(array_values($entries));
        $contentHash = hash('sha256', $jsonl);
        $totalBytes = 0;
        foreach ($entries as $e) {
            $totalBytes += $e['bytes'];
        }
        Log::info(self::LOG_COLLECTED, ['ObjectCount' => count($entries), 'TotalBytes' => $totalBytes, 'ContentHash' => $contentHash, 'Bytes' => strlen($jsonl), 'RequestId' => $requestId]);

        return ['Jsonl' => $jsonl, 'RelPath' => self::REL_PATH_INDEX, 'ContentHash' => $contentHash, 'ObjectCount' => count($entries), 'TotalBytes' => $totalBytes, 'IndexPath' => self::REL_PATH_INDEX];
    }

    /**
     * @param  list<array{bucket:string, bytes:int, path:string, sha256:string}>  $entries
     */
    private function canonicalizeIndex(array $entries): string
    {
        $lines = [];
        foreach ($entries as $e) {
            ksort($e);
            $lines[] = json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        sort($lines, SORT_STRING);

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }
}
