<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 41. Runtime feature-map resolver.
 *
 * Normative source: spec/21-app/45-license-features.md v1.0.0 §4.
 * Precedence is strictly `LicenseFeatures` (shard, per-license override)
 * over `TierFeatures` (Root, tier default). Absence of a key means
 * "not licensed"; callers MUST NOT synthesize defaults (AC-FEAT-004,
 * AC-FEAT-005).
 *
 * Cross-DB physical FKs are forbidden per split-DB architecture
 * (spec/23-app-db/10-reseller-shard-split-db.md §App-tier). This service
 * therefore performs two independent reads (Root + shard) and joins them
 * in PHP by FeatureId. FeatureIds referenced by shard rows that do not
 * resolve in Root are logged and dropped, never silently coerced.
 */
final class FeatureService
{
    private const ROOT_CONNECTION = 'root';
    private const SHARD_CONNECTION_DEFAULT = 'shard';
    private const FEATURES_TABLE = 'Features';
    private const TIER_FEATURES_TABLE = 'TierFeatures';
    private const LICENSE_FEATURES_TABLE = 'LicenseFeatures';
    private const LICENSES_TABLE = 'Licenses';
    private const ERROR_LICENSE_MISSING = 'LicenseNotFound';
    private const ERROR_CATALOG_DRIFT = 'FeatureCatalogUnseeded';
    private const FEATURE_REGISTRY_CONFIG = 'lara.feature_registry';

    /**
     * Preflight guard: assert every FeatureKey declared in the
     * config registry exists in Root `Features`. Called before any
     * write path that may persist a FeatureId reference (license
     * issuance, tier defaults, per-license overrides) so drift fails
     * fast with a diagnostic error rather than an obscure FK / NULL
     * FeatureId later. Read-only; safe to call in hot paths.
     *
     * Throws LaraException(FeatureCatalogUnseeded) listing the missing
     * keys so operators can run `db:seed --class=FeatureCatalogSeeder`.
     */
    public function assertCatalogSeeded(): void
    {
        $registry = array_keys((array) config(self::FEATURE_REGISTRY_CONFIG, []));
        if ($registry === []) {
            throw InternalException::custom(self::ERROR_CATALOG_DRIFT,
                'Root Features registry is empty; check config(lara.feature_registry).',
                [['Field' => 'FeatureRegistry', 'Rule' => 'NonEmpty']],
            );
        }
        $seeded = DB::connection(self::ROOT_CONNECTION)
            ->table(self::FEATURES_TABLE)
            ->whereIn('FeatureKey', $registry)
            ->pluck('FeatureKey')
            ->all();
        $missing = array_values(array_diff($registry, array_map('strval', $seeded)));
        if ($missing !== []) {
            $details = array_map(
                static fn (string $key): array => ['Field' => 'Features.' . $key, 'Rule' => 'Missing'],
                $missing,
            );
            throw InternalException::custom(self::ERROR_CATALOG_DRIFT,
                'Root Features catalog is missing ' . count($missing) . ' registry key(s); run FeatureCatalogSeeder.',
                $details,
            );
        }
    }

    /**
     * Resolve the effective FeatureKey -> Value map for a license.
     *
     * @return array<string,bool|int|float|string>
     */
    public function resolve(int $licenseId, string $shardConnection = self::SHARD_CONNECTION_DEFAULT): array
    {
        $tierId = $this->loadTierId($licenseId, $shardConnection);
        $catalog = $this->loadFeatureCatalog();
        $tierLayer = $this->loadTierLayer($tierId, $catalog);
        $overrides = $this->loadLicenseOverrides($licenseId, $shardConnection, $catalog);

        return array_merge($tierLayer, $overrides);
    }

