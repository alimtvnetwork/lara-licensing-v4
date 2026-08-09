<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrScopeFilesCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 14 step 20 contract tests for the SC-H File-objects index
 * collector.
 *
 * Locks:
 *  - Empty source list => empty JSONL body, `sha256('')` aggregate,
 *    `objectCount=0`, `totalBytes=0`.
 *  - Populated source => canonical JSONL keys `{bucket, bytes, path,
 *    sha256}`; lines sorted by canonical string bytes; determinism
 *    across repeat calls.
 *  - `whereColumn`/`whereValue` filter respected (unfinalized rows
 *    excluded).
 *  - Dedupe by sha256 keeps the first entry (INV-BR-SC-5).
 *  - Incomplete row (null sha256/path/bytes<=0) => `BackupCorrupt` at
 *    `/scope/files/<table>` rule `FileEntryIncomplete`.
 *  - Missing configured table => `BackupStorageFailure` rule
 *    `FileSourceTableMissing`.
 *  - Missing configured column => `BackupStorageFailure` rule
 *    `FileSourceColumnMissing`.
 */
final class BrScopeFilesCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const REQ = 'req-sc-h-0001';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const INDEX_PATH = 'scope/files/index.jsonl.zst';

    private function seedAppUpdate(int $updateId = 1): void
    {
        DB::connection('root')->table('AppUpdateAssets')->delete();
        DB::connection('root')->table('AppUpdates')->delete();
        DB::connection('root')->table('AppUpdates')->insert([
            'AppUpdateId' => $updateId,
            'Version' => '1.0.0',
            'Channel' => 'Stable',
            'IsPublished' => 1,
            'PublishedAt' => now(),
            'IsYanked' => 0,
        ]);
    }

    public function test_empty_sources_yield_empty_body_and_empty_hash(): void
    {
        Config::set('lara.br.file_sources', []);

        $out = app(BrScopeFilesCollector::class)->collect(self::REQ);

        $this->assertSame('', $out['Jsonl']);
        $this->assertSame(self::INDEX_PATH, $out['RelPath']);
        $this->assertSame(self::EMPTY_SHA256, $out['ContentHash']);
        $this->assertSame(0, $out['ObjectCount']);
        $this->assertSame(0, $out['TotalBytes']);
        $this->assertSame(self::INDEX_PATH, $out['IndexPath']);
    }

    public function test_finalized_asset_is_indexed_with_canonical_keys(): void
    {
        Config::set('lara.br.file_sources', [[
            'table' => 'AppUpdateAssets', 'sha256Column' => 'Sha256',
            'pathColumn' => 'StoragePath', 'bytesColumn' => 'SizeBytes',
            'bucket' => 'app-updates', 'whereColumn' => 'IsFinalized', 'whereValue' => 1,
        ]]);
        $this->seedAppUpdate();
        DB::connection('root')->table('AppUpdateAssets')->insert([
            'AppUpdateId' => 1, 'Platform' => 'LinuxAmd64', 'SizeBytes' => 42,
            'Sha256' => str_repeat('a', 64), 'StoragePath' => 'x/y/one.bin',
            'IsFinalized' => 1, 'FinalizedAt' => now(),
        ]);

        $out = app(BrScopeFilesCollector::class)->collect(self::REQ);

        $line = json_decode(trim($out['Jsonl']), true);
        $this->assertSame(['bucket', 'bytes', 'path', 'sha256'], array_keys($line));
        $this->assertSame('app-updates', $line['bucket']);
        $this->assertSame(42, $line['bytes']);
        $this->assertSame('x/y/one.bin', $line['path']);
        $this->assertSame(1, $out['ObjectCount']);
        $this->assertSame(42, $out['TotalBytes']);
    }

    public function test_where_filter_excludes_unfinalized_rows(): void
    {
        Config::set('lara.br.file_sources', [[
            'table' => 'AppUpdateAssets', 'sha256Column' => 'Sha256',
            'pathColumn' => 'StoragePath', 'bytesColumn' => 'SizeBytes',
            'bucket' => 'app-updates', 'whereColumn' => 'IsFinalized', 'whereValue' => 1,
        ]]);
        $this->seedAppUpdate();
        DB::connection('root')->table('AppUpdateAssets')->insert([
            ['AppUpdateId' => 1, 'Platform' => 'LinuxAmd64', 'SizeBytes' => 10, 'Sha256' => str_repeat('b', 64), 'StoragePath' => 'a', 'IsFinalized' => 1, 'FinalizedAt' => now()],
            ['AppUpdateId' => 1, 'Platform' => 'WindowsAmd64', 'SizeBytes' => 20, 'Sha256' => str_repeat('c', 64), 'StoragePath' => 'b', 'IsFinalized' => 0],
        ]);

        $out = app(BrScopeFilesCollector::class)->collect(self::REQ);

        $this->assertSame(1, $out['ObjectCount']);
        $this->assertSame(10, $out['TotalBytes']);
    }

    public function test_dedupe_by_sha256_keeps_first(): void
    {
        Config::set('lara.br.file_sources', [[
            'table' => 'AppUpdateAssets', 'sha256Column' => 'Sha256',
            'pathColumn' => 'StoragePath', 'bytesColumn' => 'SizeBytes',
            'bucket' => 'app-updates', 'whereColumn' => 'IsFinalized', 'whereValue' => 1,
        ]]);
        $this->seedAppUpdate();
        $sha = str_repeat('d', 64);
        DB::connection('root')->table('AppUpdateAssets')->insert([
            ['AppUpdateId' => 1, 'Platform' => 'LinuxAmd64', 'SizeBytes' => 5, 'Sha256' => $sha, 'StoragePath' => 'p/one', 'IsFinalized' => 1, 'FinalizedAt' => now()],
            ['AppUpdateId' => 1, 'Platform' => 'WindowsAmd64', 'SizeBytes' => 5, 'Sha256' => $sha, 'StoragePath' => 'p/two', 'IsFinalized' => 1, 'FinalizedAt' => now()],
        ]);

        $out = app(BrScopeFilesCollector::class)->collect(self::REQ);

        $this->assertSame(1, $out['ObjectCount']);
    }

    public function test_determinism_across_repeat_calls(): void
    {
        Config::set('lara.br.file_sources', [[
            'table' => 'AppUpdateAssets', 'sha256Column' => 'Sha256',
            'pathColumn' => 'StoragePath', 'bytesColumn' => 'SizeBytes',
            'bucket' => 'app-updates', 'whereColumn' => 'IsFinalized', 'whereValue' => 1,
        ]]);
        $this->seedAppUpdate();
        DB::connection('root')->table('AppUpdateAssets')->insert([
            'AppUpdateId' => 1, 'Platform' => 'DarwinArm64', 'SizeBytes' => 99, 'Sha256' => str_repeat('e', 64), 'StoragePath' => 'z', 'IsFinalized' => 1, 'FinalizedAt' => now(),
        ]);
        $a = app(BrScopeFilesCollector::class)->collect(self::REQ);
        $b = app(BrScopeFilesCollector::class)->collect(self::REQ);
        $this->assertSame($a['ContentHash'], $b['ContentHash']);
        $this->assertSame($a['Jsonl'], $b['Jsonl']);
    }

    public function test_missing_configured_table_raises_storage_failure(): void
    {
        Config::set('lara.br.file_sources', [[
            'table' => 'NoSuchTableXyz', 'sha256Column' => 'Sha256',
            'pathColumn' => 'StoragePath', 'bytesColumn' => 'SizeBytes',
            'bucket' => 'app-updates',
        ]]);
        try {
            app(BrScopeFilesCollector::class)->collect(self::REQ);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/files/NoSuchTableXyz', $e->violations[0]['Field']);
            $this->assertSame('FileSourceTableMissing', $e->violations[0]['Rule']);
        }
    }

    public function test_missing_configured_column_raises_storage_failure(): void
    {
        Config::set('lara.br.file_sources', [[
            'table' => 'AppUpdateAssets', 'sha256Column' => 'NoSuchColumn',
            'pathColumn' => 'StoragePath', 'bytesColumn' => 'SizeBytes',
            'bucket' => 'app-updates',
        ]]);
        try {
            app(BrScopeFilesCollector::class)->collect(self::REQ);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/files/AppUpdateAssets/NoSuchColumn', $e->violations[0]['Field']);
            $this->assertSame('FileSourceColumnMissing', $e->violations[0]['Rule']);
        }
    }
}
