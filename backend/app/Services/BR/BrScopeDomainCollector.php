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
 * Plan 14 step 19. SC-F "Domain tables" collector for the S1 shadow
 * Export path.
 *
 * Normative sources:
 *  - spec/26-backup-restore/05-scope-catalog.md §"SC-F Domain tables"
 *    (selector = every remaining public.* table not covered by SC-A..E
 *    and not enumerated in 06-scope-exclusions.md; whole scope per
 *    table; restore rank 6; portable JSONL body per table).
 *  - spec/26-backup-restore/06-scope-exclusions.md (EX-A..H closed set
 *    of tables/paths that MUST NOT ship; `AuditLogs` is IN scope under
 *    SC-F per §EX-E note line 116).
 *  - spec/26-backup-restore/07-manifest-schema.md §"`scope` Shape"
 *    (`manifest.scope.domain = {contentHash, tables:[{name, rowCount,
 *    contentHash}]}`).
 *  - spec/26-backup-restore/08-archive-format.md §"Entry Order"
 *    (INV-BR-AF-5: per-table `SC-F` entries appear in alphabetical
 *    order by table name; deviation is `BackupCorrupt`).
 *  - INV-BR-MS-2 (every `scope.*.contentHash` hashes the class's real
 *    bytes; empty placeholders violate the validator once shipped).
 *
 * Scope narrowing for step 19 (documented, tracked in Plan 14):
 *  Root-DB domain tables only. Shard-scope domain tables (Serials,
 *  Quotas, VerifyKeys, MachineBindings, UserBindings) land in a
 *  follow-up alongside the SC-F shard iterator; the allowlist gates
 *  their inclusion by connection so the collector remains additive.
 *
 * Portability: rows are emitted verbatim with keys sorted
 * lexicographically per row; no surrogate-id stripping is performed
 * because Restore for SC-F re-inserts rows into an EMPTY target set
 * per `whole` restore boundary (spec 05 §SC-F). Cross-table FK repair
 * happens at Restore in dependency order (INV-BR-SC-3), not at
 * Export.
 *
 * Determinism: after fetch, rows are sorted by their canonicalized
 * JSON string so archive bytes are stable across DB scan order,
 * regardless of PK column shape.
 *
 * Failure model (strict, no swallowing):
 *  - Configured table absent at Export time => `BackupStorageFailure`
 *    (500) at `/scope/domain/<name>` rule `DomainTableMissing`.
 *  - Unreadable table => `BackupStorageFailure` at `/scope/domain/<name>`
 *    rule `DomainTableUnreadable`.
 *  - Row that cannot be JSON-encoded (binary that is not valid UTF-8
 *    and not marked base64) => `BackupCorrupt` (422) at
 *    `/scope/domain/<name>` rule `DomainRowNotEncodable`.
 *
 * 15-line function cap held by splitting into `resolveTables`,
 * `collectOne`, `readRows`, `canonicalize`, `serializeRow`, and
 * `aggregate`.
 */
final class BrScopeDomainCollector
{
    private const CONN_ROOT = 'root';
    private const REL_PATH_PREFIX = 'scope/domain/';
    private const REL_PATH_SUFFIX = '.jsonl.zst';

    private const ERR_UNREADABLE = 'BackupStorageFailure';
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const RULE_MISSING = 'DomainTableMissing';
    private const RULE_UNREADABLE = 'DomainTableUnreadable';
    private const RULE_NOT_ENCODABLE = 'DomainRowNotEncodable';
    private const FIELD_ROOT = '/scope/domain';

    private const LOG_COLLECTED = 'br.export.scope.domain.collected';
    private const LOG_TABLE = 'br.export.scope.domain.table';
    private const LOG_TABLE_MISSING = 'br.export.scope.domain.table_missing';
    private const LOG_TABLE_UNREADABLE = 'br.export.scope.domain.table_unreadable';

    /**
     * Default SC-F Root-scope allowlist. Sourced from
     * `config('lara.br.domain_root_tables')` when set; falls back to
     * this list so a fresh env exports the correct closure without
     * config touch. Adding a table to Root DB WITHOUT updating this
     * list is caught by the step-30 consistency report (spec 26 §05
     * INV-BR-SC-6).
     *
     * @var list<string>
     */
    private const DEFAULT_ROOT_TABLES = [
        'AppUpdateAssets',
        'AppUpdates',
        'AuditLogs',
        'BackupAuditEvents',
        'BackupJobs',
        'BackupSnapshots',
        'BrAdvisoryLockKeys',
        'BrKekEpochs',
        'FeatureFlags',
        'LicenseTiers',
        'Prefixes',
        'ResellerShardRoutes',
        'Resellers',
    ];

