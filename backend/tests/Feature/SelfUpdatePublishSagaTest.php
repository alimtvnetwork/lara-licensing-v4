<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 06 step 55. Locks PUT /App/UpdateAssetReceiver/{UploadToken}
 * publish-saga phase-2 invariants per
 * spec/21-app/17-self-update-endpoint.md v1.3.0 §"Publish state machine":
 *
 *   AC-SUP-001: Happy path. Correct token, X-Sha256 header equal to
 *               row Sha256, body of exactly SizeBytes bytes that hash
 *               to Sha256, returns 200 and persists StoragePath.
 *   AC-SUP-002: Unknown token returns UpdateAssetUploadFailed (502).
 *   AC-SUP-003: Expired ticket returns UpdateAssetUploadFailed (502)
 *               with Rule=TicketExpired, and does not overwrite
 *               StoragePath.
 *   AC-SUP-004: X-Sha256 header mismatch returns
 *               UpdateAssetVerificationFailed (409).
 *   AC-SUP-005: Body byte-length mismatch returns
 *               UpdateAssetUploadFailed (502) with Rule=SizeMismatch.
 *   AC-SUP-006: Already-finalized token is refused with
 *               UpdateAssetUploadFailed (502) so a double-publish
 *               cannot silently overwrite a live asset.
 *
 * Uses a sqlite-portable AppUpdateAssets table and a tmp storage root.
 */
