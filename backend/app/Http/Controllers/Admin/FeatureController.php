<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 70. Read-only Feature catalog + TierFeatures matrix.
 *
 * Normative sources: spec/21-app/45-license-features.md v1.0.0 §2 (closed
 * FeatureKey registry, typed value domains) and §4 (precedence
 * `LicenseFeatures > TierFeatures`; absence of a key means NOT licensed, so
 * a missing matrix cell MUST render as "not set" and never as a synthesized
 * default), plus spec/21-app/43-license-tiers.md §2 (tier ordinals 1..4).
 *
 * The tier axis is read from Root `LicenseTiers`, NOT from
 * config('lara.license_tiers'): the config array stops at Tier3 while the
 * table's `CkLicenseTiersMemberSet` admits `Unlimited` (ordinal 4). Rendering
 * from config would silently hide every TierFeatures row curated for
 * Unlimited. Server truth is the table.
 *
 * Cross-DB FKs are forbidden (spec/23-app-db/10 §App-tier) but all three
 * tables here (`Features`, `LicenseTiers`, `TierFeatures`) live in Root, so
 * this is a single-connection join, no fanout, no shard binding.
 *
 * Read-only by design: spec/24-app-ui-design-system/38 §"Anti-patterns" bans
 * optimistic Switch toggles, and no PATCH endpoint for TierFeatures exists
 * yet, so this controller exposes no mutation. Do not add one here without
 * the `If-Match: <FeatureEtag>` + `Idempotency-Key` contract from §"Mutations".
 */
final class FeatureController
{
    private const ROOT_CONNECTION = 'root';
    private const FEATURES_TABLE = 'Features';
    private const TIERS_TABLE = 'LicenseTiers';
    private const TIER_FEATURES_TABLE = 'TierFeatures';

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="FeatureController index",
     *     tags={"FeatureController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(Request $request): JsonResponse
    {
        $tiers = DB::connection(self::ROOT_CONNECTION)
            ->table(self::TIERS_TABLE)
            ->orderBy('TierOrdinal')
            ->get(['LicenseTierId', 'TierName', 'TierOrdinal']);

        $features = DB::connection(self::ROOT_CONNECTION)
            ->table(self::FEATURES_TABLE)
            ->orderBy('FeatureKey')
            ->get(['FeatureId', 'FeatureKey', 'ValueType']);

        $assignments = DB::connection(self::ROOT_CONNECTION)
            ->table(self::TIER_FEATURES_TABLE)
            ->orderBy('FeatureId')
            ->orderBy('LicenseTierId')
            ->get(['FeatureId', 'LicenseTierId', 'Value', 'UpdatedAt']);

        // Index assignments by FeatureId|LicenseTierId. `Value` is JSONB and
        // arrives as a JSON scalar string, so it is decoded once here rather
        // than in the browser, keeping the wire payload typed.
        $cells = [];
        foreach ($assignments as $row) {
            $key = (int) $row->FeatureId . '|' . (int) $row->LicenseTierId;
            $cells[$key] = [
                'Value' => json_decode((string) $row->Value, true),
                'UpdatedAt' => $row->UpdatedAt === null ? null : (string) $row->UpdatedAt,
            ];
        }

        $tierAxis = $tiers->map(static fn ($t): array => [
            'LicenseTierId' => (int) $t->LicenseTierId,
            'TierName' => (string) $t->TierName,
            'TierOrdinal' => (int) $t->TierOrdinal,
        ])->all();

        $results = $features->map(static function ($f) use ($tierAxis, $cells): array {
            $featureId = (int) $f->FeatureId;
            $row = [];
            foreach ($tierAxis as $tier) {
                $key = $featureId . '|' . $tier['LicenseTierId'];
                // Absent cell stays null: spec 45 §4 AC-FEAT-004 forbids
                // synthesizing a default for an unassigned tier.
                $row[(string) $tier['LicenseTierId']] = $cells[$key] ?? null;
            }

            return [
                'FeatureId' => $featureId,
                'FeatureKey' => (string) $f->FeatureKey,
                'ValueType' => (string) $f->ValueType,
                'Cells' => $row,
                'AssignedTierCount' => count(array_filter($row, static fn ($cell): bool => $cell !== null)),
            ];
        })->all();

        return ApiEnvelope::success(
            results: $results,
            requestId: (string) ($request->headers->get('X-Request-Id') ?? ''),
            extraAttributes: ['Tiers' => $tierAxis],
        );
    }
}
