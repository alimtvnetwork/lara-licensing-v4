<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Domain\ClosedSets\ClosedSetCatalogue;
use App\Exceptions\LaraException;
use App\Services\BR\BrScopeClosedSetsCollector;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Plan 14 step 15 contract tests for the SC-B closed-sets collector.
 *
 * Locks:
 *  - JSONL is canonical: one row per (SetId, Ordinal), keys sorted
 *    lexicographically (`Ordinal`, `SetId`, `ValueKey`), LF-terminated.
 *  - Sets are emitted in ascending SetId; values in ascending Ordinal.
 *  - `ContentHash = sha256(Jsonl)`, `SetCount` / `ValueCount` mirror
 *    the manifest slot (INV-BR-MS-2 anchor).
 *  - Missing/empty catalogue config throws `BackupStorageFailure`
 *    at `/scope/closedSets` with rule `ClosedSetCatalogueUnreadable`.
 */
final class BrScopeClosedSetsCollectorTest extends TestCase
{
    private const REQUEST_ID = 'req-sc-b-0001';

    public function test_emits_canonical_jsonl_and_counts(): void
    {
        $out = app(BrScopeClosedSetsCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame('scope/closed-sets.jsonl.zst', $out['RelPath']);
        $this->assertSame(hash('sha256', $out['Jsonl']), $out['ContentHash']);
        $this->assertSame(5, $out['SetCount']);
        // 4 AppRole + 3 Environment + 7 LicenseCategory + 3 LicenseTier + 4 QuotaRequestStatus = 21
        $this->assertSame(21, $out['ValueCount']);
        $this->assertSame($out['ValueCount'], substr_count($out['Jsonl'], "\n"));

        $lines = array_values(array_filter(explode("\n", $out['Jsonl'])));
        $first = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Ordinal', 'SetId', 'ValueKey'], array_keys($first));
        $this->assertSame(ClosedSetCatalogue::SET_APP_ROLE, $first['SetId']);
        $this->assertSame(1, $first['Ordinal']);
    }

    public function test_set_order_is_ascending_set_id(): void
    {
        $out = app(BrScopeClosedSetsCollector::class)->collect(self::REQUEST_ID);
        $setsSeen = [];
        foreach (explode("\n", trim($out['Jsonl'])) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $setsSeen[$row['SetId']] = true;
        }
        $this->assertSame(
            [ClosedSetCatalogue::SET_APP_ROLE, ClosedSetCatalogue::SET_ENVIRONMENT, ClosedSetCatalogue::SET_LICENSE_CATEGORY, ClosedSetCatalogue::SET_LICENSE_TIER, ClosedSetCatalogue::SET_QUOTA_REQUEST_STATUS],
            array_keys($setsSeen),
        );
    }

    public function test_values_within_set_sorted_by_ordinal(): void
    {
        $out = app(BrScopeClosedSetsCollector::class)->collect(self::REQUEST_ID);
        $perSet = [];
        foreach (explode("\n", trim($out['Jsonl'])) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $perSet[$row['SetId']][] = $row['Ordinal'];
        }
        foreach ($perSet as $setId => $ordinals) {
            $sorted = $ordinals;
            sort($sorted);
            $this->assertSame($sorted, $ordinals, "Ordinals not ascending for {$setId}");
        }
    }

    public function test_missing_config_raises_backup_storage_failure(): void
    {
        Config::set('lara.license_categories', []);
        try {
            app(BrScopeClosedSetsCollector::class)->collect(self::REQUEST_ID);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/closedSets', $e->violations[0]['Field']);
            $this->assertSame('ClosedSetCatalogueUnreadable', $e->violations[0]['Rule']);
        }
    }

    public function test_determinism(): void
    {
        $a = app(BrScopeClosedSetsCollector::class)->collect(self::REQUEST_ID);
        $b = app(BrScopeClosedSetsCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame($a['Jsonl'], $b['Jsonl']);
        $this->assertSame($a['ContentHash'], $b['ContentHash']);
    }
}
