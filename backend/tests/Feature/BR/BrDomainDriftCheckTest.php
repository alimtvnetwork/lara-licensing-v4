<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrDomainDriftCheck;
use App\Services\BR\BrScopeDomainCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 14 restore-preflight domain-drift contract tests.
 *
 * Locks: `BrDomainDriftCheck::run` compares the declared
 * `manifest.scope.domain.tables[]` slot against a fresh live
 * `BrScopeDomainCollector` invocation. Every declared-vs-live
 * divergence raises `BackupCorrupt` (422) at the exact JSON pointer
 * with the closed-set rule name; the happy path returns a report
 * with declared/live counts, aggregate hashes, and the per-table
 * pairs, without opening any DB transaction or mutating anything
 * (INV-BR-RS-1).
 */
final class BrDomainDriftCheckTest extends TestCase
{
    use RefreshDatabase;

    private const REQ = 'req-domain-drift-0001';

    /** @return array<string, mixed> live manifest.scope block from the collector */
    private function liveManifestScope(): array
    {
        Config::set('lara.br.domain_root_tables', ['Prefixes', 'Resellers']);
        DB::connection('root')->table('Resellers')->delete();
        DB::connection('root')->table('Prefixes')->delete();
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        return ['domain' => ['contentHash' => $out['ContentHash'], 'tables' => $out['Tables']]];
    }

    public function test_happy_path_returns_matched_report(): void
    {
        $scope = $this->liveManifestScope();
        $report = app(BrDomainDriftCheck::class)->run('arch-1', ['scope' => $scope], self::REQ);

        $this->assertSame(2, $report['DeclaredCount']);
        $this->assertSame(2, $report['LiveCount']);
        $this->assertSame($scope['domain']['contentHash'], $report['AggregateDeclared']);
        $this->assertSame($scope['domain']['contentHash'], $report['AggregateLive']);
        $this->assertSame(['Prefixes', 'Resellers'], array_column($report['Tables'], 'name'));
    }

    public function test_missing_tables_slot_raises_shape_rule(): void
    {
        try {
            app(BrDomainDriftCheck::class)->run('arch-1', ['scope' => ['domain' => []]], self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('BackupCorrupt', $e->errorCode);
            $this->assertSame('/scope/domain/tables', $e->violations[0]['Field']);
            $this->assertSame('DomainTablesShape', $e->violations[0]['Rule']);
        }
    }

    public function test_table_count_mismatch_raises_count_rule(): void
    {
        $scope = $this->liveManifestScope();
        $scope['domain']['tables'] = array_slice($scope['domain']['tables'], 0, 1);
        try {
            app(BrDomainDriftCheck::class)->run('arch-1', ['scope' => $scope], self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('/scope/domain/tables', $e->violations[0]['Field']);
            $this->assertSame('DomainTableCountMismatch', $e->violations[0]['Rule']);
        }
    }

    public function test_missing_declared_table_in_live_raises_missing_rule(): void
    {
        $scope = $this->liveManifestScope();
        $scope['domain']['tables'][] = ['name' => 'GhostTable', 'rowCount' => 0, 'contentHash' => hash('sha256', '')];
        // Add one more slot so declared count == live count but names diverge.
        // Add matching filler on live side by widening allowlist? Instead, we
        // want DomainTableMissingInLive specifically: keep counts equal by
        // replacing one declared name with GhostTable.
        $scope['domain']['tables'] = [
            $scope['domain']['tables'][0],
            ['name' => 'GhostTable', 'rowCount' => 0, 'contentHash' => hash('sha256', '')],
        ];
        try {
            app(BrDomainDriftCheck::class)->run('arch-1', ['scope' => $scope], self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('/scope/domain/GhostTable', $e->violations[0]['Field']);
            $this->assertSame('DomainTableMissingInLive', $e->violations[0]['Rule']);
        }
    }

    public function test_extra_live_table_not_in_manifest_raises_extra_rule(): void
    {
        $scope = $this->liveManifestScope();
        // Manifest drops "Resellers" and replaces with the same-name slot for
        // "Prefixes" duplicate? Cleaner: keep declared count == live count but
        // rename one entry so Resellers becomes an extra on the live side.
        $declared = $scope['domain']['tables'];
        $declared[1] = ['name' => 'Prefixes', 'rowCount' => $declared[0]['rowCount'], 'contentHash' => $declared[0]['contentHash']];
        $scope['domain']['tables'] = $declared;
        try {
            app(BrDomainDriftCheck::class)->run('arch-1', ['scope' => $scope], self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('/scope/domain/Resellers', $e->violations[0]['Field']);
            $this->assertSame('DomainTableExtraInLive', $e->violations[0]['Rule']);
        }
    }

    public function test_row_count_drift_raises_row_drift_rule(): void
    {
        $scope = $this->liveManifestScope();
        $scope['domain']['tables'][0]['rowCount'] = 999;
        try {
            app(BrDomainDriftCheck::class)->run('arch-1', ['scope' => $scope], self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('/scope/domain/Prefixes/rowCount', $e->violations[0]['Field']);
            $this->assertSame('DomainTableRowCountDrift', $e->violations[0]['Rule']);
        }
    }

    public function test_content_hash_drift_raises_content_drift_rule(): void
    {
        $scope = $this->liveManifestScope();
        $scope['domain']['tables'][0]['contentHash'] = str_repeat('0', 64);
        try {
            app(BrDomainDriftCheck::class)->run('arch-1', ['scope' => $scope], self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('/scope/domain/Prefixes/contentHash', $e->violations[0]['Field']);
            $this->assertSame('DomainTableContentDrift', $e->violations[0]['Rule']);
        }
    }

    public function test_entry_missing_name_or_hash_raises_shape_rule(): void
    {
        $bad = ['scope' => ['domain' => ['tables' => [['rowCount' => 0]]]]];
        try {
            app(BrDomainDriftCheck::class)->run('arch-1', $bad, self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('DomainTablesShape', $e->violations[0]['Rule']);
        }
    }
}
