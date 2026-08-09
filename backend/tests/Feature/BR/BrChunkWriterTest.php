<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Domain\BR\BrManifestSchema;
use App\Services\BR\BrArchiveStorage;
use App\Services\BR\BrChunkWriter;
use App\Services\BR\BrManifestBuilder;
use App\Services\BR\BrManifestValidator;
use App\Services\BR\BrMerkleRoot;
use App\Services\BR\BrZstd;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Plan 14 step 13 contract tests for the shadow chunk writer and the
 * Merkle-bound manifest.
 *
 * Locks:
 *  - Seven S1 shadow chunks are emitted in canonical tar-entry order
 *    (spec §08 "Entry Order"; INV-BR-AF-1).
 *  - Each on-disk chunk file is `compressed || 32-byte SHA-256 trailer`
 *    with the trailer equal to `chunks[].sha256` (INV-BR-AF-3).
 *  - The Merkle root computed from the descriptor list validates
 *    against `manifest.contentHash` (INV-BR-AF-4, INV-BR-MS-3) and
 *    round-trips `BrManifestValidator::validate`.
 *  - `manifest.chunkIndex.level`/`chunkSize` equal the domain-layer
 *    pinned constants (no drift).
 *
 * Skips: hosts without PHP ext-zstd are not exercised here (the writer
 * throws `BackupZstdUnavailable` by contract; CI installs the extension).
 */
final class BrChunkWriterTest extends TestCase
{
    private const ARCHIVE_ID = '018fa7c3-cccc-7c4d-8e5f-6a7b8c9d0e1f';
    private const APP_VERSION = '0.620.0';
    private const USER_ID = '018fa7c3-cccc-7c4d-8e5f-6a7b8c9d0e20';
    private const REQUEST_ID = 'req-chunkwriter-0001';
    private const TRAILER_BYTES = 32;

    /** @var list<string> */
    private const EXPECTED_ORDER = [
        'scope/schema.jsonl.zst',
        'scope/closed-sets.jsonl.zst',
        'scope/features.jsonl.zst',
        'scope/licenses.jsonl.zst',
        'scope/rbac.jsonl.zst',
        'scope/secrets-envelope.bin.zst',
        'scope/files/index.jsonl.zst',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! app(BrZstd::class)->isAvailable()) {
            $this->markTestSkipped('PHP ext-zstd not loaded; chunk writer requires it (spec §08 INV-BR-AF-2).');
        }
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'br-chunk-writer-' . bin2hex(random_bytes(4));
        Config::set('lara.br.archive_root', $root);
    }

    public function test_writes_seven_chunks_in_canonical_order(): void
    {
        $storage = app(BrArchiveStorage::class);
        $writer = app(BrChunkWriter::class);
        $storage->reserve(self::ARCHIVE_ID, self::REQUEST_ID);
        $descriptors = $writer->writeShadowChunks($storage, self::ARCHIVE_ID, self::REQUEST_ID);
        $this->assertCount(count(self::EXPECTED_ORDER), $descriptors);
        foreach (self::EXPECTED_ORDER as $i => $path) {
            $this->assertSame($path, $descriptors[$i]['path'], "chunk[$i] path");
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $descriptors[$i]['sha256']);
            $this->assertGreaterThan(0, $descriptors[$i]['bytes'], "chunk[$i] bytes");
        }
    }

    public function test_each_chunk_has_trailer_matching_declared_sha256(): void
    {
        $storage = app(BrArchiveStorage::class);
        $writer = app(BrChunkWriter::class);
        $base = $storage->reserve(self::ARCHIVE_ID, self::REQUEST_ID);
        $descriptors = $writer->writeShadowChunks($storage, self::ARCHIVE_ID, self::REQUEST_ID);
        foreach ($descriptors as $d) {
            $blob = (string) file_get_contents($base . DIRECTORY_SEPARATOR . $d['path']);
            $this->assertSame($d['bytes'] + self::TRAILER_BYTES, strlen($blob), $d['path'] . ' entry size');
            $body = substr($blob, 0, $d['bytes']);
            $trailerHex = bin2hex(substr($blob, -self::TRAILER_BYTES));
            $this->assertSame($d['sha256'], hash('sha256', $body), $d['path'] . ' sha256 over body');
            $this->assertSame($d['sha256'], $trailerHex, $d['path'] . ' trailer equals sha256');
        }
    }

    public function test_manifest_binds_real_merkle_root_and_validates(): void
    {
        $storage = app(BrArchiveStorage::class);
        $writer = app(BrChunkWriter::class);
        $builder = app(BrManifestBuilder::class);
        $storage->reserve(self::ARCHIVE_ID, self::REQUEST_ID);
        $descriptors = $writer->writeShadowChunks($storage, self::ARCHIVE_ID, self::REQUEST_ID);
        $root = BrMerkleRoot::compute(array_map(static fn ($d) => $d['sha256'], $descriptors));
        $manifest = $builder->buildShadowWithChunks(self::ARCHIVE_ID, self::APP_VERSION, self::USER_ID, self::REQUEST_ID, $descriptors, $root);
        $this->assertSame($root, $manifest['contentHash']);
        $this->assertSame($root, $manifest['chunkIndex']['merkleRoot']);
        $this->assertSame(BrManifestSchema::CHUNK_LEVEL, $manifest['chunkIndex']['level']);
        $this->assertSame(BrManifestSchema::CHUNK_SIZE, $manifest['chunkIndex']['chunkSize']);
        app(BrManifestValidator::class)->validate($manifest, self::APP_VERSION, self::REQUEST_ID);
    }

    public function test_persist_failure_surfaces_structured_log_and_wrapped_lara_exception(): void
    {
        Log::spy();
        $storage = $this->createMock(BrArchiveStorage::class);
        $storage->method('writeAtomic')->willThrowException(new \RuntimeException('disk full'));
        $writer = app(BrChunkWriter::class);
        try {
            $writer->writeShadowChunks($storage, self::ARCHIVE_ID, self::REQUEST_ID);
            $this->fail('expected LaraException(BackupStorageFailure)');
        } catch (\App\Exceptions\LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->code());
        }
        Log::shouldHaveReceived('error')->withArgs(function (string $msg, array $ctx): bool {
            return $msg === 'br.export.chunk.failed'
                && $ctx['Stage'] === 'persist'
                && $ctx['Path'] === self::EXPECTED_ORDER[0]
                && $ctx['Ordinal'] === 0
                && is_string($ctx['Sha256']) && preg_match('/^[0-9a-f]{64}$/', $ctx['Sha256']) === 1
                && $ctx['ErrorClass'] === \RuntimeException::class
                && $ctx['ErrorMessage'] === 'disk full'
                && $ctx['RequestId'] === self::REQUEST_ID;
        })->once();
    }
}
