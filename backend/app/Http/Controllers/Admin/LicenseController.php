<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Admin\LicenseIssueRequest;
use App\Http\Requests\Admin\LicenseRevokeRequest;
use App\Http\Requests\Admin\LicenseUpdateRequest;
use App\Models\License;
use App\Models\Prefix;
use App\Models\Reseller;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use App\Support\FeatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Throwable;

/**
 * Plan 06 step 31. Admin surface for License issuance.
 *
 * `POST /Api/Admin/Licenses` mints a new license on the reseller's
 * shard. Idempotency is enforced by the global `IdempotencyKeyMiddleware`
 * (prefix `api/admin/licenses` is in its required list). Closed-set
 * guards for `TierName`, `EnvironmentName`, and `Features` follow
 * spec/21-app/43-license-tiers.md, spec/21-app/44-environments.md,
 * and spec/21-app/45-license-features.md. Feature persistence to the
 * shard `LicenseFeatures` table is deferred to Plan 06 step 41
 * (`FeatureService`); this endpoint validates and echoes the features
 * back so admin flows can proceed without silently accepting invalid
 * payloads.
 *
 * The insert + ledger write run in a single shard transaction so the
 * spec 48 invariant `SUM(Delta) = LicensesGranted - LicensesConsumed`
 * cannot be broken by a partial failure.
 */
final class LicenseController
{
    private const SHARD_CONNECTION = 'shard';
    private const LICENSE_KEY_BYTES = 8;
    private const LICENSE_KEY_SEPARATOR = '-';
    private const DEFAULT_PRODUCT_VERSION = 'V1';
    private const DEFAULT_CATEGORY_NAME = 'Key';
    private const STATUS_ACTIVE = 'Active';
    private const STATUS_SUSPENDED = 'Suspended';
    private const STATUS_REVOKED = 'Revoked';
    private const LEDGER_ACTION_RESTORED = 'QuotaRestored';
    private const LEDGER_TABLE = 'LicenseLedger';
    private const ISSUER_ACTOR_ADMIN = 'Admin';
    private const ISSUER_ACTOR_RESELLER = 'Reseller';
    private const RESTORE_SKIP_ADMIN_ISSUED = 'AdminIssued';
    private const RESTORE_SKIP_ALREADY_RESTORED = 'AlreadyRestored';
    private const REVOKE_REASON_MAX = 512;


