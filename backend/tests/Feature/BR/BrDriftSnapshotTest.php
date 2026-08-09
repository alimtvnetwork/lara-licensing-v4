<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Services\BR\BrDriftSnapshot;
use App\Services\BR\BrScopeClosedSetsCollector;
use App\Services\BR\BrScopeDomainCollector;
use App\Services\BR\BrScopeFeaturesCollector;
use App\Services\BR\BrScopeFilesCollector;
use App\Services\BR\BrScopeLicensesCollector;
use App\Services\BR\BrScopeRbacCollector;
use App\Services\BR\BrScopeSchemaCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Plan 14 step 24 contract tests for BrDriftSnapshot.
 *
 * Locks:
 *  - When every live collector matches the preflight-declared hash,
 *    `AllMatch` is true and every non-skipped slot reports `Match=true`.
 *  - When ONE live collector drifts, `AllMatch` is false and only
 *    that slot reports `Match=false, Skipped=false`.
 *  - The `secretsEnvelope` slot is always `Skipped=true` with reason
 *    `SealedByEpoch` because live recomputation of AEAD-sealed rows
 *    is not meaningful without the archive's own KEK material.
 */
final class BrDriftSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const REQ = 'req-drift-0001';
    private const ARCHIVE = '33333333-3333-4333-8333-333333333333';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_drift_snapshot_reports_all_match_when_live_equals_declared(): void
    {
        $this->bindCollectors([
            'schema'     => 'aa',
            'closedSets' => 'bb',
            'features'   => 'cc',
            'licenses'   => 'dd',
            'rbac'       => 'ee',
            'domain'     => 'ff',
            'files'      => 'gg',
        ]);
        $report = app(BrDriftSnapshot::class)->run($this->preflight('aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg'), self::REQ);
        $this->assertTrue($report['AllMatch']);
        $this->assertTrue($report['Scopes']['schema']['Match']);
        $this->assertTrue($report['Scopes']['secretsEnvelope']['Skipped']);
        $this->assertSame('SealedByEpoch', $report['Scopes']['secretsEnvelope']['Reason']);
    }

    public function test_drift_snapshot_flags_only_drifted_slot(): void
    {
        $this->bindCollectors([
            'schema'     => 'aa',
            'closedSets' => 'bb',
            'features'   => 'cc',
            'licenses'   => 'ZZ', // drift
            'rbac'       => 'ee',
            'domain'     => 'ff',
            'files'      => 'gg',
        ]);
        $report = app(BrDriftSnapshot::class)->run($this->preflight('aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg'), self::REQ);
        $this->assertFalse($report['AllMatch']);
        $this->assertTrue($report['Scopes']['schema']['Match']);
        $this->assertFalse($report['Scopes']['licenses']['Match']);
        $this->assertSame('dd', $report['Scopes']['licenses']['Declared']);
        $this->assertSame('ZZ', $report['Scopes']['licenses']['Live']);
    }

    /**
     * @param  array<string, string>  $hashes
     */
    private function bindCollectors(array $hashes): void
    {
        $map = [
            'schema'     => BrScopeSchemaCollector::class,
            'closedSets' => BrScopeClosedSetsCollector::class,
            'features'   => BrScopeFeaturesCollector::class,
            'licenses'   => BrScopeLicensesCollector::class,
            'rbac'       => BrScopeRbacCollector::class,
            'domain'     => BrScopeDomainCollector::class,
            'files'      => BrScopeFilesCollector::class,
        ];
        foreach ($map as $key => $class) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('collect')->andReturn(['ContentHash' => $hashes[$key]]);
            $this->app->instance($class, $mock);
        }
    }

    /**
     * @return array{ArchiveId:string, Scopes: array<string, array{ContentHash:string, ActualHash:string, Ok:bool, PlainBytes:int}>}
     */
    private function preflight(string $sc, string $cs, string $ft, string $lc, string $rb, string $dm, string $fl): array
    {
        $slot = fn (string $h) => ['ContentHash' => $h, 'ActualHash' => $h, 'Ok' => true, 'PlainBytes' => 0];

        return [
            'ArchiveId' => self::ARCHIVE,
            'Scopes' => [
                'schema'          => $slot($sc),
                'closedSets'      => $slot($cs),
                'features'        => $slot($ft),
                'licenses'        => $slot($lc),
                'rbac'            => $slot($rb),
                'secretsEnvelope' => $slot('sealed-hash'),
                'files'           => $slot($fl),
                'domain'          => $slot($dm),
            ],
        ];
    }
}
