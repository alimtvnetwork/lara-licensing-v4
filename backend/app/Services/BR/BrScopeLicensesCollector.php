<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 14 step 17. SC-D "License artifacts" collector for the S1 shadow
 * Export path.
 *
 * Normative sources:
 *  - spec/26-backup-restore/05-scope-catalog.md §"SC-D License artifacts"
 *    (selector = `SELECT * FROM public.licenses`,
 *    `SELECT * FROM public.license_features`,
 *    `SELECT * FROM public.license_epochs` (all epochs, not just current),
 *    whole scope, restore rank 4).
 *  - spec/26-backup-restore/07-manifest-schema.md §"`scope` Shape"
 *    (manifest slot
 *    `manifest.scope.licenses = {contentHash, licenseCount, epochCount,
 *    featureLinkCount}`).
 *  - spec/26-backup-restore/04-invariants.md `INV-BR-MS-2` (every
 *    `scope.*.contentHash` hashes the class's real bytes; empty
 *    placeholders are a validator violation once real content ships).
 *
 * Spec-to-app mapping (mirrors SC-C's `Features` != spec `features`
 * translation):
 *  - `public.licenses`         => shard-side `Licenses` per Plan 06 step 15
 *    (`spec/23-app-db/10-reseller-shard-split-db.md` puts license rows in
 *    the reseller's shard, not Root).
 *  - `public.license_features` => shard-side `LicenseFeatures` per Plan 06
 *    step 19 (spec/21-app/45-license-features.md, per-license overrides).
 *  - `public.license_epochs`   => shard-side `LicenseLedger` per Plan 06
 *    step 17 (spec/23-app-db/01-schema.md §ResellerQuotaLedger). LARA has
 *    no `license_epochs` table; the append-only `LicenseLedger` is the
 *    canonical identity-linked audit trail for license mutations
 *    (`QuotaConsumed` / `QuotaRestored` / `QuotaAdjusted`) and is what
 *    a Restore preflight must diff to detect epoch drift.
 *
 * Portability: rows are keyed by natural, cross-DB-stable values, never
 * by shard-local surrogate ids:
 *  - `LicenseKey`   (globally unique per spec 21 §Prefix registry),
 *  - `ResellerSlug` (Root-scope UK per Plan 06 migration 0002).
 * A Restore into a re-provisioned shard can therefore rebuild the
 * relations without needing the source's `LicenseId` sequence.
 *
 * Iteration model: enumerate `Resellers` rows ordered by `ResellerId`
 * ascending, bind each shard once via `ShardResolver::bind($slug)`, and
 * stream the three tables per shard. Non-`Active` shard routes are
 * skipped WITH a log record so `Provisioning` / `Failed` / `Quiesced`
 * shards do not fail the whole Export; excluding them from the archive
 * matches spec 26 §"Restore ordering" (only `Active` shards participate
 * in the identity-linked scope).
 *
 * Failure model (strict, no swallowing):
 *  - Root `Resellers` unreadable => `BackupStorageFailure` (500) at
 *    `/scope/licenses` with rule `LicenseCatalogUnreadable`.
 *  - A shard read throws => `BackupStorageFailure` (500) at
 *    `/scope/licenses/reseller/<slug>` rule `ShardUnreadable`.
 *  - A `LicenseFeatures` row references a `LicenseId` absent from the
 *    per-shard license snapshot => `BackupCorrupt` (422) at
 *    `/scope/licenses/reseller/<slug>/features/<licenseId>` rule
 *    `LicenseFeatureLicenseIdUnknown` (mirrors SC-C's cross-table
 *    surrogate-FK guard).
 *  - A `LicenseLedger` row references a `LicenseId` absent from the
 *    per-shard snapshot (only enforced when non-null: `QuotaAdjusted`
 *    rows are license-free) => `BackupCorrupt` (422) rule
 *    `LicenseLedgerLicenseIdUnknown`.
 *
 * 15-line function cap held by splitting into `loadResellers`,
 * `readShardLicenses`, `readShardLicenseFeatures`, `readShardLedger`,
 * `renderJsonl`, and small helpers.
 */
final class BrScopeLicensesCollector
{
    private const CONN_ROOT = 'root';
    private const TABLE_RESELLERS = 'Resellers';
    private const TABLE_ROUTES = 'ResellerShardRoutes';
    private const TABLE_LICENSES = 'Licenses';
    private const TABLE_LICENSE_FEATURES = 'LicenseFeatures';
    private const TABLE_LEDGER = 'LicenseLedger';
    private const REL_PATH = 'scope/licenses.jsonl.zst';

    private const SHARD_STATUS_ACTIVE = 'Active';

    private const ROW_TYPE_LICENSE = 'License';
    private const ROW_TYPE_FEATURE = 'LicenseFeature';
    private const ROW_TYPE_EPOCH = 'LicenseEpoch';

    private const ERR_UNREADABLE = 'BackupStorageFailure';
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const RULE_CATALOG_UNREADABLE = 'LicenseCatalogUnreadable';
    private const RULE_SHARD_UNREADABLE = 'ShardUnreadable';
    private const RULE_FEATURE_UNKNOWN_LICENSE = 'LicenseFeatureLicenseIdUnknown';
    private const RULE_LEDGER_UNKNOWN_LICENSE = 'LicenseLedgerLicenseIdUnknown';

    private const LOG_COLLECTED = 'br.export.scope.licenses.collected';
    private const LOG_CATALOG_UNREADABLE = 'br.export.scope.licenses.catalog_unreadable';
    private const LOG_SHARD_UNREADABLE = 'br.export.scope.licenses.shard_unreadable';
    private const LOG_SHARD_SKIPPED = 'br.export.scope.licenses.shard_skipped';
    private const LOG_UNKNOWN_LICENSE = 'br.export.scope.licenses.unknown_license_id';

    public function __construct(
        private readonly ShardResolver $shards,
    ) {}

    /**
     * Collect SC-D rows and return the JSONL payload + manifest slot
     * fields (`licenseCount`, `epochCount`, `featureLinkCount`, `contentHash`).
     *
     * @return array{
     *   Jsonl: string,
     *   RelPath: string,
     *   ContentHash: string,
     *   LicenseCount: int,
     *   EpochCount: int,
     *   FeatureLinkCount: int
     * }
     */
    public function collect(string $requestId): array
    {
        $resellers = $this->loadResellers($requestId);
        [$licenseRows, $featureRows, $ledgerRows] = $this->collectAllShards($resellers, $requestId);
        $jsonl = $this->renderJsonl($licenseRows, $featureRows, $ledgerRows);
        $contentHash = hash('sha256', $jsonl);
        Log::info(self::LOG_COLLECTED, ['ResellerCount' => count($resellers), 'LicenseCount' => count($licenseRows), 'FeatureLinkCount' => count($featureRows), 'EpochCount' => count($ledgerRows), 'ContentHash' => $contentHash, 'BodyBytes' => strlen($jsonl), 'RequestId' => $requestId]);

        return ['Jsonl' => $jsonl, 'RelPath' => self::REL_PATH, 'ContentHash' => $contentHash, 'LicenseCount' => count($licenseRows), 'EpochCount' => count($ledgerRows), 'FeatureLinkCount' => count($featureRows)];
    }

    /**
     * @return list<array{ResellerId:int, ResellerSlug:string, ShardStatus:string}>
     */
    private function loadResellers(string $requestId): array
    {
        try {
            $rows = DB::connection(self::CONN_ROOT)->table(self::TABLE_RESELLERS)
                ->leftJoin(self::TABLE_ROUTES, self::TABLE_RESELLERS . '.ResellerId', '=', self::TABLE_ROUTES . '.ResellerId')
                ->whereNull(self::TABLE_RESELLERS . '.DeletedAt')
                ->orderBy(self::TABLE_RESELLERS . '.ResellerId')
                ->get([self::TABLE_RESELLERS . '.ResellerId', self::TABLE_RESELLERS . '.ResellerSlug', self::TABLE_ROUTES . '.ShardStatus']);
        } catch (Throwable $e) {
            Log::error(self::LOG_CATALOG_UNREADABLE, ['RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Root Resellers/ResellerShardRoutes tables unreadable at Export time.', [['Field' => '/scope/licenses', 'Rule' => self::RULE_CATALOG_UNREADABLE]]);
        }

        return $this->mapResellers($rows->all());
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{ResellerId:int, ResellerSlug:string, ShardStatus:string}>
     */
    private function mapResellers(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ResellerId'   => (int) $row->ResellerId,
                'ResellerSlug' => (string) $row->ResellerSlug,
                'ShardStatus'  => (string) ($row->ShardStatus ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{ResellerId:int, ResellerSlug:string, ShardStatus:string}>  $resellers
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function collectAllShards(array $resellers, string $requestId): array
    {
        $licenseRows = [];
        $featureRows = [];
        $ledgerRows = [];
        foreach ($resellers as $r) {
            if ($r['ShardStatus'] !== self::SHARD_STATUS_ACTIVE) {
                Log::info(self::LOG_SHARD_SKIPPED, ['ResellerSlug' => $r['ResellerSlug'], 'ShardStatus' => $r['ShardStatus'], 'RequestId' => $requestId]);
                continue;
            }
            $this->shards->bind($r['ResellerSlug']);
            [$L, $F, $E] = $this->readOneShard($r['ResellerSlug'], $requestId);
            array_push($licenseRows, ...$L);
            array_push($featureRows, ...$F);
            array_push($ledgerRows, ...$E);
        }

        return [$licenseRows, $featureRows, $ledgerRows];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function readOneShard(string $slug, string $requestId): array
    {
        $licenses = $this->readShardLicenses($slug, $requestId);
        $keyIndex = $this->indexLicenses($licenses);
        $features = $this->readShardLicenseFeatures($slug, $keyIndex, $requestId);
        $ledger = $this->readShardLedger($slug, $keyIndex, $requestId);

        return [$licenses, $features, $ledger];
    }

    /** @return list<array<string, mixed>> */
    private function readShardLicenses(string $slug, string $requestId): array
    {
        try {
            $rows = DB::connection(ShardResolver::alias())->table(self::TABLE_LICENSES)
                ->orderBy('LicenseKey')
                ->get(['LicenseId', 'LicenseKey', 'PrefixValue', 'ResellerId', 'TierName', 'EnvironmentName', 'ProductVersion', 'Status', 'IssuedByUserId', 'IssuedAt', 'ExpiresAt', 'Version', 'RevokedAt', 'RevokedByUserId', 'RevokeReason', 'IssuerActorType']);
        } catch (Throwable $e) {
            Log::error(self::LOG_SHARD_UNREADABLE, ['ResellerSlug' => $slug, 'Table' => self::TABLE_LICENSES, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Shard Licenses table unreadable at Export time.', [['Field' => '/scope/licenses/reseller/' . $slug, 'Rule' => self::RULE_SHARD_UNREADABLE]]);
        }

        return $this->mapLicenseRows($rows->all(), $slug);
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapLicenseRows(array $rows, string $slug): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ResellerSlug'    => $slug,
                'LicenseKey'      => (string) $r->LicenseKey,
                'PrefixValue'     => (string) $r->PrefixValue,
                'TierName'        => (string) $r->TierName,
                'EnvironmentName' => (string) $r->EnvironmentName,
                'ProductVersion'  => (string) $r->ProductVersion,
                'Status'          => (string) $r->Status,
                'IssuerActorType' => $r->IssuerActorType === null ? null : (string) $r->IssuerActorType,
                'IssuedByUserId'  => (int) $r->IssuedByUserId,
                'IssuedAt'        => (string) $r->IssuedAt,
                'ExpiresAt'       => $r->ExpiresAt === null ? null : (string) $r->ExpiresAt,
                'RevokedAt'       => $r->RevokedAt === null ? null : (string) $r->RevokedAt,
                'RevokedByUserId' => $r->RevokedByUserId === null ? null : (int) $r->RevokedByUserId,
                'RevokeReason'    => $r->RevokeReason === null ? null : (string) $r->RevokeReason,
                'Version'         => (int) $r->Version,
                '_LicenseId'      => (int) $r->LicenseId, // stripped before render
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $licenses
     * @return array<int, string> LicenseId => LicenseKey
     */
    private function indexLicenses(array $licenses): array
    {
        $out = [];
        foreach ($licenses as $l) {
            $out[(int) $l['_LicenseId']] = (string) $l['LicenseKey'];
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $keyIndex
     * @return list<array<string, mixed>>
     */
    private function readShardLicenseFeatures(string $slug, array $keyIndex, string $requestId): array
    {
        try {
            $rows = DB::connection(ShardResolver::alias())->table(self::TABLE_LICENSE_FEATURES)
                ->orderBy('LicenseId')->orderBy('FeatureId')
                ->get(['LicenseId', 'FeatureId', 'Value', 'CreatedByUserId', 'CreatedAt']);
        } catch (Throwable $e) {
            Log::error(self::LOG_SHARD_UNREADABLE, ['ResellerSlug' => $slug, 'Table' => self::TABLE_LICENSE_FEATURES, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Shard LicenseFeatures table unreadable at Export time.', [['Field' => '/scope/licenses/reseller/' . $slug, 'Rule' => self::RULE_SHARD_UNREADABLE]]);
        }

        return $this->mapFeatureRows($rows->all(), $slug, $keyIndex, $requestId);
    }

    /**
     * @param  list<object>  $rows
     * @param  array<int, string>  $keyIndex
     * @return list<array<string, mixed>>
     */
    private function mapFeatureRows(array $rows, string $slug, array $keyIndex, string $requestId): array
    {
        $out = [];
        foreach ($rows as $r) {
            $licenseId = (int) $r->LicenseId;
            $key = $this->resolveLicenseKey($licenseId, $keyIndex, $slug, $requestId, self::RULE_FEATURE_UNKNOWN_LICENSE, 'features');
            $out[] = ['ResellerSlug' => $slug, 'LicenseKey' => $key, 'FeatureId' => (int) $r->FeatureId, 'Value' => json_decode((string) $r->Value, true, 512, JSON_THROW_ON_ERROR), 'CreatedByUserId' => (int) $r->CreatedByUserId, 'CreatedAt' => (string) $r->CreatedAt];
        }
        usort($out, static fn ($a, $b) => [$a['LicenseKey'], $a['FeatureId']] <=> [$b['LicenseKey'], $b['FeatureId']]);

        return $out;
    }

    /**
     * @param  array<int, string>  $keyIndex
     * @return list<array<string, mixed>>
     */
    private function readShardLedger(string $slug, array $keyIndex, string $requestId): array
    {
        try {
            $rows = DB::connection(ShardResolver::alias())->table(self::TABLE_LEDGER)
                ->orderBy('LicenseLedgerId')
                ->get(['LicenseLedgerId', 'ResellerId', 'TierName', 'LedgerAction', 'Delta', 'LicenseId', 'QuotaRequestId', 'RequestId', 'ActorUserId', 'CreatedAt']);
        } catch (Throwable $e) {
            Log::error(self::LOG_SHARD_UNREADABLE, ['ResellerSlug' => $slug, 'Table' => self::TABLE_LEDGER, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Shard LicenseLedger table unreadable at Export time.', [['Field' => '/scope/licenses/reseller/' . $slug, 'Rule' => self::RULE_SHARD_UNREADABLE]]);
        }

        return $this->mapLedgerRows($rows->all(), $slug, $keyIndex, $requestId);
    }

    /**
     * @param  list<object>  $rows
     * @param  array<int, string>  $keyIndex
     * @return list<array<string, mixed>>
     */
    private function mapLedgerRows(array $rows, string $slug, array $keyIndex, string $requestId): array
    {
        $out = [];
        foreach ($rows as $r) {
            $licenseKey = null;
            if ($r->LicenseId !== null) {
                $licenseKey = $this->resolveLicenseKey((int) $r->LicenseId, $keyIndex, $slug, $requestId, self::RULE_LEDGER_UNKNOWN_LICENSE, 'epochs');
            }
            $out[] = ['ResellerSlug' => $slug, 'TierName' => (string) $r->TierName, 'LedgerAction' => (string) $r->LedgerAction, 'Delta' => (int) $r->Delta, 'LicenseKey' => $licenseKey, 'QuotaRequestId' => $r->QuotaRequestId === null ? null : (int) $r->QuotaRequestId, 'RequestId' => (string) $r->RequestId, 'ActorUserId' => (int) $r->ActorUserId, 'CreatedAt' => (string) $r->CreatedAt];
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $keyIndex
     */
    private function resolveLicenseKey(int $licenseId, array $keyIndex, string $slug, string $requestId, string $rule, string $segment): string
    {
        if (isset($keyIndex[$licenseId]) === false) {
            Log::error(self::LOG_UNKNOWN_LICENSE, ['ResellerSlug' => $slug, 'LicenseId' => $licenseId, 'Rule' => $rule, 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_CORRUPT, 'License-linked row references a LicenseId absent from the shard snapshot.', [['Field' => '/scope/licenses/reseller/' . $slug . '/' . $segment . '/' . $licenseId, 'Rule' => $rule]]);
        }

        return $keyIndex[$licenseId];
    }

    /**
     * Canonical JSONL: License rows (ascending ResellerSlug, LicenseKey),
     * then LicenseFeature rows (same order + FeatureId), then LicenseEpoch
     * rows (ascending ResellerSlug, then insertion order from
     * LicenseLedgerId which is chronological per spec 23). Keys sorted
     * lexicographically per row via json_encode of a pre-sorted array.
     *
     * @param  list<array<string, mixed>>  $licenses
     * @param  list<array<string, mixed>>  $features
     * @param  list<array<string, mixed>>  $ledger
     */
    private function renderJsonl(array $licenses, array $features, array $ledger): string
    {
        $licensesSorted = $this->sortLicenses($licenses);
        $out = '';
        foreach ($licensesSorted as $l) {
            unset($l['_LicenseId']);
            $l['RowType'] = self::ROW_TYPE_LICENSE;
            $out .= $this->encodeCanonical($l);
        }
        foreach ($features as $f) {
            $f['RowType'] = self::ROW_TYPE_FEATURE;
            $out .= $this->encodeCanonical($f);
        }
        foreach ($ledger as $e) {
            $e['RowType'] = self::ROW_TYPE_EPOCH;
            $out .= $this->encodeCanonical($e);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $licenses
     * @return list<array<string, mixed>>
     */
    private function sortLicenses(array $licenses): array
    {
        usort($licenses, static fn ($a, $b) => [$a['ResellerSlug'], $a['LicenseKey']] <=> [$b['ResellerSlug'], $b['LicenseKey']]);

        return $licenses;
    }

    /** @param array<string, mixed> $row */
    private function encodeCanonical(array $row): string
    {
        ksort($row);

        return json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
