<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrCryptoConstants as C;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Plan 14 step 21. SC-G "Secrets envelope" collector for the S1 shadow
 * Export path.
 *
 * Normative sources:
 *  - spec/26-backup-restore/05-scope-catalog.md §"SC-G Secrets envelope"
 *    (selector = HKDF-sealed blob of every row column marked sensitive;
 *    manifest slot `manifest.scope.secretsEnvelope`; restore boundary
 *    "whole"; restore rank 7; re-seal on Restore under Active epoch).
 *  - spec/26-backup-restore/07-manifest-schema.md §"scope" required
 *    slot `secretsEnvelope = {contentHash, algorithm:"hkdf-sha256",
 *    epoch:int, kid:string}`.
 *  - spec/26-backup-restore/08-archive-format.md line 79
 *    (`scope/secrets-envelope.bin.zst` sits between SC-F domain shards
 *    and SC-H file-index bodies; INV-BR-AF-1 order).
 *  - spec/26-backup-restore/09-encryption-and-keys.md §"Key Hierarchy"
 *    (DEK-content derived HKDF-SHA-256 salt+info, per-row AES-256-GCM
 *    seal, INV-BR-EK-2/EK-4/EK-5).
 *  - INV-BR-MS-2 (every `scope.*.contentHash` hashes real bytes).
 *
 * Body layout (deterministic across runs):
 *  - JSONL, one line per sealed secret row, keys alpha-sorted:
 *    `{Epoch, Field, Kid, NonceB64, PayloadB64, PkB64, Table}`.
 *  - Nonce is derived deterministically from
 *    HMAC-SHA-256(aadSecret, "<table>|<field>|<pkB64>|<ordinal>")
 *    truncated to 12 bytes so archive bytes reproduce (INV-BR-EK-3
 *    still holds because the (chunkOrdinal, frameOrdinal) uniqueness
 *    per DEK is guaranteed by the (table, field, pk, ordinal) tuple
 *    being unique). Real production sealing may switch to random
 *    nonces once Step 22 (encryption sealing) lands; the manifest
 *    slot fields are unaffected.
 *  - PayloadB64 is the AES-256-GCM `ct || tag` output of encryptFrame
 *    under DEK-content = HKDF(kek, info='lara/backup/v1/dek', salt=zero).
 *
 * Selector: config-driven `lara.br.secret_sources`. Each entry:
 *    { table, pkColumn, fieldColumn, valueColumn,
 *      whereColumn?, whereValue? }
 *  Root default is empty because no Root table today carries a
 *  per-row sensitive column (Casbin policies are structural, not
 *  secret; secrets live in `.env` outside the DB per EX-A).
 *
 * Failure model (strict, no swallowing):
 *  - Configured table absent => `BackupStorageFailure` (500) at
 *    `/scope/secrets-envelope/<name>` rule `SecretSourceTableMissing`.
 *  - Configured column absent => `BackupStorageFailure` at
 *    `/scope/secrets-envelope/<name>/<column>` rule
 *    `SecretSourceColumnMissing`.
 *  - Unreadable source => `BackupStorageFailure` rule
 *    `SecretSourceUnreadable`.
 *  - Row missing field, pk, or value column => `BackupCorrupt` (422)
 *    at `/scope/secrets-envelope/<name>` rule `SecretEntryIncomplete`.
 *
 * 15-line function cap held by splitting into `resolveSources`,
 * `deriveMaterial`, `collectOne`, `assertColumns`, `readRows`,
 * `sealRow`, `deriveNonce`, `aggregate`.
 */
final class BrScopeSecretsCollector
{
    private const CONN_ROOT = 'root';
    private const REL_PATH = 'scope/secrets-envelope.bin.zst';
    private const CONFIG_SOURCES = 'lara.br.secret_sources';
    private const ALGORITHM = 'hkdf-sha256';

    private const KEY_TABLE = 'table';
    private const KEY_PK = 'pkColumn';
    private const KEY_FIELD = 'fieldColumn';
    private const KEY_VALUE = 'valueColumn';
    private const KEY_WHERE_COL = 'whereColumn';
    private const KEY_WHERE_VAL = 'whereValue';

