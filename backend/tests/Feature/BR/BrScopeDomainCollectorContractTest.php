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
 * Plan 14 SC-F contract tests. Locks byte-exact JSONL rows, per-table
 * `contentHash` derivation, alphabetical table ordering (INV-BR-AF-5),
 * canonical row ordering, aggregate hash formula, manifest slot shape,
 * empty-table hash equality with `sha256('')`, UTF-8 preservation,
 * relPath naming, and the closed-set failure branches
 * (`DomainTableMissing`, `DomainTableUnreadable`, `DomainRowNotEncodable`).
 * Complements `BrScopeDomainCollectorTest`.
 */
final class BrScopeDomainCollectorContractTest extends TestCase
{
    use RefreshDatabase;

    private const REQ = 'req-sc-f-contract-0001';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private function reset(array $tables): void
    {
        Config::set('lara.br.domain_root_tables', $tables);
        foreach ($tables as $t) {
            if (Schema::connection('root')->hasTable($t)) {
                DB::connection('root')->table($t)->delete();
            }
        }
    }

    public function test_relpath_naming_scheme_is_scope_domain_name_jsonl_zst(): void
    {
        $this->reset(['Prefixes', 'Resellers']);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $this->assertSame(['scope/domain/Prefixes.jsonl.zst', 'scope/domain/Resellers.jsonl.zst'], $out['RelPaths']);
        $this->assertSame(array_keys($out['Bodies']), $out['RelPaths']);
    }

    public function test_manifest_slot_shape_has_exactly_name_rowcount_contenthash(): void
    {
        $this->reset(['Prefixes']);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $this->assertCount(1, $out['Tables']);
        $keys = array_keys($out['Tables'][0]);
        sort($keys);
        $this->assertSame(['contentHash', 'name', 'rowCount'], $keys);
        $this->assertIsString($out['Tables'][0]['name']);
        $this->assertIsInt($out['Tables'][0]['rowCount']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $out['Tables'][0]['contentHash']);
    }

    public function test_alphabetical_table_ordering_ignores_config_input_order(): void
    {
        $this->reset(['Resellers', 'Prefixes', 'AuditLogs']);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $this->assertSame(['AuditLogs', 'Prefixes', 'Resellers'], array_column($out['Tables'], 'name'));
        $this->assertSame(['scope/domain/AuditLogs.jsonl.zst', 'scope/domain/Prefixes.jsonl.zst', 'scope/domain/Resellers.jsonl.zst'], $out['RelPaths']);
    }

    public function test_empty_table_content_hash_equals_sha256_of_empty_string(): void
    {
        $this->reset(['Prefixes']);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $this->assertSame(self::EMPTY_SHA256, $out['Tables'][0]['contentHash']);
        $this->assertSame('', $out['Bodies']['scope/domain/Prefixes.jsonl.zst']);
    }

    public function test_per_table_content_hash_equals_sha256_of_body_bytes(): void
    {
        $this->reset(['Prefixes']);
        DB::connection('root')->table('Prefixes')->insert([
            ['PrefixValue' => 'AAA'],
            ['PrefixValue' => 'BBB'],
        ]);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $body = $out['Bodies']['scope/domain/Prefixes.jsonl.zst'];
        $this->assertSame(hash('sha256', $body), $out['Tables'][0]['contentHash']);
    }

    public function test_rows_sorted_by_canonical_json_bytes_regardless_of_insert_order(): void
    {
        $this->reset(['Prefixes']);
        DB::connection('root')->table('Prefixes')->insert([
            ['PrefixValue' => 'ZZZ'],
            ['PrefixValue' => 'MMM'],
            ['PrefixValue' => 'AAA'],
        ]);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $lines = explode("\n", rtrim($out['Bodies']['scope/domain/Prefixes.jsonl.zst'], "\n"));
        $values = array_map(static fn (string $l) => json_decode($l, true)['PrefixValue'], $lines);
        $this->assertSame(['AAA', 'MMM', 'ZZZ'], $values);
    }

