<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Plan 06 step 41 followup. Idempotent seeder for the Root `Features`
 * catalog.
 *
 * The `create_root_features_and_tier_features_tables` migration seeds
 * the catalog once at `up()` time. This seeder is the reusable path
 * used by `RootSeeder` and by tests to (re)synchronise the catalog with
 * `config('lara.feature_registry')` after registry edits. Behaviour is
 * strictly additive: rows for keys removed from the registry are left
 * intact (deletions require a dedicated migration so shard
 * `LicenseFeatures` FeatureId references can be reconciled first).
 *
 * Normative source: spec/21-app/45-license-features.md v1.0.0 §2. Any
 * drift between the config registry and the `Features` table is a bug;
 * `FeatureService::assertCatalogSeeded()` performs the runtime check.
 */
final class FeatureCatalogSeeder extends Seeder
{
    private const CONN = 'root';
    private const TABLE = 'Features';
    private const CONFIG_KEY = 'lara.feature_registry';

    public function run(): void
    {
        $registry = (array) config(self::CONFIG_KEY, []);
        if ($registry === []) {
            throw new RuntimeException(
                "FeatureCatalogSeeder: config('" . self::CONFIG_KEY . "') must be non-empty."
            );
        }
        foreach ($registry as $featureKey => $meta) {
            $this->seedRow((string) $featureKey, is_array($meta) ? $meta : []);
        }
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function seedRow(string $featureKey, array $meta): void
    {
        $valueType = isset($meta['ValueType']) && is_string($meta['ValueType']) ? $meta['ValueType'] : '';
        if ($valueType === '') {
            throw new RuntimeException(
                "FeatureCatalogSeeder: '{$featureKey}' missing ValueType in config."
            );
        }
        DB::connection(self::CONN)->statement(
            'INSERT INTO "' . self::TABLE . '" ("FeatureKey","ValueType") VALUES (?,?) ON CONFLICT ("FeatureKey") DO NOTHING',
            [$featureKey, $valueType],
        );
        $this->command?->line("  root.Features seeded: {$featureKey} ({$valueType})");
    }
}
