<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Reseller\LicenseIssueRequest;
use App\Http\Requests\Reseller\LicenseRenewRequest;
use App\Models\License;
use App\Services\LicenseLedgerService;
use App\Services\QuotaService;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use App\Support\EntityHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 06 step 35. Reseller surface for Licenses on the caller's shard.
 *
 * Shard binding is performed by `ShardBindingMiddleware` before this
 * controller runs; `ResellerSlug` is available under
 * `$request->attributes->get('ResellerSlug')`. This controller MUST NOT
 * accept a `ResellerSlug` from the caller: cross-reseller reads are
 * forbidden per spec/23-app-db/10 §Routing Rules and
 * spec/21-app/04-roles.md §Reseller row-scope. All queries run against
 * the `shard` connection bound by the middleware.
 *
 * Endpoints:
 *   GET   /Api/Reseller/Licenses                 list  (200)
 *   GET   /Api/Reseller/Licenses/{LicenseKey}    show  (200)
 *   PATCH /Api/Reseller/Licenses/{LicenseKey}/Renew  renew (200, If-Match)
 *
 * Renew extends `ExpiresAt` and writes a `LicenseLedger` row with
 * `LedgerAction = LicenseRenewed`. The mutation is done inside a
 * shard transaction so the ledger invariant from spec 48 §1 holds.
 */
final class LicenseController
{
    private const SHARD_CONNECTION = 'shard';
    private const LEDGER_TABLE = 'LicenseLedger';
    private const LEDGER_ACTION_RENEWED = 'LicenseRenewed';
    private const LEDGER_DELTA_NEUTRAL = 0;
    private const LIST_LIMIT_DEFAULT = 50;
    private const LIST_LIMIT_MAX = 200;
    private const STATUS_ACTIVE = 'Active';
    private const STATUS_SUSPENDED = 'Suspended';
    private const STATUS_REVOKED = 'Revoked';
    private const ISSUER_ACTOR_RESELLER = 'Reseller';
    private const LICENSE_KEY_BYTES = 8;
    private const LICENSE_KEY_SEPARATOR = '-';
    private const DEFAULT_PRODUCT_VERSION = 'V1';
    private const DEFAULT_CATEGORY_NAME = 'Key';

    public function __construct(
        private readonly QuotaService $quotaService,
        private readonly LicenseLedgerService $ledgerService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $resellerId = $this->requireResellerId($request);
        $limit = $this->parseLimit($request);
        $rows = License::query()
            ->where('ResellerId', $resellerId)
            ->orderBy('LicenseId')
            ->limit($limit)
            ->get();
        $projected = $rows->map(fn (License $l): array => $this->project($l))->all();

        return ApiEnvelope::success($projected, $this->requestId($request));
    }

    public function show(Request $request, string $licenseKey): JsonResponse
    {
        $license = $this->requireOwnedLicense($request, $licenseKey);

        return ApiEnvelope::success([$this->project($license)], $this->requestId($request));
    }

    /**
     * Plan 06 step 48. `GET /Api/Reseller/Licenses/{LicenseKey}/Ledger`.
     *
     * Shard-scoped read of `LicenseLedger` rows for a license the
     * caller owns. Ownership is asserted by `requireOwnedLicense`
     * before the ledger read so cross-tenant probes get 404
     * `LicenseNotFound` (spec 21-app/12-error-taxonomy §Existence leak).
     */
    public function ledger(Request $request, string $licenseKey): JsonResponse
    {
        $license = $this->requireOwnedLicense($request, $licenseKey);
        $rows = $this->ledgerService->listForLicense(
            resellerId: (int) $license->ResellerId,
            licenseId: (int) $license->LicenseId,
            limit: $this->parseLimit($request),
        );

        return ApiEnvelope::success($rows, $this->requestId($request));
    }

