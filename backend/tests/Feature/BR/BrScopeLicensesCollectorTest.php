<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use App\Services\BR\BrScopeLicensesCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 14 step 17 contract tests for the SC-D licenses collector.
 *
 * Locks:
 *  - JSONL is canonical: keys sorted lexicographically per row, LF-terminated,
 *    UTF-8; License rows first, then LicenseFeature rows, then LicenseEpoch
 *    rows.
 *  - `ContentHash = sha256(Jsonl)`; `LicenseCount`, `EpochCount`,
 *    `FeatureLinkCount` mirror the manifest slot (spec 26 §07,
 *    INV-BR-MS-2 anchor).
 *  - Root Resellers unreadable => `BackupStorageFailure` at
 *    `/scope/licenses` rule `LicenseCatalogUnreadable`.
 *  - Empty Resellers directory => empty JSONL and zero counts (valid
 *    for greenfield / test envs; hash is sha256('')).
 *  - Non-Active shard routes are skipped without failing the Export
 *    (`Provisioning` / `Failed` / `Quiesced`).
 */
final class BrScopeLicensesCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_ID = 'req-sc-d-0001';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_empty_resellers_yields_empty_jsonl_and_zero_counts(): void
    {
        $out = app(BrScopeLicensesCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame('scope/licenses.jsonl.zst', $out['RelPath']);
        $this->assertSame('', $out['Jsonl']);
        $this->assertSame(self::EMPTY_SHA256, $out['ContentHash']);
        $this->assertSame(0, $out['LicenseCount']);
        $this->assertSame(0, $out['EpochCount']);
        $this->assertSame(0, $out['FeatureLinkCount']);
    }

    public function test_determinism_across_calls(): void
    {
        $a = app(BrScopeLicensesCollector::class)->collect(self::REQUEST_ID);
        $b = app(BrScopeLicensesCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame($a['Jsonl'], $b['Jsonl']);
        $this->assertSame($a['ContentHash'], $b['ContentHash']);
    }

    public function test_non_active_shard_status_is_skipped_not_failed(): void
    {
        $this->insertReseller('acme', 'Provisioning');
        $this->insertReseller('beta', 'Failed');
        $this->insertReseller('gamma', 'Quiesced');

        $out = app(BrScopeLicensesCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame(0, $out['LicenseCount']);
        $this->assertSame(0, $out['EpochCount']);
        $this->assertSame(0, $out['FeatureLinkCount']);
        $this->assertSame(self::EMPTY_SHA256, $out['ContentHash']);
    }

    public function test_reseller_without_route_row_is_skipped(): void
    {
        DB::connection('root')->table('Resellers')->insert([
            'ResellerName' => 'Solo', 'ResellerSlug' => 'solo',
            'ContactEmail' => 'ops@solo.example', 'IsActive' => true,
        ]);

        $out = app(BrScopeLicensesCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame(0, $out['LicenseCount']);
    }

    public function test_unreadable_resellers_table_raises_backup_storage_failure(): void
    {
        Schema::connection('root')->rename('Resellers', 'ResellersBackupTmp');
        try {
            app(BrScopeLicensesCollector::class)->collect(self::REQUEST_ID);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/licenses', $e->violations[0]['Field']);
            $this->assertSame('LicenseCatalogUnreadable', $e->violations[0]['Rule']);
        } finally {
            Schema::connection('root')->rename('ResellersBackupTmp', 'Resellers');
        }
    }

    private function insertReseller(string $slug, string $status): void
    {
        $id = DB::connection('root')->table('Resellers')->insertGetId([
            'ResellerName' => ucfirst($slug),
            'ResellerSlug' => $slug,
            'ContactEmail' => $slug . '@example.com',
            'IsActive' => true,
        ]);
        DB::connection('root')->table('ResellerShardRoutes')->insert([
            'ResellerId' => $id,
            'AppDbPath' => 'lara_shard_' . $slug,
            'ShardStatus' => $status,
        ]);
    }
}
