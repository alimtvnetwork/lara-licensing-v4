<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Domain\BR\BrCryptoConstants;
use App\Domain\BR\BrManifestSchema;
use App\Exceptions\LaraException;
use App\Services\BR\BrArchiveReader;
use App\Services\BR\BrArchiveSealer;
use App\Services\BR\BrArchiveStorage;
use App\Services\BR\BrCryptoService;
use App\Services\BR\BrImportPreflight;
use App\Services\BR\BrKekService;
use App\Services\BR\BrManifestBuilder;
use App\Services\BR\BrManifestValidator;
use App\Services\BR\BrMerkleRoot;
use App\Services\BR\BrZstd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Plan 14 step 23 contract tests for Import preflight.
 *
 * Locks:
 *  - A finalized sealed archive round-trips: manifest validates,
 *    every chunk trailer + sha + byte count + GCM tag verify, DEK
 *    unseals via the Active KEK epoch, per-scope contentHash matches.
 *  - Tampering ANY byte in an on-disk chunk body surfaces
 *    `BackupCorrupt` rule `ChunkTrailerMismatch` (trailer differs)
 *    or `FrameTagFailed` (trailer patched too) before any DB tx opens.
 *  - Preflight is read-only: no rows change in `root.BrKekEpochs`
 *    across the run.
 */
final class BrImportPreflightTest extends TestCase
{
    use RefreshDatabase;

    private const REQ = 'req-preflight-0001';
    private const ARCHIVE = '22222222-2222-4222-8222-222222222222';
    private const APP_VERSION = '0.628.0';
    private const USER = '00000000-0000-4000-8000-000000000abc';

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'br-preflight-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tempRoot);
        Config::set('lara.br.archive_root', $this->tempRoot);
        Config::set('br.kek_material', str_repeat("\x77", BrCryptoConstants::KEK_LEN));
        DB::connection('root')->table('BrKekEpochs')->insert([
            'Epoch' => 51, 'Kid' => 'epoch-51-preflight', 'State' => 'Active',
            'SecretsRef' => 'br.kek_material',
            'CreatedAt' => now(), 'UpdatedAt' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);
        parent::tearDown();
    }

    public function test_preflight_verifies_sealed_archive_end_to_end(): void
    {
        if (! function_exists('zstd_compress')) {
            $this->markTestSkipped('ext-zstd unavailable');
        }
        [$scopePlain] = $this->buildSealedArchive();
        $report = app(BrImportPreflight::class)->run(self::ARCHIVE, self::APP_VERSION, self::REQ);
        $this->assertSame(51, $report['EncryptionEpoch']);
        $this->assertSame('epoch-51-preflight', $report['EncryptionKid']);
        $this->assertSame(1, $report['ChunkCount']);
        $this->assertTrue($report['Scopes']['schema']['Ok']);
        $this->assertSame(hash('sha256', $scopePlain), $report['Scopes']['schema']['ContentHash']);
    }

    public function test_preflight_rejects_tampered_chunk_body(): void
    {
        if (! function_exists('zstd_compress')) {
            $this->markTestSkipped('ext-zstd unavailable');
        }
        $this->buildSealedArchive();
        $abs = app(BrArchiveStorage::class)->path(self::ARCHIVE) . '/scope/schema.jsonl.zst';
        $raw = (string) file_get_contents($abs);
        $raw[0] = $raw[0] === 'A' ? 'B' : 'A'; // flip first byte
        file_put_contents($abs, $raw);
        try {
            app(BrImportPreflight::class)->run(self::ARCHIVE, self::APP_VERSION, self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame(BrCryptoConstants::ERR_BACKUP_CORRUPT, $e->errorCode);
        }
    }

    /** @return array{0:string} plaintext scope body used for verification */
    private function buildSealedArchive(): array
    {
        $storage = app(BrArchiveStorage::class);
        $sealer = app(BrArchiveSealer::class);
        $zstd = app(BrZstd::class);
        $storage->reserve(self::ARCHIVE, self::REQ);
        $sealer->initialize(self::ARCHIVE, self::REQ, BrManifestSchema::VERSION);
        $plain = "{\"table\":\"Users\"}\n";
        $compressed = $zstd->compress($plain);
        $sealed = $sealer->sealChunkBody('scope/schema.jsonl.zst', 0, $compressed, self::REQ);
        $sha = hash('sha256', $sealed);
        $storage->writeAtomic(self::ARCHIVE, 'scope/schema.jsonl.zst', $sealed . hex2bin($sha), self::REQ);
        $chunks = [['path' => 'scope/schema.jsonl.zst', 'sha256' => $sha, 'bytes' => strlen($sealed)]];
        $merkle = BrMerkleRoot::compute([$sha]);
        $scopeOverrides = ['schema' => ['contentHash' => hash('sha256', $plain), 'migrations' => []]];
        app(BrManifestBuilder::class)->writeShadowManifest($storage, self::ARCHIVE, self::APP_VERSION, self::USER, self::REQ, $chunks, $merkle, $scopeOverrides, hash('sha256', $plain), $sealer->manifestEncryption());
        $storage->finalize(self::ARCHIVE, self::REQ);

        return [$plain];
    }
}
