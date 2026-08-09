<?php

declare(strict_types=1);

namespace App\Services\BR;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 14 step 24. Read-only drift snapshot.
 *
 * Normative source: spec/26-backup-restore/12-restore-orchestration.md
 * v1.0.0 §"Drift Snapshot" + INV-BR-RS-1 ("preflight/drift never
 * mutate any table") + INV-BR-MS-2 ("every `scope.*.contentHash`
 * hashes the class's real bytes"). Consumes the report returned by
 * {@see BrImportPreflight::run} and re-runs every live scope
 * collector (SC-A..F, SC-H) to compare the archive's declared
 * `contentHash` against a freshly recomputed live hash.
 *
 * SC-G (secretsEnvelope) is skipped for live recomputation because
 * its bytes are AEAD-sealed with per-row nonces derived from the
 * ACTIVE KEK aadSecret at Export time; recomputing here would need
 * the archive's own KEK epoch material and would only prove what
 * preflight already proved (INV-BR-MS-2 via the archive body).
 * We surface `Skipped=true` with reason `SealedByEpoch` instead of
 * hiding it, keeping the report exhaustive.
 *
 * This service NEVER opens a DB transaction, NEVER writes anywhere,
 * and NEVER touches shard connections beyond the read-only SELECTs
 * the collectors already perform. All failure paths log
 * `br.drift.snapshot.rejected` with `RequestId`, `ArchiveId`, and
 * `Reason` and re-throw. Success logs `br.drift.snapshot.recorded`
 * with per-scope match booleans.
 *
 * 15-line function cap held via helper splits.
 */
final class BrDriftSnapshot
{
    private const LOG_OK = 'br.drift.snapshot.recorded';
    private const LOG_FAIL = 'br.drift.snapshot.rejected';
    private const REASON_SEALED = 'SealedByEpoch';

    public function __construct(
        private readonly BrScopeSchemaCollector $schema,
        private readonly BrScopeClosedSetsCollector $closedSets,
        private readonly BrScopeFeaturesCollector $features,
        private readonly BrScopeLicensesCollector $licenses,
        private readonly BrScopeRbacCollector $rbac,
        private readonly BrScopeDomainCollector $domain,
        private readonly BrScopeFilesCollector $files,
    ) {}

    /**
     * @param array{
     *   ArchiveId:string,
     *   Scopes: array<string, array{ContentHash:string, ActualHash:string, Ok:bool, PlainBytes:int}>
     * } $preflight
     *
     * @return array{
     *   ArchiveId:string,
     *   RequestId:string,
     *   AllMatch:bool,
     *   Scopes: array<string, array{Declared:string, Live:string, Match:bool, Skipped:bool, Reason?:string}>
     * }
     */
    public function run(array $preflight, string $requestId): array
    {
        $archiveId = (string) ($preflight['ArchiveId'] ?? '');
        try {
            $scopes = $this->compareAll($preflight['Scopes'] ?? [], $requestId);
        } catch (Throwable $e) {
            Log::error(self::LOG_FAIL, ['ArchiveId' => $archiveId, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw $e;
        }
        $allMatch = $this->allMatch($scopes);
        Log::info(self::LOG_OK, ['ArchiveId' => $archiveId, 'RequestId' => $requestId, 'AllMatch' => $allMatch, 'Scopes' => $this->flatten($scopes)]);

        return ['ArchiveId' => $archiveId, 'RequestId' => $requestId, 'AllMatch' => $allMatch, 'Scopes' => $scopes];
    }

    /**
     * @param  array<string, array{ContentHash:string, ActualHash:string, Ok:bool, PlainBytes:int}>  $preflightScopes
     * @return array<string, array{Declared:string, Live:string, Match:bool, Skipped:bool, Reason?:string}>
     */
    private function compareAll(array $preflightScopes, string $requestId): array
    {
        $live = $this->collectLive($requestId);
        $out = [];
        foreach ($preflightScopes as $key => $slot) {
            $out[$key] = $this->compareOne($key, (string) ($slot['ContentHash'] ?? ''), $live);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $live
     * @return array{Declared:string, Live:string, Match:bool, Skipped:bool, Reason?:string}
     */
    private function compareOne(string $key, string $declared, array $live): array
    {
        if (array_key_exists($key, $live) === false) {
            return ['Declared' => $declared, 'Live' => '', 'Match' => false, 'Skipped' => true, 'Reason' => self::REASON_SEALED];
        }
        $liveHash = $live[$key];

        return ['Declared' => $declared, 'Live' => $liveHash, 'Match' => hash_equals($declared, $liveHash), 'Skipped' => false];
    }

    /**
     * @return array<string, string>
     */
    private function collectLive(string $requestId): array
    {
        return [
            'schema'     => (string) $this->schema->collect($requestId)['ContentHash'],
            'closedSets' => (string) $this->closedSets->collect($requestId)['ContentHash'],
            'features'   => (string) $this->features->collect($requestId)['ContentHash'],
            'licenses'   => (string) $this->licenses->collect($requestId)['ContentHash'],
            'rbac'       => (string) $this->rbac->collect($requestId)['ContentHash'],
            'domain'     => (string) $this->domain->collect($requestId)['ContentHash'],
            'files'      => (string) $this->files->collect($requestId)['ContentHash'],
        ];
    }

    /**
     * @param  array<string, array{Match:bool, Skipped:bool}>  $scopes
     */
    private function allMatch(array $scopes): bool
    {
        foreach ($scopes as $slot) {
            if ($slot['Skipped']) {
                continue;
            }
            if (! $slot['Match']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array{Match:bool, Skipped:bool}>  $scopes
     * @return array<string, string>
     */
    private function flatten(array $scopes): array
    {
        $flat = [];
        foreach ($scopes as $key => $slot) {
            $flat[$key] = $slot['Skipped'] ? 'skipped' : ($slot['Match'] ? 'match' : 'drift');
        }

        return $flat;
    }
}
