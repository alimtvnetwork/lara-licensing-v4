<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Domain\BR\BrExportScopeKey;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 26. Restore apply-plan builder.
 *
 * Read-only, deterministic planner. Consumes the outputs of
 * {@see BrImportPreflight::run()} and
 * {@see BrDomainDriftCheck::runForArchive()} and emits an ordered,
 * content-addressed list of steps that a future apply worker
 * (step 27) will execute inside per-shard advisory locks. INV-BR-RS-1
 * is preserved structurally: this class touches no DB connection,
 * no writer service, and no shard dispatcher.
 *
 * Plan shape (frozen at PlanVersion=1):
 *   {
 *     ArchiveId, PlanId, PlanVersion:1, ShadowOnly:true,
 *     Steps: [
 *       { Index, Scope:'schema'|'closedSets', Op:'VerifyContentHash',
 *         ContentHash, DeclaredBytes, Verified:true },
 *       { Index, Scope:'domain', Op:'ReplaceTable',
 *         Name, RelPath, DeclaredContentHash, DeclaredRowCount,
 *         LiveContentHash, LiveRowCount, Drift:bool }
 *     ],
 *     Totals: { StepCount, TableCount, DriftedTableCount }
 *   }
 *
 * `PlanId` is `sha256(canonicalJsonOfSteps)` where canonical JSON is
 * produced by recursive key-sort + `JSON_UNESCAPED_SLASHES` +
 * `JSON_UNESCAPED_UNICODE`. Determinism proven by
 * `BrRestoreApplyPlanBuilderContractTest`.
 *
 * 15-line function cap enforced by splitting into `build`, `steps`,
 * `schemaStep`, `closedSetsStep`, `domainSteps`, `totals`,
 * `planId`, `canonicalize`.
 */
final class BrRestoreApplyPlanBuilder
{
    public const PLAN_VERSION = 1;
    public const OP_VERIFY = 'VerifyContentHash';
    public const OP_REPLACE_TABLE = 'ReplaceTable';
    private const DOMAIN_REL_PATH_PREFIX = 'scope/domain/';
    private const DOMAIN_REL_PATH_SUFFIX = '.jsonl.zst';
    private const LOG_BUILT = 'br.restore.apply_plan.built';

    /**
     * @param  array{Scopes: array<string, array{ContentHash:string, PlainBytes:int}>}  $preflight
     * @param  array{Tables: list<array{name:string, declaredRowCount:int, liveRowCount:int, declaredHash:string, liveHash:string}>}  $domainDrift
     * @return array{ArchiveId:string, PlanId:string, PlanVersion:int, ShadowOnly:bool, Steps:list<array<string, mixed>>, Totals: array{StepCount:int, TableCount:int, DriftedTableCount:int}}
     */
    public function build(string $archiveId, array $preflight, array $domainDrift, string $requestId): array
    {
        $steps = $this->steps($preflight, $domainDrift);
        $totals = $this->totals($steps);
        $planId = $this->planId($steps);
        $plan = ['ArchiveId' => $archiveId, 'PlanId' => $planId, 'PlanVersion' => self::PLAN_VERSION, 'ShadowOnly' => true, 'Steps' => $steps, 'Totals' => $totals];
        Log::info(self::LOG_BUILT, ['ArchiveId' => $archiveId, 'PlanId' => $planId, 'StepCount' => $totals['StepCount'], 'TableCount' => $totals['TableCount'], 'DriftedTableCount' => $totals['DriftedTableCount'], 'RequestId' => $requestId]);

        return $plan;
    }

    /**
     * @param  array{Scopes: array<string, array{ContentHash:string, PlainBytes:int}>}  $preflight
     * @param  array{Tables: list<array{name:string, declaredRowCount:int, liveRowCount:int, declaredHash:string, liveHash:string}>}  $domainDrift
     * @return list<array<string, mixed>>
     */
    private function steps(array $preflight, array $domainDrift): array
    {
        $steps = [];
        $index = 0;
        foreach ($this->verifyScopes() as $key) {
            $step = $this->verifyStep($index, $key, $preflight['Scopes'] ?? []);
            if ($step !== null) { $steps[] = $step; $index++; }
        }
        foreach ($domainDrift['Tables'] as $t) {
            $steps[] = $this->domainStep($index, $t);
            $index++;
        }

        return $steps;
    }

    /** @return list<string> */
    private function verifyScopes(): array
    {
        return [BrExportScopeKey::Schema->value, BrExportScopeKey::ClosedSets->value];
    }

    /**
     * @param  array<string, array{ContentHash:string, PlainBytes:int}>  $scopes
     * @return array<string, mixed>|null
     */
    private function verifyStep(int $index, string $key, array $scopes): ?array
    {
        $slot = $scopes[$key] ?? null;
        if ($slot === null) {
            return null;
        }

        return ['Index' => $index, 'Scope' => $key, 'Op' => self::OP_VERIFY, 'ContentHash' => (string) $slot['ContentHash'], 'DeclaredBytes' => (int) $slot['PlainBytes'], 'Verified' => true];
    }

    /**
     * @param  array{name:string, declaredRowCount:int, liveRowCount:int, declaredHash:string, liveHash:string}  $t
     * @return array<string, mixed>
     */
    private function domainStep(int $index, array $t): array
    {
        $drift = $t['declaredHash'] !== $t['liveHash'] || $t['declaredRowCount'] !== $t['liveRowCount'];

        return ['Index' => $index, 'Scope' => BrExportScopeKey::Domain->value, 'Op' => self::OP_REPLACE_TABLE, 'Name' => $t['name'], 'RelPath' => self::DOMAIN_REL_PATH_PREFIX . $t['name'] . self::DOMAIN_REL_PATH_SUFFIX, 'DeclaredContentHash' => $t['declaredHash'], 'DeclaredRowCount' => $t['declaredRowCount'], 'LiveContentHash' => $t['liveHash'], 'LiveRowCount' => $t['liveRowCount'], 'Drift' => $drift];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{StepCount:int, TableCount:int, DriftedTableCount:int}
     */
    private function totals(array $steps): array
    {
        $tables = 0;
        $drifted = 0;
        foreach ($steps as $s) {
            if (($s['Op'] ?? '') !== self::OP_REPLACE_TABLE) { continue; }
            $tables++;
            if (($s['Drift'] ?? false) === true) { $drifted++; }
        }

        return ['StepCount' => count($steps), 'TableCount' => $tables, 'DriftedTableCount' => $drifted];
    }

    /** @param list<array<string, mixed>> $steps */
    private function planId(array $steps): string
    {
        $canon = $this->canonicalize($steps);
        $json = (string) json_encode($canon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }

    /** @param mixed $v @return mixed */
    private function canonicalize(mixed $v): mixed
    {
        if (is_array($v) === false) { return $v; }
        if (array_is_list($v)) {
            return array_map(fn ($x) => $this->canonicalize($x), $v);
        }
        ksort($v);

        return array_map(fn ($x) => $this->canonicalize($x), $v);
    }
}
