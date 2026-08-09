<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\LaraException;
use App\Services\FeatureService;
use Database\Seeders\FeatureCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks Plan 06 step 41 followup:
 *
 *   AC-FEAT-SEED-001: FeatureCatalogSeeder mirrors
 *                     config('lara.feature_registry') into Root
 *                     `Features` idempotently (re-runs do not duplicate
 *                     rows and do not fail on existing keys).
 *   AC-FEAT-SEED-002: FeatureService::assertCatalogSeeded() succeeds
 *                     when every registry key has a Features row.
 *   AC-FEAT-SEED-003: assertCatalogSeeded() throws
 *                     LaraException(FeatureCatalogUnseeded) listing the
 *                     missing keys when the catalog is short of the
 *                     registry (drift / fresh DB without seed).
 */
final class FeatureCatalogSeederTest extends TestCase
{
    private const ERROR_CODE = 'FeatureCatalogUnseeded';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeaturesTable();
        config()->set('lara.feature_registry', [
            'Modules.Reports'    => ['ValueType' => 'Boolean'],
            'Limits.MaxUsers'    => ['ValueType' => 'Number', 'IntegerOnly' => true],
            'Support.Tier'       => ['ValueType' => 'String', 'AllowedValues' => ['Community']],
        ]);
    }

    public function test_seeder_is_idempotent_and_matches_registry(): void
    {
        $seeder = $this->app->make(FeatureCatalogSeeder::class);
        $seeder->run();
        $seeder->run();
        $rows = DB::connection('root')->table('Features')->orderBy('FeatureKey')->pluck('FeatureKey')->all();
        $this->assertSame(['Limits.MaxUsers', 'Modules.Reports', 'Support.Tier'], $rows);
    }

    public function test_assert_catalog_seeded_passes_after_seed(): void
    {
        $this->app->make(FeatureCatalogSeeder::class)->run();
        $service = $this->app->make(FeatureService::class);
        $service->assertCatalogSeeded();
        $this->assertTrue(true, 'assertCatalogSeeded returned without throwing.');
    }

    public function test_assert_catalog_seeded_throws_when_catalog_is_empty(): void
    {
        $service = $this->app->make(FeatureService::class);
        try {
            $service->assertCatalogSeeded();
            $this->fail('Expected LaraException(FeatureCatalogUnseeded).');
        } catch (LaraException $e) {
            $this->assertSame(self::ERROR_CODE, $e->errorCode);
            $fields = array_column($e->details, 'Field');
            $this->assertContains('Features.Modules.Reports', $fields);
            $this->assertContains('Features.Limits.MaxUsers', $fields);
        }
    }

    public function test_assert_catalog_seeded_reports_only_missing_keys(): void
    {
        DB::connection('root')->table('Features')->insert([
            'FeatureKey' => 'Modules.Reports',
            'ValueType' => 'Boolean',
        ]);
        $service = $this->app->make(FeatureService::class);
        try {
            $service->assertCatalogSeeded();
            $this->fail('Expected LaraException.');
        } catch (LaraException $e) {
            $fields = array_column($e->details, 'Field');
            $this->assertNotContains('Features.Modules.Reports', $fields);
            $this->assertContains('Features.Limits.MaxUsers', $fields);
            $this->assertContains('Features.Support.Tier', $fields);
        }
    }

    private function createFeaturesTable(): void
    {
        DB::connection('root')->statement('DROP TABLE IF EXISTS "Features"');
        DB::connection('root')->statement(
            'CREATE TABLE "Features" (
                "FeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
                "FeatureKey" TEXT NOT NULL UNIQUE,
                "ValueType" TEXT NOT NULL
            )'
        );
    }
}
