<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 06 step 54. Locks GET /App/UpdateManifest contract per
 * spec/21-app/17-self-update-endpoint.md v1.3.0:
 *
 *   AC-SUM-001: Stable + finalized + non-yanked highest semver row is
 *               picked from a multi-row candidate window (v1.3.0 over
 *               v1.2.0 even when PublishedAt orders them the other way).
 *   AC-SUM-002: Yanked and un-finalized rows are invisible; a channel
 *               with no visible candidate returns UpdateManifestUnavailable
 *               (503).
 *   AC-SUM-003: Beta channel returns AuthzRoleDenied (403) in v1.0
 *               because the AppBuilder|Admin gate is reserved.
 *   AC-SUM-004: Unknown channel returns UpdateChannelUnknown (400).
 *   AC-SUM-005: CurrentVersion newer than latest returns
 *               UpdateVersionDowngradeBlocked (409).
 *   AC-SUM-006: Non-semver CurrentVersion returns ValidationInvalidVersion (400).
 *   AC-SUM-007: Stable responses carry `Cache-Control: public, max-age=30`.
 *
 * Uses a sqlite-portable AppUpdates + AppUpdateAssets schema; matches
 * the pattern established by RestoreOnceTest.
 */
final class SelfUpdateManifestTest extends TestCase
{
    private const PRODUCT = 'lara-cli';
    private const PLATFORM = 'WindowsAmd64';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootTables();
    }

    public function test_highest_semver_finalized_stable_row_wins(): void
    {
        // v1.2.0 published later than v1.3.0 to prove semver ordering
        // beats PublishedAt.
        $this->seedUpdate(id: 1, version: '1.3.0', channel: 'Stable', publishedAt: Carbon::now()->subDays(2));
        $this->seedAsset(assetId: 10, updateId: 1, version: '1.3.0', platform: self::PLATFORM, finalized: 1);
        $this->seedUpdate(id: 2, version: '1.2.0', channel: 'Stable', publishedAt: Carbon::now()->subHour());
        $this->seedAsset(assetId: 20, updateId: 2, version: '1.2.0', platform: self::PLATFORM, finalized: 1);
        // Un-finalized higher row must be invisible.
        $this->seedUpdate(id: 3, version: '1.9.0', channel: 'Stable', publishedAt: Carbon::now());
        $this->seedAsset(assetId: 30, updateId: 3, version: '1.9.0', platform: self::PLATFORM, finalized: 0);
        // Yanked higher row must be invisible.
        $this->seedUpdate(id: 4, version: '1.8.0', channel: 'Stable', publishedAt: Carbon::now(), yanked: 1);
        $this->seedAsset(assetId: 40, updateId: 4, version: '1.8.0', platform: self::PLATFORM, finalized: 1);

        $res = $this->getJson($this->url('1.0.0', 'Stable'));
        $res->assertStatus(200);
        $this->assertSame('1.3.0', $res->json('Results.0.LatestVersion'));
        $this->assertSame('public, max-age=30', $res->headers->get('Cache-Control'));
    }

    public function test_no_visible_candidate_returns_unavailable(): void
    {
        $this->seedUpdate(id: 1, version: '1.0.0', channel: 'Stable', publishedAt: Carbon::now(), yanked: 1);
        $this->seedAsset(assetId: 10, updateId: 1, version: '1.0.0', platform: self::PLATFORM, finalized: 1);
        $res = $this->getJson($this->url('0.9.0', 'Stable'));
        $res->assertStatus(503);
        $this->assertSame('UpdateManifestUnavailable', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_beta_channel_returns_role_denied_in_v1(): void
    {
        $res = $this->getJson($this->url('1.0.0', 'Beta'));
        $res->assertStatus(403);
        $this->assertSame('AuthzRoleDenied', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_unknown_channel_returns_channel_unknown(): void
    {
        $res = $this->getJson($this->url('1.0.0', 'Nightly'));
        $res->assertStatus(400);
        $this->assertSame('UpdateChannelUnknown', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_downgrade_current_version_returns_conflict(): void
    {
        $this->seedUpdate(id: 1, version: '1.3.0', channel: 'Stable', publishedAt: Carbon::now());
        $this->seedAsset(assetId: 10, updateId: 1, version: '1.3.0', platform: self::PLATFORM, finalized: 1);
        $res = $this->getJson($this->url('2.0.0', 'Stable'));
        $res->assertStatus(409);
        $this->assertSame('UpdateVersionDowngradeBlocked', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_non_semver_current_version_returns_validation_error(): void
    {
        $res = $this->getJson($this->url('not-a-version', 'Stable'));
        $res->assertStatus(400);
        $this->assertSame('ValidationInvalidVersion', $res->json('Attributes.Error.ErrorCode'));
    }

    private function url(string $currentVersion, string $channel): string
    {
        return sprintf(
            '/App/UpdateManifest?Product=%s&Channel=%s&CurrentVersion=%s&Platform=%s',
            self::PRODUCT, $channel, $currentVersion, self::PLATFORM,
        );
    }

    private function seedUpdate(int $id, string $version, string $channel, Carbon $publishedAt, int $yanked = 0): void
    {
        DB::connection('root')->table('AppUpdates')->insert([
            'AppUpdateId' => $id,
            'Product' => self::PRODUCT,
            'Channel' => $channel,
            'Version' => $version,
            'MinRequiredVersion' => '0.0.0',
            'ReleaseNotesUrl' => null,
            'PublishedAt' => $publishedAt->toDateTimeString(),
            'PublishedByUserId' => 1,
            'IsYanked' => $yanked,
            'YankedAt' => $yanked ? $publishedAt->toDateTimeString() : null,
            'YankedByUserId' => $yanked ? 1 : null,
        ]);
    }

    private function seedAsset(int $assetId, int $updateId, string $version, string $platform, int $finalized): void
    {
        DB::connection('root')->table('AppUpdateAssets')->insert([
            'AppUpdateAssetId' => $assetId,
            'AppUpdateId' => $updateId,
            'Product' => self::PRODUCT,
            'Version' => $version,
            'Platform' => $platform,
            'SizeBytes' => 1024,
            'Sha256' => str_repeat('0', 64),
            'SignatureSha256' => null,
            'StoragePath' => $finalized ? '/tmp/fake.bin' : null,
            'SignatureStoragePath' => null,
            'IsFinalized' => $finalized,
            'UploadToken' => str_repeat(dechex($assetId % 16), 64),
            'UploadTicketExpiresAt' => Carbon::now()->addHour()->toDateTimeString(),
            'CreatedAt' => Carbon::now()->toDateTimeString(),
            'FinalizedAt' => $finalized ? Carbon::now()->toDateTimeString() : null,
        ]);
    }

    private function createRootTables(): void
    {
        DB::connection('root')->statement(
            'CREATE TABLE IF NOT EXISTS "AppUpdates" (
                "AppUpdateId" INTEGER PRIMARY KEY,
                "Product" TEXT NOT NULL,
                "Channel" TEXT NOT NULL,
                "Version" TEXT NOT NULL,
                "MinRequiredVersion" TEXT NOT NULL,
                "ReleaseNotesUrl" TEXT NULL,
                "PublishedAt" TEXT NULL,
                "PublishedByUserId" INTEGER NULL,
                "IsYanked" INTEGER NOT NULL DEFAULT 0,
                "YankedAt" TEXT NULL,
                "YankedByUserId" INTEGER NULL
            )'
        );
        DB::connection('root')->statement(
            'CREATE TABLE IF NOT EXISTS "AppUpdateAssets" (
                "AppUpdateAssetId" INTEGER PRIMARY KEY,
                "AppUpdateId" INTEGER NOT NULL,
                "Product" TEXT NOT NULL,
                "Version" TEXT NOT NULL,
                "Platform" TEXT NOT NULL,
                "SizeBytes" INTEGER NOT NULL,
                "Sha256" TEXT NOT NULL,
                "SignatureSha256" TEXT NULL,
                "StoragePath" TEXT NULL,
                "SignatureStoragePath" TEXT NULL,
                "IsFinalized" INTEGER NOT NULL DEFAULT 0,
                "UploadToken" TEXT NOT NULL,
                "UploadTicketExpiresAt" TEXT NULL,
                "CreatedAt" TEXT NOT NULL,
                "FinalizedAt" TEXT NULL
            )'
        );
    }
}
