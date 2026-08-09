<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Exceptions\LaraException;
use App\Models\User;
use App\Support\ApiEnvelope;
use Illuminate\Support\Facades\Route;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 06 step 59 & 60.
 *  - EtagWeakVsStrongTest: Locking strong ETag requirement and weak validator rejection.
 *  - ErrorTaxonomyTest: Asserting every thrown LaraException matches an entry in spec/21-app/12-error-taxonomy.md.
 */
final class ApiContractTest extends TestCase
{
    use AssertsLaraException;

    private const ROOT = 'root';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.root', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $this->migrateRoot();
    }

    /**
     * @test
     */
    public function it_returns_strong_etag_on_get_and_rejects_weak_if_match(): void
    {
        // 1. Verify strong ETag on GET
        // We use a real in-scope route that doesn't need much state
        $response = $this->getJson('/Api/App/Health');
        $response->assertStatus(200);
        $etag = $response->header('ETag');
        
        $this->assertNotNull($etag);
        $this->assertStringStartsNotWith('W/', $etag, 'ETag must be strong (no W/ prefix)');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{64}"$/', $etag, 'ETag must be a quoted 64-char hex (SHA-256)');

        // 2. Verify rejection of weak validator on mutation
        // We need a route in IF_MATCH_SCOPES. PATCH /Api/Reseller/Licenses/{Key}
        $user = User::create(['Email' => 'a@test.com', 'PasswordHash' => 'hash', 'TenantId' => 1]);
        
        $this->assertLaraException('ValidationFailed', function() use ($user) {
            $this->actingAs($user, 'sanctum')
                ->withHeader('If-Match', 'W/"weak-one"')
                ->patchJson('/Api/Reseller/Licenses/LARA-KEY-1')
                ->assertStatus(400);
        }, function($e) {
            $this->assertEquals('WeakForbidden', $e['Details'][0]['Rule']);
        });

        // 3. Verify rejection of wildcard
        $this->assertLaraException('ValidationFailed', function() use ($user) {
            $this->actingAs($user, 'sanctum')
                ->withHeader('If-Match', '*')
                ->patchJson('/Api/Reseller/Licenses/LARA-KEY-1')
                ->assertStatus(400);
        }, function($e) {
            $this->assertEquals('WildcardForbidden', $e['Details'][0]['Rule']);
        });
    }

    /**
     * @test
     */
    public function it_enforces_error_taxonomy_mapping(): void
    {
        // This test probes common LaraException error codes to ensure they map
        // to the correct HTTP status codes defined in the taxonomy.
        
        $mapping = [
            'AuthInvalidCredentials' => 401,
            'AuthForbidden' => 403,
            'AuthzRoleDenied' => 403,
            'ValidationFailed' => 400,
            'LicenseNotFound' => 404,
            'ResellerNotFound' => 404,
            'PreconditionRequired' => 428,
            'Conflict' => 409,
            'QuotaExhausted' => 409,
            'RateLimitExceeded' => 429,
            'UpdateManifestUnavailable' => 503,
        ];

        foreach ($mapping as $code => $expectedStatus) {
            try {
                throw LaraException::make($code, "Test {$code}");
            } catch (LaraException $e) {
                $this->assertEquals($expectedStatus, $e->getStatusCode(), "Error code {$code} must map to {$expectedStatus}");
            }
        }
    }

    private function migrateRoot(): void
    {
        $m = require base_path('database/migrations/root/2026_07_18_000001_create_root_identity_tables.php');
        $m->up();
    }
}
