<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrScopeDomainCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 14 step 19 contract tests for the SC-F Domain-tables collector.
 *
 * Locks:
 *  - Alphabetical iteration over the configured allowlist (spec 26 §08
 *    INV-BR-AF-5); JSONL rows sorted by canonical string bytes.
 *  - Every table produces `scope/domain/<Table>.jsonl.zst` with a stable
 *    `contentHash = sha256(bytes)`; aggregate hash covers the ordered
 *    per-table names + hashes.
 *  - Missing configured table => `BackupStorageFailure` at
 *    `/scope/domain/<name>` rule `DomainTableMissing`.
 *  - Unreadable table => `BackupStorageFailure` at same field, rule
 *    `DomainTableUnreadable`.
 *  - Empty allowlist => zero bodies, `sha256('')` aggregate.
 */
final class BrScopeDomainCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_ID = 'req-sc-f-0001';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_empty_allowlist_yields_no_bodies_and_empty_hash(): void
    {
        Config::set('lara.br.domain_root_tables', []);

        $out = app(BrScopeDomainCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame([], $out['Bodies']);
        $this->assertSame([], $out['RelPaths']);
        $this->assertSame([], $out['Tables']);
        $this->assertSame(self::EMPTY_SHA256, $out['ContentHash']);
        $this->assertSame(0, $out['TableCount']);
        $this->assertSame(0, $out['TotalRowCount']);
    }

    public function test_alphabetical_order_and_per_table_hashes(): void
    {
        Config::set('lara.br.domain_root_tables', ['Resellers', 'Prefixes']);
        DB::connection('root')->table('Resellers')->delete();
        DB::connection('root')->table('Prefixes')->delete();

        $out = app(BrScopeDomainCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame(['Prefixes', 'Resellers'], array_column($out['Tables'], 'name'));
        $this->assertSame(['scope/domain/Prefixes.jsonl.zst', 'scope/domain/Resellers.jsonl.zst'], $out['RelPaths']);
        foreach ($out['Tables'] as $t) {
            $this->assertSame(self::EMPTY_SHA256, $t['contentHash']);
            $this->assertSame(0, $t['rowCount']);
        }
    }

    public function test_rows_are_serialized_canonically_and_row_count_is_reported(): void
    {
        Config::set('lara.br.domain_root_tables', ['Prefixes']);
        DB::connection('root')->table('Prefixes')->delete();
        DB::connection('root')->table('Prefixes')->insert([
            ['PrefixValue' => 'ZZZ'],
            ['PrefixValue' => 'AAA'],
        ]);

        $out = app(BrScopeDomainCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame(2, $out['Tables'][0]['rowCount']);
        $this->assertSame(2, $out['TotalRowCount']);
        $jsonl = $out['Bodies']['scope/domain/Prefixes.jsonl.zst'];
        $lines = array_values(array_filter(explode("\n", $jsonl)));
        $this->assertCount(2, $lines);
        // Rows sorted by canonical JSON string => AAA precedes ZZZ.
        $first = json_decode($lines[0], true);
        $this->assertSame('AAA', $first['PrefixValue']);
    }

    public function test_determinism_across_calls(): void
    {
        Config::set('lara.br.domain_root_tables', ['Prefixes', 'Resellers']);
        $a = app(BrScopeDomainCollector::class)->collect(self::REQUEST_ID);
        $b = app(BrScopeDomainCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame($a['ContentHash'], $b['ContentHash']);
        $this->assertSame($a['Bodies'], $b['Bodies']);
    }

    public function test_missing_configured_table_raises_backup_storage_failure(): void
    {
        Config::set('lara.br.domain_root_tables', ['NoSuchTable']);
        try {
            app(BrScopeDomainCollector::class)->collect(self::REQUEST_ID);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/domain/NoSuchTable', $e->violations[0]['Field']);
            $this->assertSame('DomainTableMissing', $e->violations[0]['Rule']);
        }
    }

    public function test_unreadable_table_raises_backup_storage_failure(): void
    {
        Config::set('lara.br.domain_root_tables', ['Prefixes']);
        Schema::connection('root')->rename('Prefixes', 'PrefixesTmpBr');
        try {
            app(BrScopeDomainCollector::class)->collect(self::REQUEST_ID);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/domain/Prefixes', $e->violations[0]['Field']);
            $this->assertSame('DomainTableMissing', $e->violations[0]['Rule']);
        } finally {
            Schema::connection('root')->rename('PrefixesTmpBr', 'Prefixes');
        }
    }
}
