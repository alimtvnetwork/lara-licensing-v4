<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 14 step 16. SC-C "Feature catalog" collector for the S1 shadow
 * Export path.
 *
 * Normative sources:
 *  - spec/26-backup-restore/05-scope-catalog.md §"SC-C Feature catalog"
 *    (selector = `SELECT * FROM public.features` +
 *    `SELECT * FROM public.feature_defaults`, whole scope, restore rank 3).
 *  - spec/26-backup-restore/07-manifest-schema.md §"`scope` Shape"
 *    (manifest slot `manifest.scope.features = {contentHash, featureCount,
 *    defaultCount}`).
 *  - spec/26-backup-restore/04-invariants.md `INV-BR-MS-2` (every
 *    `scope.*.contentHash` hashes the class's real bytes; empty
 *    placeholders are a validator violation once real content ships).
 *
 * App shape differs from the generic spec names: the Root DB tables are
 * `Features` (FeatureId, FeatureKey, ValueType) and `TierFeatures`
 * (LicenseTierId, FeatureId, Value JSONB) per Plan 06 migration
 * `2026_07_18_000005_create_root_features_and_tier_features_tables`.
 * Shard-side `LicenseFeatures` overrides ship separately under SC-D
 * per spec 26 §05 (they are per-license, not per-tier). This collector
 * only binds SC-C.
 *
 * Portability: rows are keyed by `FeatureKey` (spec 21/45 closed-set
 * identifier), never by the surrogate `FeatureId`, so a Restore into a
 * different Root instance re-links defaults correctly even when the
 * catalog is renumbered.
 *
 * Failure model (strict, no swallowing):
 *  - Root DB unreachable or either table missing => `BackupStorageFailure`
 *    (500) at `/scope/features` with rule `FeatureCatalogUnreadable`.
 *  - A `TierFeatures` row references a `FeatureId` absent from the
 *    catalog snapshot => `BackupCorrupt` (422) at
 *    `/scope/features/tierDefaults/<FeatureId>` rule
 *    `TierFeatureFeatureIdUnknown`. Mirrors SC-A's `MigrationFileMissing`
 *    semantics: mid-Export server drift fails fast rather than producing
 *    a mis-hashed archive that would break Import preflight.
 *
 * 15-line function cap held by splitting into `loadFeatures`,
 * `loadDefaults`, `renderJsonl`, and `resolveKey`.
 */
final class BrScopeFeaturesCollector
{
    private const CONN = 'root';
    private const TABLE_FEATURES = 'Features';
    private const TABLE_TIER_FEATURES = 'TierFeatures';
    private const REL_PATH = 'scope/features.jsonl.zst';

    private const ROW_TYPE_FEATURE = 'Feature';
    private const ROW_TYPE_TIER_DEFAULT = 'TierFeatureDefault';

    private const ERR_UNREADABLE = 'BackupStorageFailure';
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const RULE_UNREADABLE = 'FeatureCatalogUnreadable';
    private const RULE_UNKNOWN_FEATURE_ID = 'TierFeatureFeatureIdUnknown';

    private const LOG_COLLECTED = 'br.export.scope.features.collected';
    private const LOG_UNREADABLE = 'br.export.scope.features.unreadable';
    private const LOG_UNKNOWN_FEATURE = 'br.export.scope.features.unknown_feature_id';

    /**
     * Collect SC-C rows and return the JSONL payload + manifest slot
     * fields (`featureCount`, `defaultCount`, `contentHash`).
     *
     * @return array{
     *   Jsonl: string,
     *   RelPath: string,
     *   ContentHash: string,
     *   FeatureCount: int,
     *   DefaultCount: int
     * }
     */
    public function collect(string $requestId): array
    {
        $features = $this->loadFeatures($requestId);
        $defaults = $this->loadDefaults($features, $requestId);
        $jsonl = $this->renderJsonl($features, $defaults);
        $contentHash = hash('sha256', $jsonl);
        Log::info(self::LOG_COLLECTED, ['FeatureCount' => count($features), 'DefaultCount' => count($defaults), 'ContentHash' => $contentHash, 'BodyBytes' => strlen($jsonl), 'RequestId' => $requestId]);

        return ['Jsonl' => $jsonl, 'RelPath' => self::REL_PATH, 'ContentHash' => $contentHash, 'FeatureCount' => count($features), 'DefaultCount' => count($defaults)];
    }