    /**
     * Collect SC-F rows and return per-table JSONL bodies plus the
     * manifest slot fields (`tables[]`, aggregate `contentHash`,
     * `tableCount`, `totalRowCount`).
     *
     * @return array{
     *   Bodies: array<string, string>,
     *   RelPaths: list<string>,
     *   Tables: list<array{name:string, rowCount:int, contentHash:string}>,
     *   ContentHash: string,
     *   TableCount: int,
     *   TotalRowCount: int
     * }
     */
    public function collect(string $requestId): array
    {
        $tables = $this->resolveTables();
        $bodies = [];
        $relPaths = [];
        $manifestTables = [];
        $totalRows = 0;
        foreach ($tables as $name) {
            $one = $this->collectOne($name, $requestId);
            $bodies[$one['RelPath']] = $one['Jsonl'];
            $relPaths[] = $one['RelPath'];
            $manifestTables[] = ['name' => $name, 'rowCount' => $one['RowCount'], 'contentHash' => $one['ContentHash']];
            $totalRows += $one['RowCount'];
        }

        return $this->aggregate($bodies, $relPaths, $manifestTables, $totalRows, $requestId);
    }

    /**
     * Alphabetical allowlist (spec 26 §08 INV-BR-AF-5). Config override
     * lets cPanel-relocated deploys or seeded test envs narrow the set
     * without touching code; unknown table names raise at collect time.
     *
     * @return list<string>
     */
    private function resolveTables(): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('lara.br.domain_root_tables', self::DEFAULT_ROOT_TABLES);
        $names = array_values(array_unique(array_map('strval', $configured)));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @return array{RelPath:string, Jsonl:string, ContentHash:string, RowCount:int}
     */
    private function collectOne(string $table, string $requestId): array
    {
        if (! Schema::connection(self::CONN_ROOT)->hasTable($table)) {
            Log::error(self::LOG_TABLE_MISSING, ['Table' => $table, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Root domain table missing at Export time: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_MISSING]]);
        }
        $rows = $this->readRows($table, $requestId);
        $jsonl = $this->canonicalize($rows, $table);
        $contentHash = hash('sha256', $jsonl);
        Log::info(self::LOG_TABLE, ['Table' => $table, 'RowCount' => count($rows), 'ContentHash' => $contentHash, 'Bytes' => strlen($jsonl), 'RequestId' => $requestId]);

        return ['RelPath' => self::REL_PATH_PREFIX . $table . self::REL_PATH_SUFFIX, 'Jsonl' => $jsonl, 'ContentHash' => $contentHash, 'RowCount' => count($rows)];
    }

    /** @return list<array<string, mixed>> */
    private function readRows(string $table, string $requestId): array
    {
        try {
            $columns = Schema::connection(self::CONN_ROOT)->getColumnListing($table);
            sort($columns, SORT_STRING);
            $orderCol = $columns[0] ?? null;
            $query = DB::connection(self::CONN_ROOT)->table($table);
            if ($orderCol !== null) {
                $query = $query->orderBy($orderCol);
            }
            $rows = $query->get()->all();
        } catch (Throwable $e) {
            Log::error(self::LOG_TABLE_UNREADABLE, ['Table' => $table, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Root domain table unreadable at Export time: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_UNREADABLE]]);
        }

        return array_map(static fn ($r) => (array) $r, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function canonicalize(array $rows, string $table): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $this->serializeRow($row, $table);
        }
        sort($lines, SORT_STRING);

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Sort keys, drop nothing, emit compact JSON. Bytea columns arrive
     * as PHP strings from PDO; if not valid UTF-8 the row is rejected
     * as unencodable (BackupCorrupt) rather than silently mangled.
     *
     * @param  array<string, mixed>  $row
     */
    private function serializeRow(array $row, string $table): string
    {
        ksort($row);
        try {
            return json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw InternalException::custom(self::ERR_CORRUPT, 'Row not JSON-encodable in domain table: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_NOT_ENCODABLE]]);
        }
    }

    /**
     * @param  array<string, string>  $bodies
     * @param  list<string>  $relPaths
     * @param  list<array{name:string, rowCount:int, contentHash:string}>  $tables
     * @return array{Bodies: array<string, string>, RelPaths: list<string>, Tables: list<array{name:string, rowCount:int, contentHash:string}>, ContentHash: string, TableCount: int, TotalRowCount: int}
     */
    private function aggregate(array $bodies, array $relPaths, array $tables, int $totalRows, string $requestId): array
    {
        $agg = '';
        foreach ($tables as $t) {
            $agg .= $t['name'] . "\t" . $t['contentHash'] . "\n";
        }
        $contentHash = hash('sha256', $agg);
        Log::info(self::LOG_COLLECTED, ['TableCount' => count($tables), 'TotalRowCount' => $totalRows, 'ContentHash' => $contentHash, 'RequestId' => $requestId]);

        return ['Bodies' => $bodies, 'RelPaths' => $relPaths, 'Tables' => $tables, 'ContentHash' => $contentHash, 'TableCount' => count($tables), 'TotalRowCount' => $totalRows];
    }
}
