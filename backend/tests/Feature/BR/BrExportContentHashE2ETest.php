<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Services\BR\BrArchiveStorage;
use App\Services\BR\BrManifestBuilder;
use App\Services\BR\BrScopeClosedSetsCollector;
use App\Services\BR\BrScopeSchemaCollector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 14 step 26 end-to-end contract tests for the two "unlocked"
 * production classes (SC-A Schema, SC-B ClosedSets).
 *
 * Contract:
 *  - Run the same collect -> assemble -> writeAtomic path
 *    `BrExportWorker::materializeArchive` uses.
 *  - Decode `manifest.json` off disk and assert
 *      `scope.schema.contentHash    === sha256(SC-A.Jsonl)`
 *      `scope.closedSets.contentHash === sha256(SC-B.Jsonl)`
 *    (INV-BR-MS-2, INV-BR-MS-3).
 *  - Neither hash may equal `sha256("")` - the shadow placeholder
 *    that `BrManifestBuilder::EMPTY_SHA256` fills in when a collector
 *    is not wired. This locks the "wired real bytes" invariant against
 *    silent regression to the pre-v0.632.0 shadow default.
 *  - `scope.schema.migrations` mirrors the collector's ordered list;
 *    `scope.closedSets.{setCount,valueCount}` mirror the collector's
 *    counts. Any drift means the manifest is bound to different bytes
 *    than the ones the archive claims to contain.
 */
final class BrExportContentHashE2ETest extends TestCase
{
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const ARCHIVE_ID = '018fa7c3-e2e2-7c4d-8e5f-6a7b8c9d0e21';
    private const APP_VERSION = '0.633.0';
    private const USER_ID = '018fa7c3-e2e2-7c4d-8e5f-6a7b8c9d0e22';
    private const REQUEST_ID = 'req-e2e-contenthash-0001';
    private const CONN = 'root';

    protected function setUp(): void
    {
        parent::setUp();
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'br-e2e-hash-' . bin2hex(random_bytes(4));
        Config::set('lara.br.archive_root', $root);
        $migDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'br-e2e-mig-' . bin2hex(random_bytes(4));
        if (! is_dir($migDir) && ! mkdir($migDir, 0o700, true) && ! is_dir($migDir)) {
            $this->fail('could not create migrations fixture dir');
        }
        Config::set('lara.br.root_migrations_path', $migDir);
        $this->seedMigrations($migDir);
    }

    public function test_manifest_binds_real_schema_and_closed_sets_content_hashes(): void
    {
        [$manifest, $schema, $closedSets] = $this->materialize();

        $this->assertSame($schema['ContentHash'], $manifest['scope']['schema']['contentHash']);
        $this->assertSame($closedSets['ContentHash'], $manifest['scope']['closedSets']['contentHash']);
    }

    public function test_scope_hashes_recompute_from_collector_jsonl(): void
    {
        [$manifest, $schema, $closedSets] = $this->materialize();

        $this->assertSame(hash('sha256', $schema['Jsonl']), $manifest['scope']['schema']['contentHash']);
        $this->assertSame(hash('sha256', $closedSets['Jsonl']), $manifest['scope']['closedSets']['contentHash']);
    }

    public function test_scope_hashes_are_not_empty_sha256_placeholder(): void
    {
        [$manifest] = $this->materialize();

        $this->assertNotSame(self::EMPTY_SHA256, $manifest['scope']['schema']['contentHash'], 'schema.contentHash regressed to shadow placeholder');
        $this->assertNotSame(self::EMPTY_SHA256, $manifest['scope']['closedSets']['contentHash'], 'closedSets.contentHash regressed to shadow placeholder');
    }

    public function test_scope_slot_counters_mirror_collector_output(): void
    {
        [$manifest, $schema, $closedSets] = $this->materialize();

        $this->assertSame($schema['Migrations'], $manifest['scope']['schema']['migrations']);
        $this->assertSame($closedSets['SetCount'], $manifest['scope']['closedSets']['setCount']);
        $this->assertSame($closedSets['ValueCount'], $manifest['scope']['closedSets']['valueCount']);
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,mixed>}
     */
    private function materialize(): array
    {
        /** @var BrArchiveStorage $storage */
        $storage = app(BrArchiveStorage::class);
        $storage->reserve(self::ARCHIVE_ID, self::REQUEST_ID);
        /** @var array<string,mixed> $schema */
        $schema = app(BrScopeSchemaCollector::class)->collect(self::REQUEST_ID);
        /** @var array<string,mixed> $closedSets */
        $closedSets = app(BrScopeClosedSetsCollector::class)->collect(self::REQUEST_ID);
        $overrides = [
            'schema' => ['contentHash' => $schema['ContentHash'], 'migrations' => $schema['Migrations']],
            'closedSets' => ['contentHash' => $closedSets['ContentHash'], 'setCount' => $closedSets['SetCount'], 'valueCount' => $closedSets['ValueCount']],
        ];
        app(BrManifestBuilder::class)->writeShadowManifest(
            $storage, self::ARCHIVE_ID, self::APP_VERSION, self::USER_ID, self::REQUEST_ID,
            [], self::EMPTY_SHA256, $overrides, (string) $schema['SchemaHash'],
        );
        $manifest = $this->readManifest($storage);

        return [$manifest, $schema, $closedSets];
    }

    /** @return array<string,mixed> */
    private function readManifest(BrArchiveStorage $storage): array
    {
        $path = $storage->path(self::ARCHIVE_ID) . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->assertFileExists($path);
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function seedMigrations(string $dir): void
    {
        $sch = Schema::connection(self::CONN);
        if (! $sch->hasTable('migrations')) {
            $sch->create('migrations', function ($t) {
                $t->increments('id');
                $t->string('migration');
                $t->integer('batch');
            });
        }
        DB::connection(self::CONN)->table('migrations')->delete();
        $names = ['2026_08_08_000001_e2e_a', '2026_08_08_000002_e2e_b'];
        foreach ($names as $n) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . $n . '.php', "<?php // {$n}\n");
            DB::connection(self::CONN)->table('migrations')->insert(['migration' => $n, 'batch' => 1]);
        }
    }
}
