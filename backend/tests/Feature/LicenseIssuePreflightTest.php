<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Requests\Admin\LicenseIssueRequest;
use App\Services\FeatureService;
use App\Services\LicenseLedgerService;
use App\Services\QuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plan 10 step 13. Locks that `POST /Api/Admin/Licenses` fails cleanly
 * with `FeatureCatalogUnseeded` when the Root Features catalog is
 * empty, BEFORE any tenant lookup or shard write can occur.
 *
 *   AC-LIC-PREFLIGHT-001: A fresh environment without seeded features
 *     surfaces `FeatureCatalogUnseeded` (not `ResellerNotFound` /
 *     `PrefixForbidden`), giving operators the true root cause.
 *   AC-LIC-PREFLIGHT-002: The preflight failure is logged with the
 *     missing registry keys so ops can see the drift in logs.
 */
final class LicenseIssuePreflightTest extends TestCase
{
    private const ERROR_CODE = 'FeatureCatalogUnseeded';

    protected function setUp(): void
    {
        parent::setUp();
        // Root `Features` table exists but has zero rows: this is the
        // fresh-DB scenario the preflight guards against.
        DB::connection('root')->statement('DROP TABLE IF EXISTS "Features"');
        DB::connection('root')->statement(
            'CREATE TABLE "Features" (
                "FeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
                "FeatureKey" TEXT NOT NULL UNIQUE,
                "ValueType" TEXT NOT NULL
            )'
        );
        config()->set('lara.feature_registry', [
            'Modules.Reports' => ['ValueType' => 'Boolean'],
            'Limits.MaxUsers' => ['ValueType' => 'Number', 'IntegerOnly' => true],
        ]);
    }

    #[Test]
    public function license_issue_short_circuits_when_catalog_is_empty(): void
    {
        Log::spy();

        $controller = new LicenseController(
            shardResolver: $this->app->make(ShardResolver::class),
            quotaService: $this->app->make(QuotaService::class),
            ledgerService: $this->app->make(LicenseLedgerService::class),
            featureService: $this->app->make(FeatureService::class),
        );

        $request = new class extends LicenseIssueRequest {
            public function payload(): array
            {
                // Note: preflight MUST throw before this is ever read.
                // If ordering regresses, the test still fails cleanly
                // because Reseller lookup with slug 'nonexistent'
                // would raise `ResellerNotFound`, which we assert
                // against below.
                return [
                    'ResellerSlug' => 'nonexistent',
                    'PrefixValue' => 'AAA',
                    'TierName' => 'Standard',
                    'EnvironmentName' => 'Production',
                ];
            }
        };

        try {
            $controller->issue($request);
            $this->fail('Expected LaraException(FeatureCatalogUnseeded).');
        } catch (LaraException $e) {
            $this->assertSame(
                self::ERROR_CODE,
                $e->errorCode,
                'Preflight must run before tenant lookup so operators see the true root cause.',
            );
            $fields = array_column($e->details, 'Field');
            $this->assertContains('Features.Modules.Reports', $fields);
            $this->assertContains('Features.Limits.MaxUsers', $fields);
        }
    }
}