    public function test_jsonl_body_ends_with_single_lf_and_uses_lf_only(): void
    {
        $this->reset(['Prefixes']);
        DB::connection('root')->table('Prefixes')->insert([['PrefixValue' => 'AAA']]);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $body = $out['Bodies']['scope/domain/Prefixes.jsonl.zst'];
        $this->assertStringEndsWith("\n", $body);
        $this->assertStringNotContainsString("\r", $body);
        $this->assertSame(1, substr_count($body, "\n"));
    }

    public function test_row_keys_are_sorted_lexicographically_in_each_jsonl_line(): void
    {
        $this->reset(['Prefixes']);
        DB::connection('root')->table('Prefixes')->insert([['PrefixValue' => 'AAA']]);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $line = rtrim($out['Bodies']['scope/domain/Prefixes.jsonl.zst'], "\n");
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $keys = array_keys($decoded);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function test_utf8_row_values_preserved_without_unicode_escapes(): void
    {
        $this->reset(['Prefixes']);
        DB::connection('root')->table('Prefixes')->insert([['PrefixValue' => 'café-ünïcode']]);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $body = $out['Bodies']['scope/domain/Prefixes.jsonl.zst'];
        $this->assertStringContainsString('café-ünïcode', $body);
        $this->assertStringNotContainsString('\\u00e9', $body);
    }

    public function test_aggregate_content_hash_equals_sha256_of_name_tab_hash_lines(): void
    {
        $this->reset(['Prefixes', 'Resellers']);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $agg = '';
        foreach ($out['Tables'] as $t) {
            $agg .= $t['name'] . "\t" . $t['contentHash'] . "\n";
        }
        $this->assertSame(hash('sha256', $agg), $out['ContentHash']);
    }

    public function test_aggregate_hash_changes_when_a_row_is_added(): void
    {
        $this->reset(['Prefixes']);
        $before = app(BrScopeDomainCollector::class)->collect(self::REQ)['ContentHash'];
        DB::connection('root')->table('Prefixes')->insert([['PrefixValue' => 'AAA']]);
        $after = app(BrScopeDomainCollector::class)->collect(self::REQ)['ContentHash'];

        $this->assertNotSame($before, $after);
    }

    public function test_totals_report_declared_table_and_row_counts(): void
    {
        $this->reset(['Prefixes', 'Resellers']);
        DB::connection('root')->table('Prefixes')->insert([['PrefixValue' => 'AAA'], ['PrefixValue' => 'BBB']]);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $this->assertSame(2, $out['TableCount']);
        $this->assertSame(2, $out['TotalRowCount']);
    }

    public function test_determinism_across_repeated_calls_yields_byte_identical_bodies(): void
    {
        $this->reset(['Prefixes']);
        DB::connection('root')->table('Prefixes')->insert([['PrefixValue' => 'AAA'], ['PrefixValue' => 'BBB']]);
        $a = app(BrScopeDomainCollector::class)->collect(self::REQ);
        $b = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $this->assertSame($a['Bodies'], $b['Bodies']);
        $this->assertSame($a['Tables'], $b['Tables']);
        $this->assertSame($a['ContentHash'], $b['ContentHash']);
    }

    public function test_missing_configured_table_raises_domain_table_missing(): void
    {
        Config::set('lara.br.domain_root_tables', ['GhostTable']);
        try {
            app(BrScopeDomainCollector::class)->collect(self::REQ);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/domain/GhostTable', $e->violations[0]['Field']);
            $this->assertSame('DomainTableMissing', $e->violations[0]['Rule']);
        }
    }

    public function test_duplicate_table_names_are_deduplicated_before_iteration(): void
    {
        $this->reset(['Prefixes']);
        Config::set('lara.br.domain_root_tables', ['Prefixes', 'Prefixes', 'Prefixes']);
        $out = app(BrScopeDomainCollector::class)->collect(self::REQ);

        $this->assertSame(1, $out['TableCount']);
        $this->assertSame(['Prefixes'], array_column($out['Tables'], 'name'));
    }
}
