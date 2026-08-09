<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\ClosedSets\ClosedSetCatalogue;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Plan 14 step 15. SC-B "Closed-set tables" collector for the S1
 * shadow Export path.
 *
 * Normative sources:
 *  - spec/26-backup-restore/05-scope-catalog.md §"SC-B · Closed-set
 *    tables" (selector = the closed-set catalogue; manifest slot
 *    `manifest.scope.closedSets = {contentHash, setCount, valueCount}`).
 *  - spec/26-backup-restore/07-manifest-schema.md §"`scope` Shape".
 *  - spec/26-backup-restore/04-invariants.md INV-BR-MS-2 (real bytes
 *    behind every `scope.*.contentHash`), INV-BR-MS-3 (deterministic
 *    Merkle root across identical inputs).
 *
 * The physical `public.closed_sets` / `public.closed_set_values`
 * tables do not exist in the root schema. The BE authoritative
 * surface for closed sets lives in `App\Domain\ClosedSets\
 * ClosedSetCatalogue` (mirrors FE `src/lib/closed-sets.ts`). This
 * collector reads that catalogue and produces a canonical JSONL
 * body keyed by `(SetId asc, Ordinal asc)`.
 *
 * Failure model (strict, no swallowing):
 *  - Catalogue config missing/empty (`RuntimeException` from
 *    catalogue) => `BackupStorageFailure` (500) at
 *    `/scope/closedSets` with rule `ClosedSetCatalogueUnreadable`.
 *    Upstream `BrExportWorker::materializeArchive` aborts the
 *    reserved dir so INV-BR-A holds.
 *
 * 15-line function cap held by splitting into `renderJsonl` and
 * `countValues`.
 */
final class BrScopeClosedSetsCollector
{
    private const REL_PATH = 'scope/closed-sets.jsonl.zst';
    private const ERR_UNREADABLE = 'BackupStorageFailure';
    private const RULE_UNREADABLE = 'ClosedSetCatalogueUnreadable';
    private const LOG_COLLECTED = 'br.export.scope.closedSets.collected';
    private const LOG_UNREADABLE = 'br.export.scope.closedSets.unreadable';

    public function __construct(private readonly ClosedSetCatalogue $catalogue) {}

    /**
     * Collect SC-B rows and return the JSONL payload + manifest slot
     * fields (`setCount`, `valueCount`, `contentHash`).
     *
     * @return array{
     *   Jsonl: string,
     *   RelPath: string,
     *   ContentHash: string,
     *   SetCount: int,
     *   ValueCount: int
     * }
     */
    public function collect(string $requestId): array
    {
        $sets = $this->loadSets($requestId);
        $jsonl = $this->renderJsonl($sets);
        $contentHash = hash('sha256', $jsonl);
        $valueCount = $this->countValues($sets);
        Log::info(self::LOG_COLLECTED, ['SetCount' => count($sets), 'ValueCount' => $valueCount, 'ContentHash' => $contentHash, 'BodyBytes' => strlen($jsonl), 'RequestId' => $requestId]);

        return ['Jsonl' => $jsonl, 'RelPath' => self::REL_PATH, 'ContentHash' => $contentHash, 'SetCount' => count($sets), 'ValueCount' => $valueCount];
    }

    /**
     * @return list<array{SetId:string, Values:list<array{Ordinal:int, ValueKey:string}>}>
     */
    private function loadSets(string $requestId): array
    {
        try {
            return $this->catalogue->all();
        } catch (RuntimeException | Throwable $e) {
            Log::error(self::LOG_UNREADABLE, ['RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Closed-set catalogue unreadable at Export time.', [['Field' => '/scope/closedSets', 'Rule' => self::RULE_UNREADABLE]]);
        }
    }

    /**
     * Canonical JSONL: one row per (SetId, Ordinal), keys sorted
     * lexicographically (`Ordinal`, `SetId`, `ValueKey`), LF-terminated,
     * UTF-8. Sets emitted in ascending `SetId`; values in ascending
     * `Ordinal`.
     *
     * @param list<array{SetId:string, Values:list<array{Ordinal:int, ValueKey:string}>}> $sets
     */
    private function renderJsonl(array $sets): string
    {
        $out = '';
        foreach ($sets as $set) {
            foreach ($set['Values'] as $value) {
                $row = ['Ordinal' => $value['Ordinal'], 'SetId' => $set['SetId'], 'ValueKey' => $value['ValueKey']];
                $out .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            }
        }

        return $out;
    }

    /**
     * @param list<array{SetId:string, Values:list<array{Ordinal:int, ValueKey:string}>}> $sets
     */
    private function countValues(array $sets): int
    {
        $total = 0;
        foreach ($sets as $set) {
            $total += count($set['Values']);
        }

        return $total;
    }
}