    private const ERR_UNREADABLE = 'BackupStorageFailure';
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const RULE_TABLE_MISSING = 'SecretSourceTableMissing';
    private const RULE_COLUMN_MISSING = 'SecretSourceColumnMissing';
    private const RULE_UNREADABLE = 'SecretSourceUnreadable';
    private const RULE_INCOMPLETE = 'SecretEntryIncomplete';
    private const FIELD_ROOT = '/scope/secrets-envelope';

    private const LOG_COLLECTED = 'br.export.scope.secrets.collected';
    private const LOG_SOURCE = 'br.export.scope.secrets.source';
    private const LOG_TABLE_MISSING = 'br.export.scope.secrets.table_missing';
    private const LOG_COLUMN_MISSING = 'br.export.scope.secrets.column_missing';
    private const LOG_SOURCE_UNREADABLE = 'br.export.scope.secrets.source_unreadable';
    private const LOG_INCOMPLETE = 'br.export.scope.secrets.entry_incomplete';

    /** Deterministic zero salt for shadow reproducibility (32 bytes). */
    private const SHADOW_SALT = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";
    private const NONCE_BYTES = 12;
    private const AAD_SEP = '|';

    public function __construct(
        private readonly BrKekService $keks,
        private readonly BrCryptoService $crypto,
    ) {}

    /**
     * Collect SC-G sealed rows and the manifest slot fields.
     *
     * @return array{
     *   Body:string,
     *   RelPath:string,
     *   ContentHash:string,
     *   Algorithm:string,
     *   Epoch:int,
     *   Kid:string,
     *   RowCount:int
     * }
     */
    public function collect(string $requestId): array
    {
        $epochRow = $this->keks->resolveActive();
        [$dek, $aadSecret] = $this->deriveMaterial($epochRow);
        $sources = $this->resolveSources();
        $lines = [];
        foreach ($sources as $src) {
            foreach ($this->collectOne($src, $dek, $aadSecret, $epochRow, $requestId) as $line) {
                $lines[] = $line;
            }
        }

        return $this->aggregate($lines, $epochRow, $requestId);
    }

    /**
     * @param  array{Epoch:int, Kid:string, State:string, SecretsRef:string}  $epochRow
     * @return array{0:string, 1:string}
     */
    private function deriveMaterial(array $epochRow): array
    {
        $kek = $this->keks->materialFor($epochRow);
        $dek = $this->crypto->deriveDekContent($kek, self::SHADOW_SALT);
        $aad = $this->crypto->deriveAadSecret($kek, self::SHADOW_SALT);

        return [$dek, $aad];
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function resolveSources(): array
    {
        /** @var list<array<string, string|int>> $configured */
        $configured = (array) config(self::CONFIG_SOURCES, []);

        return array_values($configured);
    }

    /**
     * @param  array<string, string|int>  $src
     * @param  array{Epoch:int, Kid:string, State:string, SecretsRef:string}  $epochRow
     * @return list<string>
     */
    private function collectOne(array $src, string $dek, string $aadSecret, array $epochRow, string $requestId): array
    {
        $table = (string) $src[self::KEY_TABLE];
        if (! Schema::connection(self::CONN_ROOT)->hasTable($table)) {
            Log::error(self::LOG_TABLE_MISSING, ['Table' => $table, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Secret-source table missing at Export time: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_TABLE_MISSING]]);
        }
        $this->assertColumns($table, $src, $requestId);
        $rows = $this->readRows($table, $src, $requestId);
        $out = [];
        foreach ($rows as $ordinal => $row) {
            $out[] = $this->sealRow($row, $src, $dek, $aadSecret, $epochRow, $ordinal, $requestId);
        }
        Log::info(self::LOG_SOURCE, ['Table' => $table, 'RowCount' => count($out), 'Epoch' => $epochRow['Epoch'], 'RequestId' => $requestId]);

        return $out;
    }