    /**
     * Plan 06 step 81. Same two reads as `resolve()`, but the layers are
     * returned unmerged so the console can show provenance (tier default vs
     * per-license override) instead of only the flattened runtime map. The
     * merge itself is mirrored client-side in
     * `resources/js/lib/featureMap.ts::resolveFeatureMap`, which MUST keep the
     * same precedence: `LicenseOverrides` over `TierDefaults` (spec 45 §4).
     *
     * Read-only and side-effect free; the runtime verify path keeps using
     * `resolve()` so its wire shape is untouched.
     *
     * @return array{
     *   LicenseTierId:int,
     *   TierDefaults:array<string,bool|int|float|string>,
     *   LicenseOverrides:array<string,bool|int|float|string>,
     *   ValueTypes:array<string,string>
     * }
     */
    public function layers(int $licenseId, string $shardConnection = self::SHARD_CONNECTION_DEFAULT): array
    {
        $tierId = $this->loadTierId($licenseId, $shardConnection);
        $catalog = $this->loadFeatureCatalog();
        $tierDefaults = $this->loadTierLayer($tierId, $catalog);
        $overrides = $this->loadLicenseOverrides($licenseId, $shardConnection, $catalog);

        $valueTypes = [];
        foreach ($catalog as $entry) {
            $valueTypes[$entry['Key']] = $entry['ValueType'];
        }

        return [
            'LicenseTierId' => $tierId,
            'TierDefaults' => $tierDefaults,
            'LicenseOverrides' => $overrides,
            'ValueTypes' => $valueTypes,
        ];
    }



    private function loadTierId(int $licenseId, string $shardConnection): int
    {
        $row = DB::connection($shardConnection)
            ->table(self::LICENSES_TABLE)
            ->where('LicenseId', $licenseId)
            ->first(['LicenseTierId']);
        if ($row === null) {
            throw new LaraException(self::ERROR_LICENSE_MISSING, ['LicenseId' => $licenseId]);
        }

        return (int) $row->LicenseTierId;
    }

    /**
     * @return array<int,array{Key:string,ValueType:string}>
     */
    private function loadFeatureCatalog(): array
    {
        $rows = DB::connection(self::ROOT_CONNECTION)
            ->table(self::FEATURES_TABLE)
            ->get(['FeatureId', 'FeatureKey', 'ValueType']);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->FeatureId] = ['Key' => (string) $row->FeatureKey, 'ValueType' => (string) $row->ValueType];
        }

        return $map;
    }

    /**
     * @param array<int,array{Key:string,ValueType:string}> $catalog
     * @return array<string,bool|int|float|string>
     */
    private function loadTierLayer(int $tierId, array $catalog): array
    {
        $rows = DB::connection(self::ROOT_CONNECTION)
            ->table(self::TIER_FEATURES_TABLE)
            ->where('LicenseTierId', $tierId)
            ->get(['FeatureId', 'Value']);

        return $this->rowsToKeyValueMap($rows, $catalog);
    }

    /**
     * @param array<int,array{Key:string,ValueType:string}> $catalog
     * @return array<string,bool|int|float|string>
     */
    private function loadLicenseOverrides(int $licenseId, string $shardConnection, array $catalog): array
    {
        $rows = DB::connection($shardConnection)
            ->table(self::LICENSE_FEATURES_TABLE)
            ->where('LicenseId', $licenseId)
            ->get(['FeatureId', 'Value']);

        return $this->rowsToKeyValueMap($rows, $catalog);
    }

    /**
     * @param iterable<object> $rows
     * @param array<int,array{Key:string,ValueType:string}> $catalog
     * @return array<string,bool|int|float|string>
     */
    private function rowsToKeyValueMap(iterable $rows, array $catalog): array
    {
        $out = [];
        foreach ($rows as $row) {
            $entry = $catalog[(int) $row->FeatureId] ?? null;
            if ($entry === null) {
                continue;
            }
            $decoded = json_decode((string) $row->Value, true);
            if ($decoded === null && $row->Value !== 'null') {
                continue;
            }
            $out[$entry['Key']] = $decoded;
        }

        return $out;
    }
}