    /**
     * @return array<int,array{FeatureKey:string, ValueType:string}>
     *         Keyed by FeatureId so tier-default rows can resolve.
     */
    private function loadFeatures(string $requestId): array
    {
        try {
            $rows = DB::connection(self::CONN)->table(self::TABLE_FEATURES)->orderBy('FeatureKey')->get(['FeatureId', 'FeatureKey', 'ValueType']);
        } catch (Throwable $e) {
            Log::error(self::LOG_UNREADABLE, ['Table' => self::TABLE_FEATURES, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Root Features table unreadable at Export time.', [['Field' => '/scope/features', 'Rule' => self::RULE_UNREADABLE]]);
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->FeatureId] = ['FeatureKey' => (string) $row->FeatureKey, 'ValueType' => (string) $row->ValueType];
        }

        return $map;
    }

    /**
     * @param  array<int,array{FeatureKey:string, ValueType:string}>  $features
     * @return list<array{FeatureKey:string, LicenseTierId:int, Value:mixed}>
     */
    private function loadDefaults(array $features, string $requestId): array
    {
        try {
            $rows = DB::connection(self::CONN)->table(self::TABLE_TIER_FEATURES)->orderBy('LicenseTierId')->orderBy('FeatureId')->get(['LicenseTierId', 'FeatureId', 'Value']);
        } catch (Throwable $e) {
            Log::error(self::LOG_UNREADABLE, ['Table' => self::TABLE_TIER_FEATURES, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Root TierFeatures table unreadable at Export time.', [['Field' => '/scope/features', 'Rule' => self::RULE_UNREADABLE]]);
        }

        return $this->mapDefaults($rows->all(), $features, $requestId);
    }

    /**
     * @param  list<object>  $rows
     * @param  array<int,array{FeatureKey:string, ValueType:string}>  $features
     * @return list<array{FeatureKey:string, LicenseTierId:int, Value:mixed}>
     */
    private function mapDefaults(array $rows, array $features, string $requestId): array
    {
        $out = [];
        foreach ($rows as $row) {
            $featureId = (int) $row->FeatureId;
            $key = $this->resolveKey($featureId, $features, $requestId);
            $out[] = ['FeatureKey' => $key, 'LicenseTierId' => (int) $row->LicenseTierId, 'Value' => json_decode((string) $row->Value, true, 512, JSON_THROW_ON_ERROR)];
        }
        usort($out, static fn ($a, $b) => [$a['LicenseTierId'], $a['FeatureKey']] <=> [$b['LicenseTierId'], $b['FeatureKey']]);

        return $out;
    }

    /**
     * @param  array<int,array{FeatureKey:string, ValueType:string}>  $features
     */
    private function resolveKey(int $featureId, array $features, string $requestId): string
    {
        if (isset($features[$featureId]) === false) {
            Log::error(self::LOG_UNKNOWN_FEATURE, ['FeatureId' => $featureId, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_CORRUPT, 'TierFeatures row references a FeatureId absent from the Features catalog snapshot.', [['Field' => '/scope/features/tierDefaults/' . $featureId, 'Rule' => self::RULE_UNKNOWN_FEATURE_ID]]);
        }

        return $features[$featureId]['FeatureKey'];
    }

    /**
     * Canonical JSONL: Feature rows first (ascending FeatureKey), then
     * TierFeatureDefault rows (ascending LicenseTierId, then FeatureKey).
     * Keys sorted lexicographically per row, LF-terminated, UTF-8.
     *
     * @param  array<int,array{FeatureKey:string, ValueType:string}>  $features
     * @param  list<array{FeatureKey:string, LicenseTierId:int, Value:mixed}>  $defaults
     */
    private function renderJsonl(array $features, array $defaults): string
    {
        $sortedFeatures = array_values($features);
        usort($sortedFeatures, static fn ($a, $b) => $a['FeatureKey'] <=> $b['FeatureKey']);
        $out = '';
        foreach ($sortedFeatures as $f) {
            $row = ['FeatureKey' => $f['FeatureKey'], 'RowType' => self::ROW_TYPE_FEATURE, 'ValueType' => $f['ValueType']];
            $out .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }
        foreach ($defaults as $d) {
            $row = ['FeatureKey' => $d['FeatureKey'], 'LicenseTierId' => $d['LicenseTierId'], 'RowType' => self::ROW_TYPE_TIER_DEFAULT, 'Value' => $d['Value']];
            $out .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        return $out;
    }
}
