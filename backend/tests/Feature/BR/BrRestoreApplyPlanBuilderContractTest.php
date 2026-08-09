<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Services\BR\BrRestoreApplyPlanBuilder;
use Tests\TestCase;

/**
 * Plan 14 step 26 contract tests for {@see BrRestoreApplyPlanBuilder}.
 *
 * Locks: deterministic step ordering (Schema, ClosedSets, then domain
 * tables in their input order which equals alphabetical per SC-F),
 * ReplaceTable RelPath format, Drift flag semantics, Totals math, and
 * the PlanId formula (`sha256(canonicalJsonOfSteps)`).
 */
final class BrRestoreApplyPlanBuilderContractTest extends TestCase
{
    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const HASH_C = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const HASH_D = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const HASH_E = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const ARCHIVE_ID = '00000000-0000-4000-8000-000000000001';
    private const REQ = 'req-plan-26';

    private function builder(): BrRestoreApplyPlanBuilder
    {
        return new BrRestoreApplyPlanBuilder();
    }

    /** @return array{Scopes: array<string, array{ContentHash:string, PlainBytes:int}>} */
    private function preflight(): array
    {
        return ['Scopes' => [
            'schema' => ['ContentHash' => self::HASH_A, 'PlainBytes' => 100],
            'closedSets' => ['ContentHash' => self::HASH_B, 'PlainBytes' => 200],
        ]];
    }

    /** @return array{Tables: list<array{name:string, declaredRowCount:int, liveRowCount:int, declaredHash:string, liveHash:string}>} */
    private function domainDrift(): array
    {
        return ['Tables' => [
            ['name' => 'AuditEvents', 'declaredRowCount' => 5, 'liveRowCount' => 5, 'declaredHash' => self::HASH_C, 'liveHash' => self::HASH_C],
            ['name' => 'Licenses', 'declaredRowCount' => 3, 'liveRowCount' => 4, 'declaredHash' => self::HASH_D, 'liveHash' => self::HASH_E],
        ]];
    }

    public function test_plan_has_schema_and_closed_sets_verify_steps_first(): void
    {
        $plan = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $this->domainDrift(), self::REQ);

        self::assertSame(1, $plan['PlanVersion']);
        self::assertTrue($plan['ShadowOnly']);
        self::assertSame(self::ARCHIVE_ID, $plan['ArchiveId']);
        self::assertSame('schema', $plan['Steps'][0]['Scope']);
        self::assertSame(BrRestoreApplyPlanBuilder::OP_VERIFY, $plan['Steps'][0]['Op']);
        self::assertSame(self::HASH_A, $plan['Steps'][0]['ContentHash']);
        self::assertSame(100, $plan['Steps'][0]['DeclaredBytes']);
        self::assertSame('closedSets', $plan['Steps'][1]['Scope']);
        self::assertSame(self::HASH_B, $plan['Steps'][1]['ContentHash']);
        self::assertSame(0, $plan['Steps'][0]['Index']);
        self::assertSame(1, $plan['Steps'][1]['Index']);
    }

    public function test_domain_steps_use_replace_table_op_and_scope_domain_relpath(): void
    {
        $plan = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $this->domainDrift(), self::REQ);

        $t0 = $plan['Steps'][2];
        self::assertSame('domain', $t0['Scope']);
        self::assertSame(BrRestoreApplyPlanBuilder::OP_REPLACE_TABLE, $t0['Op']);
        self::assertSame('AuditEvents', $t0['Name']);
        self::assertSame('scope/domain/AuditEvents.jsonl.zst', $t0['RelPath']);
        self::assertSame(2, $t0['Index']);
        self::assertFalse($t0['Drift']);

        $t1 = $plan['Steps'][3];
        self::assertSame('Licenses', $t1['Name']);
        self::assertSame('scope/domain/Licenses.jsonl.zst', $t1['RelPath']);
        self::assertTrue($t1['Drift']);
    }

    public function test_totals_count_steps_tables_and_drifted_tables(): void
    {
        $plan = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $this->domainDrift(), self::REQ);
        self::assertSame(4, $plan['Totals']['StepCount']);
        self::assertSame(2, $plan['Totals']['TableCount']);
        self::assertSame(1, $plan['Totals']['DriftedTableCount']);
    }

    public function test_plan_id_is_deterministic_across_repeated_builds(): void
    {
        $a = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $this->domainDrift(), self::REQ);
        $b = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $this->domainDrift(), 'req-different');
        self::assertSame($a['PlanId'], $b['PlanId']);
        self::assertSame(64, strlen($a['PlanId']));
    }

    public function test_plan_id_matches_sha256_of_canonical_steps_json(): void
    {
        $plan = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $this->domainDrift(), self::REQ);
        $canon = $this->canonicalize($plan['Steps']);
        $json = (string) json_encode($canon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertSame(hash('sha256', $json), $plan['PlanId']);
    }

    public function test_plan_id_flips_when_any_declared_hash_changes(): void
    {
        $a = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $this->domainDrift(), self::REQ);
        $drift = $this->domainDrift();
        $drift['Tables'][0]['declaredHash'] = self::HASH_E;
        $b = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $drift, self::REQ);
        self::assertNotSame($a['PlanId'], $b['PlanId']);
    }

    public function test_missing_scope_slot_omits_verify_step_without_error(): void
    {
        $preflight = ['Scopes' => ['schema' => ['ContentHash' => self::HASH_A, 'PlainBytes' => 1]]];
        $plan = $this->builder()->build(self::ARCHIVE_ID, $preflight, ['Tables' => []], self::REQ);
        self::assertSame(1, $plan['Totals']['StepCount']);
        self::assertSame('schema', $plan['Steps'][0]['Scope']);
    }

    public function test_empty_domain_tables_yields_zero_tables(): void
    {
        $plan = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), ['Tables' => []], self::REQ);
        self::assertSame(2, $plan['Totals']['StepCount']);
        self::assertSame(0, $plan['Totals']['TableCount']);
        self::assertSame(0, $plan['Totals']['DriftedTableCount']);
    }

    public function test_row_count_mismatch_alone_marks_drift_true(): void
    {
        $drift = ['Tables' => [
            ['name' => 'X', 'declaredRowCount' => 1, 'liveRowCount' => 2, 'declaredHash' => self::HASH_C, 'liveHash' => self::HASH_C],
        ]];
        $plan = $this->builder()->build(self::ARCHIVE_ID, $this->preflight(), $drift, self::REQ);
        self::assertTrue($plan['Steps'][2]['Drift']);
        self::assertSame(1, $plan['Totals']['DriftedTableCount']);
    }

    /** @param mixed $v @return mixed */
    private function canonicalize(mixed $v): mixed
    {
        if (! is_array($v)) { return $v; }
        if (array_is_list($v)) {
            return array_map(fn ($x) => $this->canonicalize($x), $v);
        }
        ksort($v);

        return array_map(fn ($x) => $this->canonicalize($x), $v);
    }
}
