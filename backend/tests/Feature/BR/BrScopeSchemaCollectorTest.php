<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrScopeSchemaCollector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 14 step 14 contract tests for the SC-A schema collector.
 *
 * Locks:
 *  - Rows are read from `public.migrations` on the `root` connection
 *    and ordered by `id` (spec 26 §05 SC-A selector).
 *  - JSONL is canonical: keys sorted lexicographically (`batch`
 *    before `migration`), LF-terminated, one row per line.
 *  - `SchemaHash` is SHA-256 over concatenated migration file bodies
 *    in table order (INV-BR-MS-2 anchor).
 *  - Missing migration file on disk throws `BackupCorrupt` with a
 *    stable JSON pointer, never a silently mis-hashed archive.
 */
final class BrScopeSchemaCollectorTest extends TestCase
{
    private const REQUEST_ID = 'req-sc-a-0001';
    private const CONN = 'root';

    protected function setUp(): void
    {
        parent::setUp();
        $fixtureDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'br-sc-a-' . bin2hex(random_bytes(4));
        if (! is_dir($fixtureDir) && ! mkdir($fixtureDir, 0o700, true) && ! is_dir($fixtureDir)) {
            $this->fail('could not create fixture dir');
        }
        Config::set('lara.br.root_migrations_path', $fixtureDir);
        $this->resetMigrationsTable();
    }

    public function test_collects_rows_in_table_order_and_hashes_files(): void
    {
        $dir = (string) config('lara.br.root_migrations_path');
        $a = '2026_07_18_000001_a'; $b = '2026_07_18_000002_b';
        file_put_contents($dir . DIRECTORY_SEPARATOR . $a . '.php', "<?php // A\n");
        file_put_contents($dir . DIRECTORY_SEPARATOR . $b . '.php', "<?php // B\n");
        $this->insertMigration($a, 1);
        $this->insertMigration($b, 1);
        $expectHash = hash('sha256', file_get_contents($dir . DIRECTORY_SEPARATOR . $a . '.php') . file_get_contents($dir . DIRECTORY_SEPARATOR . $b . '.php'));

        $out = app(BrScopeSchemaCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame([$a, $b], $out['Migrations']);
        $this->assertSame($expectHash, $out['SchemaHash']);
        $this->assertSame('scope/schema.jsonl.zst', $out['RelPath']);
        $this->assertSame(hash('sha256', $out['Jsonl']), $out['ContentHash']);
        $this->assertSame(2, substr_count($out['Jsonl'], "\n"));
    }

    public function test_jsonl_lines_are_canonical_sorted_keys(): void
    {
        $dir = (string) config('lara.br.root_migrations_path');
        $name = '2026_07_18_000001_z';
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name . '.php', "<?php // z\n");
        $this->insertMigration($name, 7);

        $out = app(BrScopeSchemaCollector::class)->collect(self::REQUEST_ID);

        $expected = json_encode(['batch' => 7, 'migration' => $name], JSON_UNESCAPED_SLASHES) . "\n";
        $this->assertSame($expected, $out['Jsonl']);
    }

    public function test_missing_file_on_disk_fails_backup_corrupt(): void
    {
        $name = '2026_07_18_000001_ghost';
        $this->insertMigration($name, 1);

        try {
            app(BrScopeSchemaCollector::class)->collect(self::REQUEST_ID);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('BackupCorrupt', $e->errorCode);
        }
    }

    public function test_empty_migrations_table_yields_empty_body_and_content_hash_of_empty(): void
    {
        $out = app(BrScopeSchemaCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame([], $out['Migrations']);
        $this->assertSame('', $out['Jsonl']);
        $this->assertSame(hash('sha256', ''), $out['ContentHash']);
        $this->assertSame(hash('sha256', ''), $out['SchemaHash']);
    }

    private function resetMigrationsTable(): void
    {
        $schema = Schema::connection(self::CONN);
        if (! $schema->hasTable('migrations')) {
            $schema->create('migrations', function ($table) {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
        }
        DB::connection(self::CONN)->table('migrations')->delete();
    }

    private function insertMigration(string $name, int $batch): void
    {
        DB::connection(self::CONN)->table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
    }
}