    /**
     * @param  array<string, string|int>  $src
     */
    private function assertColumns(string $table, array $src, string $requestId): void
    {
        $needed = [self::KEY_PK, self::KEY_FIELD, self::KEY_VALUE];
        if (isset($src[self::KEY_WHERE_COL])) {
            $needed[] = self::KEY_WHERE_COL;
        }
        foreach ($needed as $k) {
            $col = (string) $src[$k];
            if (! Schema::connection(self::CONN_ROOT)->hasColumn($table, $col)) {
                Log::error(self::LOG_COLUMN_MISSING, ['Table' => $table, 'Column' => $col, 'RequestId' => $requestId]);
                throw InternalException::custom(self::ERR_UNREADABLE, 'Secret-source column missing on ' . $table . ': ' . $col, [['Field' => self::FIELD_ROOT . '/' . $table . '/' . $col, 'Rule' => self::RULE_COLUMN_MISSING]]);
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
            $rows = $q->orderBy((string) $src[self::KEY_PK])
                ->get([(string) $src[self::KEY_PK], (string) $src[self::KEY_FIELD], (string) $src[self::KEY_VALUE]]);
        } catch (Throwable $e) {
            Log::error(self::LOG_SOURCE_UNREADABLE, ['Table' => $table, 'Reason' => $e->getMessage(), 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Secret-source table unreadable at Export time: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_UNREADABLE]]);
        }

        return $rows->all();
    }

    /**
     * @param  array<string, string|int>  $src
     * @param  array{Epoch:int, Kid:string, State:string, SecretsRef:string}  $epochRow
     */
    private function sealRow(object $row, array $src, string $dek, string $aadSecret, array $epochRow, int $ordinal, string $requestId): string
    {
        $table = (string) $src[self::KEY_TABLE];
        $pk = (string) ($row->{(string) $src[self::KEY_PK]} ?? '');
        $field = (string) ($row->{(string) $src[self::KEY_FIELD]} ?? '');
        $value = (string) ($row->{(string) $src[self::KEY_VALUE]} ?? '');
        if ($pk === '' || $field === '' || $value === '') {
            Log::error(self::LOG_INCOMPLETE, ['Table' => $table, 'Pk' => $pk, 'Field' => $field, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_CORRUPT, 'Secret entry incomplete in table: ' . $table, [['Field' => self::FIELD_ROOT . '/' . $table, 'Rule' => self::RULE_INCOMPLETE]]);
        }
        $nonce = $this->deriveNonce($aadSecret, $table, $field, $pk, $ordinal);
        $aad = $table . self::AAD_SEP . $field;
        $entry = ['Epoch' => (int) $epochRow['Epoch'], 'Field' => $field, 'Kid' => (string) $epochRow['Kid'], 'NonceB64' => base64_encode($nonce), 'PayloadB64' => base64_encode($this->crypto->encryptFrame($dek, $value, $nonce, $aad)), 'PkB64' => base64_encode($pk), 'Table' => $table];

        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function deriveNonce(string $aadSecret, string $table, string $field, string $pk, int $ordinal): string
    {
        $msg = $table . self::AAD_SEP . $field . self::AAD_SEP . base64_encode($pk) . self::AAD_SEP . (string) $ordinal;
        $full = hash_hmac('sha256', $msg, $aadSecret, true);

        return substr($full, 0, self::NONCE_BYTES);
    }

    /**
     * @param  list<string>  $lines
     * @param  array{Epoch:int, Kid:string, State:string, SecretsRef:string}  $epochRow
     * @return array{Body:string, RelPath:string, ContentHash:string, Algorithm:string, Epoch:int, Kid:string, RowCount:int}
     */
    private function aggregate(array $lines, array $epochRow, string $requestId): array
    {
        sort($lines, SORT_STRING);
        $body = $lines === [] ? '' : implode("\n", $lines) . "\n";
        $contentHash = hash('sha256', $body);
        Log::info(self::LOG_COLLECTED, ['RowCount' => count($lines), 'ContentHash' => $contentHash, 'Bytes' => strlen($body), 'Epoch' => (int) $epochRow['Epoch'], 'Kid' => (string) $epochRow['Kid'], 'RequestId' => $requestId]);

        return ['Body' => $body, 'RelPath' => self::REL_PATH, 'ContentHash' => $contentHash, 'Algorithm' => self::ALGORITHM, 'Epoch' => (int) $epochRow['Epoch'], 'Kid' => (string) $epochRow['Kid'], 'RowCount' => count($lines)];
    }
}
