<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 06 step 57. Feature test SelfUpdateManifestTest locking 
 * Stable-only response, signature present, SHA-256 present.
 *
 * Locked behaviors (spec 17 v1.3.0):
 *  - GET /App/UpdateManifest returns 200 with enveloped manifest for valid query.
 *  - Stable channel returns public cache-control.
 *  - Only finalized assets (IsFinalized=1) are returned.
 *  - Yanked updates (IsYanked=1) are hidden.
 *  - Beta channel is forbidden for anonymous callers (AuthzRoleDenied 403).
 *  - Highest semver is picked among candidates.
 *  - SignatureUrl is present if SignatureSha256 is not null.
 */
final class SelfUpdateManifestTest extends TestCase
{
    use AssertsLaraException;

    private const ROOT = 'root';
    private User $admin;

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
        $this->seedAdmin();
        
        // Setup lara config for self-update
        config()->set('lara.self_update.products', ['lara-cli']);
        config()->set('lara.self_update.channels', ['Stable', 'Beta']);
        config()->set('lara.self_update.platforms', ['WindowsAmd64', 'LinuxAmd64']);
    }

    /**
     * @test
     */
    public function it_returns_latest_finalized_manifest_for_stable_channel(): void
    {
        // 1. Seed two updates: 1.0.0 and 1.1.0
        $this->createUpdate('1.0.0', 'Stable');
        $this->createUpdate('1.1.0', 'Stable');
        
        // 2. Request manifest for 0.9.0
        $response = $this->getJson('/Api/App/UpdateManifest?Product=lara-cli&Channel=Stable&CurrentVersion=0.9.0&Platform=WindowsAmd64');

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control', 'public, max-age=30');
        
        $data = $response->json('Results.0');
        $this->assertEquals('1.1.0', $data['LatestVersion']);
        $this->assertEquals('WindowsAmd64', $data['Assets'][0]['Platform']);
        $this->assertStringContainsString('/App/UpdateAsset/1.1.0/WindowsAmd64', $data['Assets'][0]['Url']);
        $this->assertNotNull($data['Assets'][0]['Sha256']);
        $this->assertNotNull($data['Assets'][0]['SignatureUrl']);
    }

    /**
     * @test
     */
    public function it_filters_out_yanked_updates(): void
    {
        $this->createUpdate('1.1.0', 'Stable');
        $update2 = $this->createUpdate('1.2.0', 'Stable');
        
        // Yank 1.2.0
        DB::connection(self::ROOT)->table('AppUpdates')->where('AppUpdateId', $update2)->update([
            'IsYanked' => 1,
            'YankedAt' => now(),
            'YankedByUserId' => $this->admin->UserId,
        ]);

        $response = $this->getJson('/Api/App/UpdateManifest?Product=lara-cli&Channel=Stable&CurrentVersion=1.0.0&Platform=WindowsAmd64');
        
        $response->assertStatus(200);
        $this->assertEquals('1.1.0', $response->json('Results.0.LatestVersion'));
    }

    /**
     * @test
     */
    public function it_filters_out_non_finalized_assets(): void
    {
        $updateId = $this->createUpdate('1.1.0', 'Stable', false); // Create update but don't finalize asset
        
        $response = $this->getJson('/Api/App/UpdateManifest?Product=lara-cli&Channel=Stable&CurrentVersion=1.0.0&Platform=WindowsAmd64');
        
        // Should be 503/404 equivalent depending on implementation (LaraException maps to 503 UpdateManifestUnavailable)
        $this->assertLaraException('UpdateManifestUnavailable', function() {
            $this->getJson('/Api/App/UpdateManifest?Product=lara-cli&Channel=Stable&CurrentVersion=1.0.0&Platform=WindowsAmd64')
                 ->assertStatus(503);
        });
    }

    /**
     * @test
     */
    public function it_forbids_beta_channel_for_anonymous_callers(): void
    {
        $this->assertLaraException('AuthzRoleDenied', function() {
            $this->getJson('/Api/App/UpdateManifest?Product=lara-cli&Channel=Beta&CurrentVersion=1.0.0&Platform=WindowsAmd64')
                 ->assertStatus(403);
        });
    }

    private function migrateRoot(): void
    {
        $files = [
            '2026_07_18_000001_create_root_identity_tables.php',
            '2026_07_18_000002_create_root_reseller_tables.php',
            '2026_07_18_000010_create_root_app_updates_tables.php',
        ];

        foreach ($files as $file) {
            $m = require base_path('database/migrations/root/' . $file);
            $m->up();
        }
    }

    private function seedAdmin(): void
    {
        $this->admin = User::create([
            'Email' => 'admin@lara.test',
            'PasswordHash' => 'hash',
            'IsActive' => true,
        ]);
    }

    private function createUpdate(string $version, string $channel, bool $finalized = true): int
    {
        $updateId = DB::connection(self::ROOT)->table('AppUpdates')->insertGetId([
            'Product' => 'lara-cli',
            'Channel' => $channel,
            'Version' => $version,
            'MinRequiredVersion' => '1.0.0',
            'PublishedAt' => now(),
            'PublishedByUserId' => $this->admin->UserId,
            'IsYanked' => 0,
        ]);

        DB::connection(self::ROOT)->table('AppUpdateAssets')->insert([
            'AppUpdateId' => $updateId,
            'Platform' => 'WindowsAmd64',
            'SizeBytes' => 1024,
            'Sha256' => hash('sha256', $version),
            'SignatureSha256' => hash('sha256', 'sig-' . $version),
            'IsFinalized' => $finalized ? 1 : 0,
            'StoragePath' => "updates/{$version}/win64.zip",
            'CreatedAt' => now(),
            'UpdatedAt' => now(),
        ]);

        return (int) $updateId;
    }
}