final class SelfUpdatePublishSagaTest extends TestCase
{
    private const PRODUCT = 'lara-cli';
    private const VERSION = '1.4.0';
    private const PLATFORM = 'WindowsAmd64';
    private const TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TOKEN_EXPIRED = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TOKEN_FINAL = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const BODY = 'lara-binary-payload-bytes';

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir() . '/lara-selfupdate-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0775, true);
        config()->set('lara.self_update.asset_storage_root', $this->storageRoot);
        $this->createAssetsTable();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->storageRoot);
        parent::tearDown();
    }

    public function test_happy_path_persists_bytes_and_sets_storage_path(): void
    {
        $sha = hash('sha256', self::BODY);
        $this->seedAsset(1, self::TOKEN_A, $sha, strlen(self::BODY), Carbon::now()->addHour(), 0);
        $res = $this->call(
            'PUT',
            '/App/UpdateAssetReceiver/' . self::TOKEN_A,
            [], [], [],
            ['HTTP_X-Sha256' => $sha, 'CONTENT_TYPE' => 'application/octet-stream'],
            self::BODY,
        );
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame($sha, json_decode($res->getContent(), true)['Results'][0]['Sha256']);
        $row = DB::connection('root')->table('AppUpdateAssets')->where('AppUpdateAssetId', 1)->first();
        $this->assertNotNull($row->StoragePath);
        $this->assertFileExists($row->StoragePath);
        $this->assertSame(self::BODY, file_get_contents($row->StoragePath));
    }

    public function test_unknown_token_returns_upload_failed(): void
    {
        $unknown = str_repeat('d', 64);
        $res = $this->call(
            'PUT',
            '/App/UpdateAssetReceiver/' . $unknown,
            [], [], [],
            ['HTTP_X-Sha256' => str_repeat('0', 64), 'CONTENT_TYPE' => 'application/octet-stream'],
            'x',
        );
        $this->assertSame(502, $res->getStatusCode());
        $this->assertSame('UpdateAssetUploadFailed', json_decode($res->getContent(), true)['Attributes']['Error']['ErrorCode']);
    }

    public function test_expired_ticket_returns_upload_failed_ticket_expired(): void
    {
        $sha = hash('sha256', self::BODY);
        $this->seedAsset(2, self::TOKEN_EXPIRED, $sha, strlen(self::BODY), Carbon::now()->subMinute(), 0);
        $res = $this->call(
            'PUT',
            '/App/UpdateAssetReceiver/' . self::TOKEN_EXPIRED,
            [], [], [],
            ['HTTP_X-Sha256' => $sha, 'CONTENT_TYPE' => 'application/octet-stream'],
            self::BODY,
        );
        $this->assertSame(502, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('UpdateAssetUploadFailed', $body['Attributes']['Error']['ErrorCode']);
        $this->assertSame('TicketExpired', $body['Attributes']['Error']['Details'][0]['Rule'] ?? null);
        $storagePath = DB::connection('root')->table('AppUpdateAssets')->where('AppUpdateAssetId', 2)->value('StoragePath');
        $this->assertNull($storagePath, 'Expired ticket must not write StoragePath.');
    }

    public function test_header_sha_mismatch_returns_verification_failed(): void
    {
        $sha = hash('sha256', self::BODY);
        $this->seedAsset(3, self::TOKEN_A, $sha, strlen(self::BODY), Carbon::now()->addHour(), 0);
        $wrong = str_repeat('9', 64);
        $res = $this->call(
            'PUT',
            '/App/UpdateAssetReceiver/' . self::TOKEN_A,
            [], [], [],
            ['HTTP_X-Sha256' => $wrong, 'CONTENT_TYPE' => 'application/octet-stream'],
            self::BODY,
        );
        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame('UpdateAssetVerificationFailed', json_decode($res->getContent(), true)['Attributes']['Error']['ErrorCode']);
    }

    public function test_body_size_mismatch_returns_size_mismatch(): void
    {
        $sha = hash('sha256', self::BODY);
        // Declare a size 2 bytes larger than actual body.
        $this->seedAsset(4, self::TOKEN_A, $sha, strlen(self::BODY) + 2, Carbon::now()->addHour(), 0);
        $res = $this->call(
            'PUT',
            '/App/UpdateAssetReceiver/' . self::TOKEN_A,
            [], [], [],
            ['HTTP_X-Sha256' => $sha, 'CONTENT_TYPE' => 'application/octet-stream'],
            self::BODY,
        );
        $this->assertSame(502, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('UpdateAssetUploadFailed', $body['Attributes']['Error']['ErrorCode']);
        $this->assertSame('SizeMismatch', $body['Attributes']['Error']['Details'][0]['Rule'] ?? null);
    }

    public function test_already_finalized_token_is_refused(): void
    {
        $sha = hash('sha256', self::BODY);
        $this->seedAsset(5, self::TOKEN_FINAL, $sha, strlen(self::BODY), Carbon::now()->addHour(), 1);
        $res = $this->call(
            'PUT',
            '/App/UpdateAssetReceiver/' . self::TOKEN_FINAL,
            [], [], [],
            ['HTTP_X-Sha256' => $sha, 'CONTENT_TYPE' => 'application/octet-stream'],
            self::BODY,
        );
        $this->assertSame(502, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('UpdateAssetUploadFailed', $body['Attributes']['Error']['ErrorCode']);
        $this->assertSame('AlreadyFinalized', $body['Attributes']['Error']['Details'][0]['Rule'] ?? null);
    }

    private function seedAsset(int $id, string $token, string $sha, int $size, Carbon $expires, int $finalized): void
    {
        DB::connection('root')->table('AppUpdateAssets')->insert([
            'AppUpdateAssetId' => $id,
            'AppUpdateId' => $id,
            'Product' => self::PRODUCT,
            'Version' => self::VERSION,
            'Platform' => self::PLATFORM,
            'SizeBytes' => $size,
            'Sha256' => $sha,
            'SignatureSha256' => null,
            'StoragePath' => null,
            'SignatureStoragePath' => null,
            'IsFinalized' => $finalized,
            'UploadToken' => $token,
            'UploadTicketExpiresAt' => $expires->toDateTimeString(),
            'CreatedAt' => Carbon::now()->toDateTimeString(),
            'FinalizedAt' => $finalized ? Carbon::now()->toDateTimeString() : null,
        ]);
    }

    private function createAssetsTable(): void
    {
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

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
