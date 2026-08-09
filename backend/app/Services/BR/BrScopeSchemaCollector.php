<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 14. SC-A "Schema state" collector for the S1 shadow
 * Export path.
 *
 * Normative sources:
 *  - spec/26-backup-restore/05-scope-catalog.md §"SC-A · Schema state"
 *    (selector = `SELECT migration FROM public.migrations ORDER BY id
 *    ASC` + SHA-256 over concatenated migration file bodies).
 *  - spec/26-backup-restore/07-manifest-schema.md §"`scope` Shape"
 *    (manifest slot `manifest.scope.schema.migrations : string[]`).
 *  - spec/26-backup-restore/04-invariants.md `INV-BR-MS-2` (every
 *    `scope.*.contentHash` hashes the class's real bytes; empty
 *    placeholders are a validator violation once real content ships).
 *
 * This is the first collector that produces *real* bytes for the
 * shadow archive: earlier steps wrote an empty `scope/schema.jsonl.zst`
 * so INV-BR-AF-1 / AF-3 / AF-4 could be exercised over zero-length
 * payloads. Step 14 replaces the empty body with a canonical JSONL
 * stream and binds `manifest.schemaHash` + `manifest.scope.schema`
 * to the true schema state at Export time.
 *
 * Failure model:
 *  - Root DB unreachable or the `migrations` table missing =>
 *    `BackupStorageFailure` (500) with pointer `/scope/schema` and
 *    rule `MigrationsTableUnreadable`. The archive dir is aborted
 *    upstream in `BrExportWorker::materializeArchive`.
 *  - A migration row references a filename that is not present on
 *    disk under `lara.br.root_migrations_path` => `BackupCorrupt`
 *    (422) with pointer `/scope/schema/migrations/<name>` and rule
 *    `MigrationFileMissing`. This mirrors Import-side
 *    `BackupCorrupt.MissingChunk` at Export time so a bad server
 *    state fails FAST, not silently produces a mis-hashed archive.
 *
 * 15-line function cap held by splitting into `loadRows`,
 * `renderJsonl`, and `hashFiles`.
 */
final class BrScopeSchemaCollector
{
    private const CONN = 'root';
    private const TABLE = 'migrations';
    private const REL_PATH = 'scope/schema.jsonl.zst';
    private const CFG_MIGRATION_PATH = 'lara.br.root_migrations_path';
    private const ERR_UNREADABLE = 'BackupStorageFailure';
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const RULE_UNREADABLE = 'MigrationsTableUnreadable';
    private const RULE_MISSING_FILE = 'MigrationFileMissing';
    private const LOG_COLLECTED = 'br.export.scope.schema.collected';

    /**
     * Collect SC-A rows + bodies and return the JSONL payload, the
     * manifest-facing migration list, and the schema hash (SHA-256
     * over concatenated file bodies in table order).
     *
     * @return array{
     *   Jsonl: string,
     *   Migrations: list<string>,
     *   SchemaHash: string,
     *   ContentHash: string,
     *   RelPath: string
     * }
     */
    public function collect(string $requestId): array
    {
        $rows = $this->loadRows($requestId);
        $jsonl = $this->renderJsonl($rows);
        $schemaHash = $this->hashFiles($rows, $requestId);
        $contentHash = hash('sha256', $jsonl);
        Log::info(self::LOG_COLLECTED, ['MigrationCount' => count($rows), 'SchemaHash' => $schemaHash, 'ContentHash' => $contentHash, 'BodyBytes' => strlen($jsonl), 'RequestId' => $requestId]);

        return ['Jsonl' => $jsonl, 'Migrations' => array_column($rows, 'migration'), 'SchemaHash' => $schemaHash, 'ContentHash' => $contentHash, 'RelPath' => self::REL_PATH];
    }

    /**
     * @return list<array{migration:string, batch:int}>
     */
    private function loadRows(string $requestId): array
    {
        try {
            $raw = DB::connection(self::CONN)->table(self::TABLE)->orderBy('id')->get(['migration', 'batch']);
        } catch (\Throwable $e) {
            Log::error('br.export.scope.schema.unreadable', ['RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Root migrations table unreadable at Export time.', [['Field' => '/scope/schema', 'Rule' => self::RULE_UNREADABLE]]);
        }

        return array_map(static fn ($r) => ['migration' => (string) $r->migration, 'batch' => (int) $r->batch], $raw->all());
    }

    /**
     * Canonical JSONL: one row per line, keys sorted lexicographically
     * (`batch` before `migration`), LF-terminated, UTF-8. Identical
     * input yields identical bytes so `ContentHash` is deterministic
     * across runs (INV-BR-MS-3 upstream via the manifest builder).
     *
     * @param list<array{migration:string, batch:int}> $rows
     */
    private function renderJsonl(array $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            ksort($row);
            $out .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        return $out;
    }

    /**
     * SHA-256 over concatenated migration file bodies in table order.
     * Missing file => `BackupCorrupt` (server-side integrity failure).
     *
     * @param list<array{migration:string, batch:int}> $rows
     */
    private function hashFiles(array $rows, string $requestId): string
    {
        $ctx = hash_init('sha256');
        $dir = $this->migrationsPath();
        foreach ($rows as $row) {
            $path = $dir . DIRECTORY_SEPARATOR . $row['migration'] . '.php';
            if (is_file($path) === false) {
                Log::error('br.export.scope.schema.file_missing', ['Migration' => $row['migration'], 'Path' => $path, 'RequestId' => $requestId]);
                throw InternalException::custom(self::ERR_CORRUPT, 'Migration file referenced by root.migrations row is missing on disk.', [['Field' => '/scope/schema/migrations/' . $row['migration'], 'Rule' => self::RULE_MISSING_FILE]]);
            }
            hash_update_file($ctx, $path);
        }

        return hash_final($ctx);
    }

    private function migrationsPath(): string
    {
        $configured = (string) config(self::CFG_MIGRATION_PATH, '');
        if ($configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        return rtrim(database_path('migrations' . DIRECTORY_SEPARATOR . 'root'), DIRECTORY_SEPARATOR);
    }
}
