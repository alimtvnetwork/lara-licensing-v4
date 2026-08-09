<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrScopeSecretsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 14 step 21 contract tests for the SC-G Secrets envelope
 * collector.
 *
 * Locks:
 *  - Empty source list => empty body, `sha256('')` aggregate, active
 *    epoch Kid + Epoch surfaced regardless.
 *  - Populated source seals plaintext into `PayloadB64` with alpha
 *    keys `{Epoch, Field, Kid, NonceB64, PayloadB64, PkB64, Table}`
 *    and lines sorted as strings; determinism across calls.
 *  - Where-filter respected; incomplete row raises `BackupCorrupt`
 *    rule `SecretEntryIncomplete`.
 *  - Missing table => `BackupStorageFailure` rule
 *    `SecretSourceTableMissing`.
 *  - Missing column => `BackupStorageFailure` rule
 *    `SecretSourceColumnMissing`.
 */
final class BrScopeSecretsCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const REQ = 'req-sc-g-0001';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const BODY_PATH = 'scope/secrets-envelope.bin.zst';
    private const TABLE = 'br_test_secrets';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureActiveEpoch();
    }

    protected function tearDown(): void
    {
        if (Schema::connection('root')->hasTable(self::TABLE)) {
            Schema::connection('root')->drop(self::TABLE);
        }
        parent::tearDown();
    }

    private function ensureActiveEpoch(): void
    {
        $kek = base64_encode(str_repeat("\x11", 32));
        $kek = rtrim(strtr($kek, '+/', '-_'), '=');
        Config::set('br.kek_material', ['epoch-active-ref' => $kek]);
        DB::connection('root')->table('BrKekEpochs')->where('State', 'Active')->update(['State' => 'Retired']);
        DB::connection('root')->table('BrKekEpochs')->updateOrInsert(
            ['Epoch' => 42],
            ['Kid' => 'epoch-42-test', 'State' => 'Active', 'SecretsRef' => 'epoch-active-ref', 'CreatedAt' => now(), 'UpdatedAt' => now()],
        );
    }

    private function createTestTable(): void
    {
        Schema::connection('root')->create(self::TABLE, function ($table) {
            $table->bigIncrements('Id');
            $table->string('Field');
            $table->text('Secret');
            $table->boolean('IsActive')->default(true);
        });
    }

    public function test_empty_sources_yield_empty_body_and_empty_hash(): void
    {
        Config::set('lara.br.secret_sources', []);

        $out = app(BrScopeSecretsCollector::class)->collect(self::REQ);

        $this->assertSame('', $out['Body']);
        $this->assertSame(self::BODY_PATH, $out['RelPath']);
        $this->assertSame(self::EMPTY_SHA256, $out['ContentHash']);
        $this->assertSame('hkdf-sha256', $out['Algorithm']);
        $this->assertSame(42, $out['Epoch']);
        $this->assertSame('epoch-42-test', $out['Kid']);
        $this->assertSame(0, $out['RowCount']);
    }

    public function test_populated_source_seals_rows_with_canonical_keys(): void
    {
        $this->createTestTable();
        DB::connection('root')->table(self::TABLE)->insert([
            ['Id' => 1, 'Field' => 'ApiKey', 'Secret' => 'plaintext-one', 'IsActive' => 1],
            ['Id' => 2, 'Field' => 'WebhookSecret', 'Secret' => 'plaintext-two', 'IsActive' => 1],
        ]);
        Config::set('lara.br.secret_sources', [[
            'table' => self::TABLE, 'pkColumn' => 'Id', 'fieldColumn' => 'Field',
            'valueColumn' => 'Secret', 'whereColumn' => 'IsActive', 'whereValue' => 1,
        ]]);

        $out = app(BrScopeSecretsCollector::class)->collect(self::REQ);

        $this->assertSame(2, $out['RowCount']);
        $lines = array_values(array_filter(explode("\n", $out['Body'])));
        $this->assertCount(2, $lines);
        $first = json_decode($lines[0], true);
        $this->assertSame(['Epoch', 'Field', 'Kid', 'NonceB64', 'PayloadB64', 'PkB64', 'Table'], array_keys($first));
        $this->assertSame(42, $first['Epoch']);
        $this->assertSame('epoch-42-test', $first['Kid']);
        $this->assertSame(self::TABLE, $first['Table']);
    }

    public function test_where_filter_excludes_inactive_rows(): void
    {
        $this->createTestTable();
        DB::connection('root')->table(self::TABLE)->insert([
            ['Id' => 1, 'Field' => 'A', 'Secret' => 'x', 'IsActive' => 1],
            ['Id' => 2, 'Field' => 'B', 'Secret' => 'y', 'IsActive' => 0],
        ]);
        Config::set('lara.br.secret_sources', [[
            'table' => self::TABLE, 'pkColumn' => 'Id', 'fieldColumn' => 'Field',
            'valueColumn' => 'Secret', 'whereColumn' => 'IsActive', 'whereValue' => 1,
        ]]);

        $out = app(BrScopeSecretsCollector::class)->collect(self::REQ);

        $this->assertSame(1, $out['RowCount']);
    }

    public function test_determinism_across_repeat_calls(): void
    {
        $this->createTestTable();
        DB::connection('root')->table(self::TABLE)->insert([
            ['Id' => 1, 'Field' => 'ApiKey', 'Secret' => 'v1', 'IsActive' => 1],
            ['Id' => 2, 'Field' => 'Signing', 'Secret' => 'v2', 'IsActive' => 1],
        ]);
        Config::set('lara.br.secret_sources', [[
            'table' => self::TABLE, 'pkColumn' => 'Id', 'fieldColumn' => 'Field',
            'valueColumn' => 'Secret',
        ]]);

        $a = app(BrScopeSecretsCollector::class)->collect(self::REQ);
        $b = app(BrScopeSecretsCollector::class)->collect(self::REQ);

        $this->assertSame($a['ContentHash'], $b['ContentHash']);
        $this->assertSame($a['Body'], $b['Body']);
    }

    public function test_incomplete_row_raises_backup_corrupt(): void
    {
        $this->createTestTable();
        DB::connection('root')->table(self::TABLE)->insert([
            ['Id' => 1, 'Field' => 'A', 'Secret' => '', 'IsActive' => 1],
        ]);
        Config::set('lara.br.secret_sources', [[
            'table' => self::TABLE, 'pkColumn' => 'Id', 'fieldColumn' => 'Field',
            'valueColumn' => 'Secret',
        ]]);

        try {
            app(BrScopeSecretsCollector::class)->collect(self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('BackupCorrupt', $e->errorCode);
            $this->assertSame('/scope/secrets-envelope/' . self::TABLE, $e->violations[0]['Field']);
            $this->assertSame('SecretEntryIncomplete', $e->violations[0]['Rule']);
        }
    }

    public function test_missing_configured_table_raises_storage_failure(): void
    {
        Config::set('lara.br.secret_sources', [[
            'table' => 'NoSuchTableXyz', 'pkColumn' => 'Id',
            'fieldColumn' => 'Field', 'valueColumn' => 'Secret',
        ]]);

        try {
            app(BrScopeSecretsCollector::class)->collect(self::REQ);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/secrets-envelope/NoSuchTableXyz', $e->violations[0]['Field']);
            $this->assertSame('SecretSourceTableMissing', $e->violations[0]['Rule']);
        }
    }

    public function test_missing_configured_column_raises_storage_failure(): void
    {
        $this->createTestTable();
        Config::set('lara.br.secret_sources', [[
            'table' => self::TABLE, 'pkColumn' => 'NoSuchCol',
            'fieldColumn' => 'Field', 'valueColumn' => 'Secret',
        ]]);

        try {
            app(BrScopeSecretsCollector::class)->collect(self::REQ);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/secrets-envelope/' . self::TABLE . '/NoSuchCol', $e->violations[0]['Field']);
            $this->assertSame('SecretSourceColumnMissing', $e->violations[0]['Rule']);
        }
    }
}