    public function renew(LicenseRenewRequest $request, string $licenseKey): JsonResponse
    {
        $newExpiresAt = $this->requireFutureExpiresAt($request->expiresAt());
        $license = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): License => $this->applyRenew($request, $licenseKey, $newExpiresAt)
        );
        AuditWriter::write($request, 'LicenseRenewed', 'Licenses', (int) $license->LicenseId, [
            'LicenseKey' => (string) $license->LicenseKey,
            'ExpiresAt' => $license->ExpiresAt instanceof \DateTimeInterface ? $license->ExpiresAt->format('Y-m-d\TH:i:s\Z') : (string) $license->ExpiresAt,
        ]);

        return ApiEnvelope::success([$this->project($license)], $this->requestId($request));
    }

    /**
     * Plan 06 step 35b. `POST /Api/Reseller/Licenses`.
     *
     * Mints a Reseller-issued license on the caller's bound shard,
     * charging the reseller quota via `QuotaService::preflight` +
     * `::decrement`. Runs inside a single shard transaction so the
     * ledger invariant `SUM(Delta) = LicensesGranted - LicensesConsumed`
     * (spec 48 §Ledger contract) cannot drift under partial failure.
     * Idempotency is enforced by the global `IdempotencyKeyMiddleware`
     * (prefix `api/reseller/licenses` is in its required list).
     */
    public function issue(LicenseIssueRequest $request): JsonResponse
    {
        $resellerId = $this->requireResellerId($request);
        $payload = $this->normalizeIssuePayload($request);
        $license = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): License => $this->applyIssue($request, $resellerId, $payload)
        );
        AuditWriter::write($request, 'LicenseIssued', 'Licenses', (int) $license->LicenseId, [
            'LicenseKey' => (string) $license->LicenseKey,
            'ResellerId' => (int) $resellerId,
            'TierName' => (string) $license->TierName,
            'EnvironmentName' => (string) $license->EnvironmentName,
            'IssuerActor' => 'Reseller',
        ]);

        return ApiEnvelope::success(
            results: [$this->project($license)],
            requestId: $this->requestId($request),
            httpCode: 201,
        );
    }

    /**
     * @return array{PrefixValue:string, TierName:string, EnvironmentName:string, LicenseCategory:string, ExpiresAt:?string}
     */
    private function normalizeIssuePayload(LicenseIssueRequest $request): array
    {
        $v = $request->raw();
        $tiers = (array) config('lara.license_tiers', []);
        $envs = (array) config('lara.environments', []);
        $cats = (array) config('lara.license_categories', []);
        $tierName = (string) ($v['TierName'] ?? '');
        if (array_key_exists($tierName, $tiers) === false) {
            throw ValidationException::validationFailed( 'Unknown TierName.', [['Field' => 'TierName', 'Rule' => 'MembershipRequired', 'Value' => $tierName]]);
        }
        $envName = (string) ($v['EnvironmentName'] ?? '');
        if (!in_array($envName, $envs, true) && !array_key_exists($envName, $envs)) {
            throw ValidationException::validationFailed( 'Unknown EnvironmentName.', [['Field' => 'EnvironmentName', 'Rule' => 'MembershipRequired', 'Value' => $envName]]);
        }
        $catName = isset($v['LicenseCategory']) && $v['LicenseCategory'] !== null ? (string) $v['LicenseCategory'] : self::DEFAULT_CATEGORY_NAME;
        if (array_key_exists($catName, $cats) === false) {
            throw ValidationException::validationFailed( 'Unknown LicenseCategory.', [['Field' => 'LicenseCategory', 'Rule' => 'MembershipRequired', 'Value' => $catName]]);
        }

        return [
            'PrefixValue' => (string) $v['PrefixValue'],
            'TierName' => $tierName,
            'EnvironmentName' => $envName,
            'LicenseCategory' => $catName,
            'ExpiresAt' => isset($v['ExpiresAt']) && $v['ExpiresAt'] !== null ? (string) $v['ExpiresAt'] : null,
        ];
    }


    /**
     * @param array{PrefixValue:string, TierName:string, EnvironmentName:string, LicenseCategory:string, ExpiresAt:?string} $payload
     */
    private function applyIssue(Request $request, int $resellerId, array $payload): License
    {
        $issuerId = $this->requireIssuerId($request);
        $now = Carbon::now();
        $tiers = (array) config('lara.license_tiers', []);
        $cats = (array) config('lara.license_categories', []);
        $tierId = (int) $tiers[$payload['TierName']];
        $categoryId = (int) $cats[$payload['LicenseCategory']];
        $this->assertPrefixOwnership($resellerId, $payload['PrefixValue']);
        // Preflight throws QuotaExhausted (409) before we mint anything.
        $this->quotaService->preflight($resellerId, $categoryId, $tierId, $now);
        $license = new License();
        $license->LicenseKey = $this->mintLicenseKey($payload['PrefixValue']);
        $license->PrefixValue = $payload['PrefixValue'];
        $license->ResellerId = $resellerId;
        $license->IssuedByUserId = $issuerId;
        $license->IssuerActorType = self::ISSUER_ACTOR_RESELLER;
        $license->LicenseCategoryId = $categoryId;
        $license->TierName = $payload['TierName'];
        $license->EnvironmentName = $payload['EnvironmentName'];
        $license->ProductVersion = self::DEFAULT_PRODUCT_VERSION;
        $license->Status = self::STATUS_ACTIVE;
        $license->IssuedAt = $now;
        $license->ExpiresAt = $payload['ExpiresAt'] !== null ? Carbon::parse($payload['ExpiresAt']) : null;
        $license->Version = 1;
        $license->save();
        $ledgerId = $this->quotaService->decrement(
            resellerId: $resellerId,
            categoryId: $categoryId,
            tierId: $tierId,
            licenseId: (int) $license->LicenseId,
            tierName: $payload['TierName'],
            requestId: $this->requestId($request),
            actorUserId: $issuerId,
            now: $now,
        );
        // Backlink the funding ledger row so revoke restore is eligible
        // per spec 48 §1: only rows with ResellerQuotaLedgerId != NULL
        // participate in restoration.
        $license->ResellerQuotaLedgerId = $ledgerId;
        $license->save();
        Log::info('reseller.license.issued', [
            'licenseId' => (int) $license->LicenseId,
            'licenseKey' => (string) $license->LicenseKey,
            'resellerId' => $resellerId,
            'ledgerId' => $ledgerId,
            'requestId' => $this->requestId($request),
        ]);

        return $license;
    }

    private function assertPrefixOwnership(int $resellerId, string $prefixValue): void
    {
        $row = DB::connection('root')->table('Prefixes')->where('PrefixValue', $prefixValue)->first();
        if ($row === null) {
            throw NotFoundException::notFound('PrefixNotFound', 'Prefix does not exist.', [['Field' => 'PrefixValue', 'Rule' => 'NotFound', 'Value' => $prefixValue]]);
        }
        if ((int) $row->ResellerId !== $resellerId) {
            throw DomainConflictException::conflict('PrefixForbidden', 'Prefix belongs to another reseller.', [['Field' => 'PrefixValue', 'Rule' => 'OwnershipMismatch', 'Value' => $prefixValue]]);
        }
        if ($row->IsActive === false) {
            throw DomainConflictException::conflict('PrefixForbidden', 'Prefix is disabled.', [['Field' => 'PrefixValue', 'Rule' => 'Inactive', 'Value' => $prefixValue]]);
        }
    }

    private function mintLicenseKey(string $prefixValue): string
    {
        $random = strtoupper(bin2hex(random_bytes(self::LICENSE_KEY_BYTES)));

        return $prefixValue . self::LICENSE_KEY_SEPARATOR . $random;
    }

    private function requireIssuerId(Request $request): int
    {
        $id = $request->user()?->getAuthIdentifier();
        if ($id === null || !is_numeric($id)) {
            throw AuthException::invalidCredentials( 'Authenticated user identifier is missing.', [['Field' => 'IssuedByUserId', 'Rule' => 'Missing']]);
        }

        return (int) $id;
    }


    private function requireResellerId(Request $request): int
    {
        $slug = (string) $request->attributes->get('ResellerSlug', '');
        if ($slug === '') {
            throw AuthException::forbidden( 'Reseller shard is not bound; user has no tenant.', [['Field' => 'ResellerSlug', 'Rule' => 'Unbound']]);
        }
        $id = DB::connection('root')->table('Resellers')->where('ResellerSlug', $slug)->value('ResellerId');
        if ($id === null) {
            throw NotFoundException::notFound('ResellerNotFound', 'Bound reseller does not resolve to a Root row.', [['Field' => 'ResellerSlug', 'Rule' => 'NotFound', 'Value' => $slug]]);
        }

        return (int) $id;
    }

    private function parseLimit(Request $request): int
    {
        $raw = (int) $request->query('Limit', (string) self::LIST_LIMIT_DEFAULT);

        return max(1, min(self::LIST_LIMIT_MAX, $raw));
    }

    private function requireOwnedLicense(Request $request, string $licenseKey): License
    {
        $resellerId = $this->requireResellerId($request);
        $row = License::query()->where('LicenseKey', $licenseKey)->first();
        if ($row === null || (int) $row->ResellerId !== $resellerId) {
            // Existence leak protection per spec 12: return NotFound even
            // when the row exists in a different reseller's shard would
            // be a bug (shard is scoped), but if a caller forges a key
            // for another tenant on this shard we still return NotFound.
            throw NotFoundException::notFound('LicenseNotFound', 'License not found on this reseller shard.', [['Field' => 'LicenseKey', 'Rule' => 'NotFound', 'Value' => $licenseKey]]);
        }

        return $row;
    }

    private function requireFutureExpiresAt(string $raw): Carbon
    {
        $next = Carbon::parse($raw);
        if ($next->isPast()) {
            throw ValidationException::validationFailed( 'ExpiresAt must be in the future.', [['Field' => 'ExpiresAt', 'Rule' => 'Future']]);
        }

        return $next;
    }


    private function applyRenew(Request $request, string $licenseKey, Carbon $newExpiresAt): License
    {
        $license = $this->requireOwnedLicenseForUpdate($request, $licenseKey);
        $this->enforceIfMatch($request, $license);
        $this->assertRenewable($license);
        $license->ExpiresAt = $newExpiresAt;
        $license->Version = ((int) $license->Version) + 1;
        $license->UpdatedAt = Carbon::now();
        $license->save();
        $this->insertRenewLedger($request, $license);

        return $license;
    }

    private function requireOwnedLicenseForUpdate(Request $request, string $licenseKey): License
    {
        $resellerId = $this->requireResellerId($request);
        $row = License::query()
            ->where('LicenseKey', $licenseKey)
            ->lockForUpdate()
            ->first();
        if ($row === null || (int) $row->ResellerId !== $resellerId) {
            throw NotFoundException::notFound('LicenseNotFound', 'License not found on this reseller shard.', [['Field' => 'LicenseKey', 'Rule' => 'NotFound', 'Value' => $licenseKey]]);
        }

        return $row;
    }

    private function enforceIfMatch(Request $request, License $license): void
    {
        $header = (string) $request->attributes->get('lara.if_match', '');
        $current = EntityHasher::hashSingleResource($this->project($license), $this->requestId($request));
        if (EntityHasher::ifMatchMatches($header, $current) === false) {
            throw ValidationException::custom('PreconditionFailed', 'The license was modified since last read. Re-fetch and retry.', [['Field' => 'If-Match', 'Rule' => 'ETagMismatch']]);
        }
    }

    private function assertRenewable(License $license): void
    {
        if ($license->Status === self::STATUS_REVOKED || $license->RevokedAt !== null) {
            throw DomainConflictException::custom('LicenseRevoked', 'Revoked licenses cannot be renewed.', [['Field' => 'Status', 'Rule' => 'Revoked']]);
        }
    }

    private function insertRenewLedger(Request $request, License $license): void
    {
        DB::connection(self::SHARD_CONNECTION)->table(self::LEDGER_TABLE)->insert([
            'ResellerId' => (int) $license->ResellerId,
            'TierName' => (string) $license->TierName,
            'LedgerAction' => self::LEDGER_ACTION_RENEWED,
            'Delta' => self::LEDGER_DELTA_NEUTRAL,
            'LicenseId' => (int) $license->LicenseId,
            'QuotaRequestId' => null,
            'RequestId' => $this->requestId($request),
            'ActorUserId' => $this->actorId($request),
            'CreatedAt' => Carbon::now(),
        ]);
        Log::info('reseller.license.renewed', [
            'licenseId' => (int) $license->LicenseId,
            'licenseKey' => (string) $license->LicenseKey,
            'expiresAt' => $license->ExpiresAt?->format('Y-m-d\TH:i:s\Z'),
            'requestId' => $this->requestId($request),
        ]);
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    // throwValidationFailed removed: FormRequests now own the
    // ValidationFailed envelope via their failedValidation() hook.


    /**
     * @return array<string, mixed>
     */
    private function project(License $license): array
    {
        return [
            'LicenseId' => (int) $license->LicenseId,
            'LicenseKey' => (string) $license->LicenseKey,
            'PrefixValue' => (string) $license->PrefixValue,
            'ResellerId' => (int) $license->ResellerId,
            'IssuedByUserId' => (int) $license->IssuedByUserId,
            'IssuerActorType' => (string) ($license->IssuerActorType ?? ''),
            'TierName' => (string) $license->TierName,
            'EnvironmentName' => (string) $license->EnvironmentName,
            'ProductVersion' => (string) $license->ProductVersion,
            'Status' => (string) $license->Status,
            'IssuedAt' => $this->isoOrEmpty($license->IssuedAt),
            'ExpiresAt' => $this->isoOrEmpty($license->ExpiresAt),
            'RevokedAt' => $this->isoOrEmpty($license->RevokedAt),
            'RevokeReason' => (string) ($license->RevokeReason ?? ''),
            'Version' => (int) $license->Version,
        ];
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
}
