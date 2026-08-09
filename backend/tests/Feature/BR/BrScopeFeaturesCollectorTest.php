<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrScopeFeaturesCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 14 step 16 contract tests for the SC-C features collector.
 *
 * Locks:
 *  - JSONL is canonical: Feature rows first (ascending FeatureKey),
 *    then TierFeatureDefault rows (ascending LicenseTierId, FeatureKey);
 *    keys sorted lexicographically per row; LF-terminated; UTF-8.
 *  - `ContentHash = sha256(Jsonl)`, `FeatureCount` / `DefaultCount`
 *    mirror the manifest slot (INV-BR-MS-2 anchor).
 *  - Root Features table unreadable => `BackupStorageFailure` at
 *    `/scope/features` with rule `FeatureCatalogUnreadable`.
 *  - TierFeatures row with an unknown FeatureId => `BackupCorrupt`
 *    at `/scope/features/tierDefaults/<FeatureId>` rule
 *    `TierFeatureFeatureIdUnknown`.
 */
final class BrScopeFeaturesCollectorTest extends TestCase
{
    private const REQUEST_ID = 'req-sc-c-0001';

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic reset so the seeded catalog + tier-default fixtures
        // do not depend on migration ordering side effects across tests.
        DB::connection('root')->table('TierFeatures')->delete();
    }

    public function test_emits_canonical_jsonl_and_counts_with_no_defaults(): void
    {
        $out = app(BrScopeFeaturesCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame('scope/features.jsonl.zst', $out['RelPath']);
        $this->assertSame(hash('sha256', $out['Jsonl']), $out['ContentHash']);
        $registryCount = count((array) config('lara.feature_registry'));
        $this->assertSame($registryCount, $out['FeatureCount']);
        $this->assertSame(0, $out['DefaultCount']);
        $this->assertSame($out['FeatureCount'], substr_count($out['Jsonl'], "\n"));

        $lines = array_values(array_filter(explode("\n", $out['Jsonl'])));
        $first = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['FeatureKey', 'RowType', 'ValueType'], array_keys($first));
        $this->assertSame('Feature', $first['RowType']);
    }

    public function test_feature_rows_ascending_feature_key(): void
    {
        $out = app(BrScopeFeaturesCollector::class)->collect(self::REQUEST_ID);
        $keys = [];
        foreach (explode("\n", trim($out['Jsonl'])) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($row['RowType'] === 'Feature') {
                $keys[] = $row['FeatureKey'];
            }
        }
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    public function test_tier_defaults_included_and_sorted(): void
    {
        $feature = DB::connection('root')->table('Features')->orderBy('FeatureKey')->first();
        $this->assertNotNull($feature);
        DB::connection('root')->table('TierFeatures')->insert([
            'LicenseTierId' => 2, 'FeatureId' => $feature->FeatureId, 'Value' => json_encode(true),
            'CreatedByUserId' => 1, 'CreatedAt' => now(), 'UpdatedAt' => now(),
        ]);
        DB::connection('root')->table('TierFeatures')->insert([
            'LicenseTierId' => 1, 'FeatureId' => $feature->FeatureId, 'Value' => json_encode(false),
            'CreatedByUserId' => 1, 'CreatedAt' => now(), 'UpdatedAt' => now(),
        ]);

        $out = app(BrScopeFeaturesCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame(2, $out['DefaultCount']);
        $defaults = [];
        foreach (explode("\n", trim($out['Jsonl'])) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($row['RowType'] === 'TierFeatureDefault') {
                $defaults[] = $row;
            }
        }
        $this->assertSame([1, 2], array_map(static fn ($r) => $r['LicenseTierId'], $defaults));
        $this->assertSame(['FeatureKey', 'LicenseTierId', 'RowType', 'Value'], array_keys($defaults[0]));
    }

    public function test_unknown_feature_id_raises_backup_corrupt(): void
    {
        DB::connection('root')->table('TierFeatures')->insert([
            'LicenseTierId' => 1, 'FeatureId' => 32000, 'Value' => json_encode(true),
            'CreatedByUserId' => 1, 'CreatedAt' => now(), 'UpdatedAt' => now(),
        ]);
        // Bypass the surrogate-FK by deleting the row indirectly: insert a
        // FeatureId that will not appear in the catalog snapshot because
        // no Features row uses that Id. The BIGINT column accepts it.
        // Then invoke the collector.
        try {
            app(BrScopeFeaturesCollector::class)->collect(self::REQUEST_ID);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('BackupCorrupt', $e->errorCode);
            $this->assertSame('/scope/features/tierDefaults/32000', $e->violations[0]['Field']);
            $this->assertSame('TierFeatureFeatureIdUnknown', $e->violations[0]['Rule']);
        }
    }

    public function test_unreadable_features_table_raises_backup_storage_failure(): void
    {
        Schema::connection('root')->rename('Features', 'FeaturesBackupTmp');
        try {
            app(BrScopeFeaturesCollector::class)->collect(self::REQUEST_ID);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/features', $e->violations[0]['Field']);
            $this->assertSame('FeatureCatalogUnreadable', $e->violations[0]['Rule']);
        } finally {
            Schema::connection('root')->rename('FeaturesBackupTmp', 'Features');
        }
    }

    public function test_determinism(): void
    {
        $a = app(BrScopeFeaturesCollector::class)->collect(self::REQUEST_ID);
        $b = app(BrScopeFeaturesCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame($a['Jsonl'], $b['Jsonl']);
        $this->assertSame($a['ContentHash'], $b['ContentHash']);
    }
}