    public function __construct(
        private readonly ShardResolver $shardResolver,
        private readonly \App\Services\QuotaService $quotaService,
        private readonly \App\Services\LicenseLedgerService $ledgerService,
        private readonly \App\Services\FeatureService $featureService,
    ) {
    }


        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="LicenseController issue",
     *     tags={"LicenseController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function issue(LicenseIssueRequest $request): JsonResponse
    {
        // Preflight (spec 45 §2 + Plan 06 step 41): Root Features catalog
        // must contain every registry key BEFORE any tenant lookup, so a
        // fresh/unseeded environment surfaces `FeatureCatalogUnseeded`
        // rather than being masked by a downstream `ResellerNotFound` /
        // `PrefixForbidden` from a partial fixture.
        $this->featureService->assertCatalogSeeded();
        $data = $request->payload();
        $reseller = $this->requireReseller($data['ResellerSlug']);
        $this->requirePrefixOwnedByReseller($reseller->ResellerId, $data['PrefixValue']);
        $features = FeatureValidator::normalize($data['Features'] ?? []);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $license = $this->mintOnShard($request, $reseller, $data);
        AuditWriter::write($request, 'LicenseIssued', 'Licenses', (int) $license->LicenseId, [
            'LicenseKey' => (string) $license->LicenseKey,
            'ResellerSlug' => (string) $reseller->ResellerSlug,
            'TierName' => (string) $license->TierName,
            'EnvironmentName' => (string) $license->EnvironmentName,
            'IssuerActor' => self::ISSUER_ACTOR_ADMIN,
        ]);

        return ApiEnvelope::success(
            results: [$this->project($license, $features)],
            requestId: $this->requestId($request),
            httpCode: 201,
            message: 'Created',
        );
    }


    // Inline validation removed: Plan 10 step 2 (phase B) moved payload
    // validation into typed FormRequests under App\Http\Requests\Admin
    // (LicenseIssueRequest, LicenseUpdateRequest, LicenseRevokeRequest).
    // Their failedValidation() hooks throw the same
    // LaraException('ValidationFailed', ...) envelope so wire behaviour
    // is unchanged.


    private function requireReseller(string $slug): Reseller
    {
        $row = Reseller::query()->where('ResellerSlug', $slug)->first();
        if ($row === null) {
            throw NotFoundException::notFound('ResellerNotFound',
                'Reseller not found for the requested slug.',
                [['Field' => 'ResellerSlug', 'Rule' => 'NotFound', 'Value' => $slug]],
            );
        }

        return $row;
    }

    private function requirePrefixOwnedByReseller(int $resellerId, string $prefixValue): void
    {
        $row = Prefix::query()->where('PrefixValue', $prefixValue)->first();
        if ($row === null) {
            throw NotFoundException::notFound('PrefixNotFound',
                'Prefix is not registered.',
                [['Field' => 'PrefixValue', 'Rule' => 'NotFound', 'Value' => $prefixValue]],
            );
        }
        if ((int) $row->ResellerId !== $resellerId) {
            throw DomainConflictException::conflict('PrefixForbidden',
                'Prefix does not belong to the requested reseller.',
                [['Field' => 'PrefixValue', 'Rule' => 'OwnershipMismatch', 'Value' => $prefixValue]],
            );
        }
        if ($row->IsActive === false) {
            throw DomainConflictException::conflict('PrefixForbidden',
                'Prefix is disabled and cannot mint new licenses.',
                [['Field' => 'PrefixValue', 'Rule' => 'Inactive', 'Value' => $prefixValue]],
            );
        }
    }

    /**
     * @param array{PrefixValue:string, TierName:string, EnvironmentName:string, ExpiresAt?:string} $data
     */
    private function mintOnShard(Request $request, Reseller $reseller, array $data): License
    {
        $issuerId = $this->requireIssuerId($request);
        $now = Carbon::now();
        $licenseKey = $this->mintLicenseKey($data['PrefixValue']);
        // Spec 48 §1.4 + §41.4 step 4: admin-issued licenses skip the
        // ledger entirely. No `QuotaConsumed` row is written and
        // `ResellerQuotaLedgerId` stays NULL so revoke reports
        // `RestoreSkippedReason = AdminIssued`.
        return DB::connection(self::SHARD_CONNECTION)->transaction(function () use ($reseller, $data, $issuerId, $now, $licenseKey): License {
            return $this->insertLicense($reseller, $data, $issuerId, $now, $licenseKey);
        });
    }


    /**
     * @param array{TierName:string, EnvironmentName:string, ExpiresAt?:string} $data
     */
    private function insertLicense(Reseller $reseller, array $data, int $issuerId, Carbon $now, string $licenseKey): License
    {
        $license = new License();
        $license->LicenseKey = $licenseKey;
        $license->PrefixValue = $this->prefixFromKey($licenseKey);
        $license->ResellerId = $reseller->ResellerId;
        $license->IssuedByUserId = $issuerId;
        $license->IssuerActorType = self::ISSUER_ACTOR_ADMIN;
        $license->LicenseCategoryId = $this->resolveCategoryId($data);
        $license->TierName = $data['TierName'];
        $license->EnvironmentName = $data['EnvironmentName'];
        $license->ProductVersion = self::DEFAULT_PRODUCT_VERSION;
        $license->Status = self::STATUS_ACTIVE;
        $license->IssuedAt = $now;
        $license->ExpiresAt = isset($data['ExpiresAt']) ? Carbon::parse($data['ExpiresAt']) : null;
        $license->Version = 1;
        // ResellerQuotaLedgerId stays NULL for admin-issued rows (spec 48 §1.4).
        $license->ResellerQuotaLedgerId = null;
        $license->save();

        return $license;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveCategoryId(array $data): int
    {
        $categories = (array) config('lara.license_categories', []);
        $name = isset($data['LicenseCategory']) ? (string) $data['LicenseCategory'] : self::DEFAULT_CATEGORY_NAME;
        if (array_key_exists($name, $categories) === false) {
            throw ValidationException::validationFailed(
                'LicenseCategory is not in the closed set.',
                [['Field' => 'LicenseCategory', 'Rule' => 'MembershipRequired', 'Value' => $name]],
            );
        }

        return (int) $categories[$name];
    }


    private function mintLicenseKey(string $prefixValue): string
    {
        $random = strtoupper(bin2hex(random_bytes(self::LICENSE_KEY_BYTES)));

        return $prefixValue . self::LICENSE_KEY_SEPARATOR . $random;
    }

    private function prefixFromKey(string $licenseKey): string
    {
        $pos = strpos($licenseKey, self::LICENSE_KEY_SEPARATOR);

        return $pos === false ? $licenseKey : substr($licenseKey, 0, $pos);
    }

    private function requireIssuerId(Request $request): int
    {
        $user = $request->user();
        $id = $user?->getAuthIdentifier();
        if ($id === null || !is_numeric($id)) {
            throw AuthException::invalidCredentials(
                'Authenticated user identifier is missing.',
                [['Field' => 'IssuedByUserId', 'Rule' => 'Missing']],
            );
        }

        return (int) $id;
    }

    /**
     * @param array<string, bool|int|float|string> $features
     * @return array<string, mixed>
     */
    private function project(License $license, array $features): array
    {
        // Plan 10 step 4 wiring: delegate wire shape to LicenseResource so
        // the PascalCase envelope + Version (ETag) live in one place. The
        // Resource mirrors this method's prior output byte-for-byte and
        // uses `additional['Features']` for the shard LicenseFeatures join.
        return (new \App\Http\Resources\LicenseResource($license))
            ->additional(['Features' => $features])
            ->resolve();
    }

    private function isoOrEmpty(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d\TH:i:s\Z');
        }

        return (string) $v;
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }

    /**
     * Plan 06 step 33. `GET /Api/Admin/Licenses/{LicenseKey}?ResellerSlug=...`.
     *
     * ResellerSlug is required so we know which shard to bind; the
     * License row lives on the shard, not Root. Emits the same
     * projection as `issue()` so the client can cache the ETag.
     */
        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="LicenseController show",
     *     tags={"LicenseController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function show(Request $request, string $licenseKey): JsonResponse
    {
        $slug = $this->requireResellerSlug($request);
        $reseller = $this->requireReseller($slug);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $license = $this->requireLicense($licenseKey);

        return ApiEnvelope::success(
            results: [$this->project($license, [])],
            requestId: $this->requestId($request),
            httpCode: 200,
        );
    }

    /**
     * Plan 06 step 48. `GET /Api/Admin/Licenses/{LicenseKey}/Ledger?ResellerSlug=...`.
     *
     * Admin ledger read. Binds the reseller shard via ShardResolver,
     * looks up the License so unknown keys 404 (existence-leak safe
     * because Admin already selected a specific tenant), then returns
     * the shard-scoped ledger rows.
     */
        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="LicenseController ledger",
     *     tags={"LicenseController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function ledger(Request $request, string $licenseKey): JsonResponse
    {
        $slug = $this->requireResellerSlug($request);
        $reseller = $this->requireReseller($slug);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $license = $this->requireLicense($licenseKey);
        $limit = $this->parseLedgerLimit($request);
        $rows = $this->ledgerService->listForLicense(
            resellerId: (int) $license->ResellerId,
            licenseId: (int) $license->LicenseId,
            limit: $limit,
        );

        return ApiEnvelope::success($rows, $this->requestId($request));
    }

    private function parseLedgerLimit(Request $request): int
    {
        $raw = (int) $request->query('Limit', (string) \App\Services\LicenseLedgerService::DEFAULT_LIMIT);

        return max(1, min(\App\Services\LicenseLedgerService::MAX_LIMIT, $raw));
    }

    /**
     * Plan 06 step 33. `PATCH /Api/Admin/Licenses/{LicenseKey}?ResellerSlug=...`.
     *
     * If-Match is required by the global `EtagMiddleware`; here we
     * verify it against the CURRENT server projection (spec 09
     * concurrency-control §Server algorithm step 3). Mismatch -> 412.
     * EnvironmentName is not accepted (immutable per shard trigger).
     */
        /**
     * @OA\Patch(
     *     path="/api/placeholder",
     *     summary="LicenseController update",
     *     tags={"LicenseController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function update(LicenseUpdateRequest $request, string $licenseKey): JsonResponse
    {
        $slug = $this->requireResellerSlug($request);
        $reseller = $this->requireReseller($slug);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $patch = $request->payload();
        $license = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): License => $this->applyUpdate($request, $licenseKey, $patch)
        );
        AuditWriter::write($request, 'LicenseUpdated', 'Licenses', (int) $license->LicenseId, [
            'LicenseKey' => (string) $license->LicenseKey,
            'ResellerSlug' => (string) $reseller->ResellerSlug,
            'Fields' => array_keys($patch),
        ]);

        return ApiEnvelope::success([$this->project($license, [])], $this->requestId($request));
    }

    /**
     * Plan 06 step 32. `DELETE /Api/Admin/Licenses/{LicenseKey}?ResellerSlug=...`.
     *
     * Sets Status=Revoked, RevokedAt/By/Reason, bumps Version. Restore
     * eligibility follows spec 48 §1: only Reseller-issued licenses with
     * a non-null ResellerQuotaLedgerId restore quota. Every other case
     * emits a `Warn`-level `quota.restore.skipped` log line with the
     * exact reason (never silent). Root-side ledger write is deferred
     * to `QuotaService` (Plan 06 step 40); this endpoint records the
     * decision on the audit log via the returned envelope Meta so the
     * consumer can trigger the follow-up service call.
     */
    public function revoke(LicenseRevokeRequest $request, string $licenseKey): JsonResponse
    {
        $slug = $this->requireResellerSlug($request);
        $reseller = $this->requireReseller($slug);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $reason = $request->reason();
        [$license, $decision] = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): array => $this->applyRevoke($request, $licenseKey, $reason)
        );
        $this->logRestoreDecision($license, $decision);
        AuditWriter::write($request, 'LicenseRevoked', 'Licenses', (int) $license->LicenseId, [
            'LicenseKey' => (string) $license->LicenseKey,
            'ResellerSlug' => (string) $reseller->ResellerSlug,
            'RevokeReason' => (string) $reason,
            'QuotaRestored' => (bool) $decision['QuotaRestored'],
            'RestoreSkippedReason' => $decision['RestoreSkippedReason'],
        ]);

        return ApiEnvelope::success(
            results: [$this->project($license, []) + ['QuotaRestored' => $decision['QuotaRestored'], 'RestoreSkippedReason' => $decision['RestoreSkippedReason']]],
            requestId: $this->requestId($request),
        );
    }

    /**
     * Plan 09 step 58. REST-symmetric alias for `revoke()`.
     *
     * The DELETE verb on `/Api/Admin/Licenses/{LicenseKey}` maps here to
     * satisfy REST convention (`destroy` is the Laravel resource verb for
     * DELETE) without changing observable behaviour: this method delegates
     * to `revoke()` so the audit action name stays `LicenseRevoked`,
     * quota-restore semantics stay identical, and no additional route
     * needs to be added. Route binding stays in `routes/api.php` so callers
     * can migrate at their own pace; both `revoke` and `destroy` are
     * public and covered by the same feature test surface.
     */
        /**
     * @OA\Delete(
     *     path="/api/placeholder",
     *     summary="LicenseController destroy",
     *     tags={"LicenseController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function destroy(LicenseRevokeRequest $request, string $licenseKey): JsonResponse
    {
        return $this->revoke($request, $licenseKey);
    }

    private function requireResellerSlug(Request $request): string
    {
        $slug = trim((string) $request->query('ResellerSlug', ''));
        if ($slug === '' || preg_match('/^[a-z][a-z0-9-]{2,63}$/', $slug) !== 1) {
            throw ValidationException::validationFailed(
                'ResellerSlug query parameter is required for shard binding.',
                [['Field' => 'ResellerSlug', 'Rule' => 'Required']],
            );
        }

        return $slug;
    }

    private function requireLicense(string $licenseKey): License
    {
        $row = License::query()->where('LicenseKey', $licenseKey)->first();
        if ($row === null) {
            throw NotFoundException::notFound('LicenseNotFound',
                'License not found on the requested reseller shard.',
                [['Field' => 'LicenseKey', 'Rule' => 'NotFound', 'Value' => $licenseKey]],
            );
        }

        return $row;
    }

    // validateUpdatePayload removed: moved to LicenseUpdateRequest
    // (Plan 10 step 2 phase B).


    /**
     * @param array<string, mixed> $patch
     */
    private function applyUpdate(Request $request, string $licenseKey, array $patch): License
    {
        $license = $this->requireLicenseForUpdate($licenseKey);
        $this->enforceIfMatch($request, $license);
        $this->assertNotRevoked($license);
        $priorVersion = (int) $license->Version;
        $this->applyPatchFields($license, $patch);
        $license->Version = $priorVersion + 1;
        $license->UpdatedAt = Carbon::now();
        $license->save();
        Log::info('license.update.version_bump', [
            'LicenseId' => (int) $license->LicenseId,
            'LicenseKey' => (string) $license->LicenseKey,
            'PriorVersion' => $priorVersion,
            'NewVersion' => (int) $license->Version,
            'Fields' => array_keys($patch),
            'RequestId' => $this->requestId($request),
        ]);

        return $license;
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyPatchFields(License $license, array $patch): void
    {
        if (array_key_exists('TierName', $patch)) {
            $license->TierName = (string) $patch['TierName'];
        }
        if (array_key_exists('ProductVersion', $patch)) {
            $license->ProductVersion = (string) $patch['ProductVersion'];
        }
        if (array_key_exists('ExpiresAt', $patch)) {
            $license->ExpiresAt = $patch['ExpiresAt'] === null ? null : Carbon::parse((string) $patch['ExpiresAt']);
        }
        if (array_key_exists('Status', $patch)) {
            $license->Status = (string) $patch['Status'];
        }
    }

    private function requireLicenseForUpdate(string $licenseKey): License
    {
        $row = License::query()
            ->where('LicenseKey', $licenseKey)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw NotFoundException::notFound('LicenseNotFound',
                'License not found on the requested reseller shard.',
                [['Field' => 'LicenseKey', 'Rule' => 'NotFound', 'Value' => $licenseKey]],
            );
        }

        return $row;
    }

    private function enforceIfMatch(Request $request, License $license): void
    {
        $header = (string) $request->attributes->get('lara.if_match', '');
        $current = \App\Support\EntityHasher::hashSingleResource(
            $this->project($license, []),
            $this->requestId($request),
        );
        if (\App\Support\EntityHasher::ifMatchMatches($header, $current)) {
            return;
        }
        Log::warning('license.ifmatch.mismatch', [
            'LicenseId' => (int) $license->LicenseId,
            'LicenseKey' => (string) $license->LicenseKey,
            'ServerVersion' => (int) $license->Version,
            'IfMatchHeader' => $header,
            'ServerEtag' => $current,
            'RequestId' => $this->requestId($request),
        ]);
        throw ValidationException::custom('PreconditionFailed',
            'The license was modified since it was last read. Re-fetch and retry.',
            [
                ['Field' => 'If-Match', 'Rule' => 'ETagMismatch'],
                ['Field' => 'ServerVersion', 'Rule' => 'Current', 'Value' => (string) (int) $license->Version],
            ],
        )
    }

    private function assertNotRevoked(License $license): void
    {
        if ($license->Status === self::STATUS_REVOKED || $license->RevokedAt !== null) {
            throw DomainConflictException::custom('LicenseRevoked',
                'License is revoked and cannot be updated.',
                [['Field' => 'Status', 'Rule' => 'Revoked']],
            )
        }
    }

    // validateRevokePayload removed: moved to LicenseRevokeRequest
    // (Plan 10 step 2 phase B).


    /**
     * @return array{0: License, 1: array{QuotaRestored: bool, RestoreSkippedReason: string}}
     */
    private function applyRevoke(Request $request, string $licenseKey, string $reason): array
    {
        $license = $this->requireLicenseForUpdate($licenseKey);
        $this->enforceIfMatch($request, $license);
        if ($license->Status === self::STATUS_REVOKED || $license->RevokedAt !== null) {
            return [$license, ['QuotaRestored' => false, 'RestoreSkippedReason' => self::RESTORE_SKIP_ALREADY_RESTORED]];
        }
        $decision = $this->applyRestore($request, $license);
        $this->writeRevokedFields($license, $reason, $request);

        return [$license, $decision];
    }

    /**
     * Spec 48 §1 restore eligibility + §2 transactional contract. Runs
     * inside the caller's shard transaction so the ledger insert and
     * `LicensesConsumed` decrement land atomically with the
     * `Licenses` UPDATE below.
     *
     * @return array{QuotaRestored: bool, RestoreSkippedReason: string}
     */
    private function applyRestore(Request $request, License $license): array
    {
        if ($license->IssuerActorType !== self::ISSUER_ACTOR_RESELLER) {
            return ['QuotaRestored' => false, 'RestoreSkippedReason' => self::RESTORE_SKIP_ADMIN_ISSUED];
        }
        if ($license->ResellerQuotaLedgerId === null) {
            // Reseller-issued row with no back-reference is a data-
            // integrity anomaly; log Error so it surfaces, but let
            // revoke succeed per spec 48 §5.3.
            Log::error('quota.restore.missing_ledger_link', [
                'LicenseId' => (int) $license->LicenseId,
                'ResellerId' => (int) $license->ResellerId,
            ]);

            return ['QuotaRestored' => false, 'RestoreSkippedReason' => self::RESTORE_SKIP_ALREADY_RESTORED];
        }
        $result = $this->quotaService->restoreForLicense(
            resellerId: (int) $license->ResellerId,
            licenseCategoryId: (int) $license->LicenseCategoryId,
            tierName: (string) $license->TierName,
            licenseId: (int) $license->LicenseId,
            resellerQuotaLedgerId: (int) $license->ResellerQuotaLedgerId,
            requestId: $this->requestId($request),
            actorUserId: $this->requireIssuerId($request),
        );

        return [
            'QuotaRestored' => $result['QuotaRestored'],
            'RestoreSkippedReason' => $result['RestoreSkippedReason'],
        ];
    }


    private function writeRevokedFields(License $license, string $reason, Request $request): void
    {
        $issuerId = $this->requireIssuerId($request);
        $license->Status = self::STATUS_REVOKED;
        $license->RevokedAt = Carbon::now();
        $license->RevokedByUserId = $issuerId;
        $license->RevokeReason = $reason;
        $license->Version = ((int) $license->Version) + 1;
        $license->UpdatedAt = Carbon::now();
        $license->save();
    }

    /**
     * @param array{QuotaRestored: bool, RestoreSkippedReason: string} $decision
     */
    private function logRestoreDecision(License $license, array $decision): void
    {
        if ($decision['QuotaRestored'] === true) {
            return;
        }
        Log::warning('quota.restore.skipped', [
            'LicenseId' => (int) $license->LicenseId,
            'LicenseKey' => (string) $license->LicenseKey,
            'ResellerId' => (int) $license->ResellerId,
            'TierName' => (string) $license->TierName,
            'Reason' => $decision['RestoreSkippedReason'],
        ]);
    }
}
