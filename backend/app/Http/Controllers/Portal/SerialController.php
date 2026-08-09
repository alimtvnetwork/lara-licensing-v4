<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Portal\SerialIssueRequest;
use App\Models\License;
use App\Models\Prefix;
use App\Models\Reseller;
use App\Models\Serial;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 06 step 38. Portal Serial issuance (`POST /Api/Portal/Serials`).
 *
 * End-user device requests a Serial for a known LicenseKey. Endpoint is
 * HMAC-signed by SignedRequestMiddleware (`require.signature`), never
 * session-authenticated. Idempotency is DB-native via
 * UNIQUE("LicenseId","DeviceIdHash") so a retry of the same (license,
 * device) always returns the same SerialValue with HTTP 200; a first
 * successful issue returns 201.
 *
 * References:
 *  - spec/21-app/07-serial-generation.md    (SerialValue format)
 *  - spec/21-app/11-api-contracts/03-verification-contracts.md
 *  - spec/23-app-db/01-schema.md §Serials   (columns + UNIQUE invariant)
 *  - spec/21-app/29-idempotency-lifecycle.md (echo IdempotencyKey)
 */
final class SerialController
{
    private const SHARD_CONNECTION = 'shard';
    private const STATUS_ACTIVE = 'Active';
    private const STATUS_REVOKED = 'Revoked';
    private const DEVICE_HASH_REGEX = '/^[a-f0-9]{64}$/';
    private const FEATURE_HASH_REGEX = '/^[a-f0-9]{64}$/';
    private const LICENSE_KEY_REGEX = '/^[A-Z0-9]{2,12}-[A-Z0-9-]{4,80}$/';
    private const IDEMPOTENCY_KEY_HEADER = 'Idempotency-Key';
    private const RANDOM_GROUPS = 4;
    private const RANDOM_GROUP_LEN = 4;
    private const DEFAULT_CATEGORY_CODE = 'K';
    private const COLLISION_RETRY_LIMIT = 5;

    public function __construct(private readonly ShardResolver $shardResolver)
    {
    }

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="SerialController issue",
     *     tags={"SerialController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function issue(SerialIssueRequest $request): JsonResponse
    {
        $data = $request->payload();
        $idempotencyKey = $this->readIdempotencyKey($request);
        $reseller = $this->resolveResellerForLicenseKey($data['LicenseKey']);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $license = $this->requireActiveLicense($data['LicenseKey']);
        $this->assertEnvironmentMatches($license, $data['EnvironmentName']);
        [$serial, $created] = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): array => $this->upsertSerial($license, $data, $idempotencyKey)
        );

        return ApiEnvelope::success(
            results: [$this->project($serial)],
            requestId: $this->requestId($request),
            httpCode: $created ? 201 : 200,
            message: $created ? 'Created' : 'OK',
            extraAttributes: $idempotencyKey === '' ? [] : ['IdempotencyKey' => $idempotencyKey],
        );
    }

    private function readIdempotencyKey(Request $request): string
    {
        return trim((string) $request->headers->get(self::IDEMPOTENCY_KEY_HEADER, ''));
    }

    private function resolveResellerForLicenseKey(string $licenseKey): Reseller
    {
        $sep = strpos($licenseKey, '-');
        $prefixValue = $sep === false ? $licenseKey : substr($licenseKey, 0, $sep);
        $prefix = Prefix::query()->where('PrefixValue', $prefixValue)->first();
        if ($prefix === null) {
            throw NotFoundException::notFound('LicenseNotFound',
                'License prefix is not registered.',
                [['Field' => 'LicenseKey', 'Rule' => 'PrefixUnknown', 'Value' => $prefixValue]],
            );
        }
        $reseller = Reseller::query()->where('ResellerId', (int) $prefix->ResellerId)->first();
        if ($reseller === null) {
            throw NotFoundException::notFound('ResellerNotFound',
                'Reseller owning the license prefix is missing.',
                [['Field' => 'PrefixValue', 'Rule' => 'ResellerMissing', 'Value' => $prefixValue]],
            );
        }

        return $reseller;
    }

    private function requireActiveLicense(string $licenseKey): License
    {
        $license = License::query()->where('LicenseKey', $licenseKey)->first();
        if ($license === null) {
            throw NotFoundException::notFound('LicenseNotFound',
                'License not found on the resolved shard.',
                [['Field' => 'LicenseKey', 'Rule' => 'NotFound']],
            );
        }
        if ($license->Status === self::STATUS_REVOKED || $license->RevokedAt !== null) {
            throw DomainConflictException::custom('LicenseRevoked',
                'License is revoked and cannot mint serials.',
                [['Field' => 'Status', 'Rule' => 'Revoked']],
            )
        }
        if ($license->Status !== self::STATUS_ACTIVE) {
            throw DomainConflictException::conflict('LicenseConflict',
                'License is not in an issuable state.',
                [['Field' => 'Status', 'Rule' => 'NotActive', 'Value' => (string) $license->Status]],
            );
        }
        if ($license->ExpiresAt !== null && $license->ExpiresAt->isPast()) {
            throw DomainConflictException::custom('LicenseExpired',
                'License has expired.',
                [['Field' => 'ExpiresAt', 'Rule' => 'InPast', 'Value' => (string) $license->ExpiresAt]],
            )
        }

        return $license;
    }

    private function assertEnvironmentMatches(License $license, string $requested): void
    {
        if ((string) $license->EnvironmentName === $requested) {
            return;
        }
        // Opaque marker only per spec 21 §Verify EnvironmentMismatch.
        throw DomainConflictException::custom('EnvironmentMismatch',
            'Requested environment does not match license environment.',
            [['Field' => 'Environment', 'Rule' => 'Mismatch', 'Value' => $requested . '/' . (string) $license->EnvironmentName]],
        )
    }

    /**
     * @param array{LicenseKey:string, DeviceIdHash:string, EnvironmentName:string, FeaturePayloadHash?:string} $data
     * @return array{0: Serial, 1: bool}
     */
    private function upsertSerial(License $license, array $data, string $idempotencyKey): array
    {
        $existing = Serial::query()
            ->where('LicenseId', (int) $license->LicenseId)
            ->where('DeviceIdHash', $data['DeviceIdHash'])
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            Log::info('portal.serial.reused', [
                'LicenseId' => (int) $license->LicenseId,
                'SerialId' => (int) $existing->SerialId,
            ]);

            return [$existing, false];
        }
        $serial = new Serial();
        $serial->LicenseId = (int) $license->LicenseId;
        $serial->SerialValue = $this->mintUniqueSerialValue($license);
        $serial->DeviceIdHash = $data['DeviceIdHash'];
        $serial->EnvironmentName = $data['EnvironmentName'];
        $serial->FeaturePayloadHash = $data['FeaturePayloadHash'] ?? null;
        $serial->IdempotencyKey = $idempotencyKey;
        $serial->IsRevoked = false;
        $serial->IssuedAt = Carbon::now();
        $serial->save();
        Log::info('portal.serial.issued', [
            'LicenseId' => (int) $license->LicenseId,
            'SerialId' => (int) $serial->SerialId,
        ]);

        return [$serial, true];
    }

    private function mintUniqueSerialValue(License $license): string
    {
        $prefix = (string) $license->PrefixValue;
        $categoryCode = $this->categoryCodeForLicense($license);
        $versionCode = (string) $license->ProductVersion;
        for ($attempt = 0; $attempt < self::COLLISION_RETRY_LIMIT; $attempt++) {
            $random = $this->generateRandomGroups();
            $candidate = $prefix . '-' . $categoryCode . '-' . $versionCode . '-' . $random;
            $exists = Serial::query()->where('SerialValue', $candidate)->exists();
            if ($exists === false) {
                return $candidate;
            }
            Log::warning('portal.serial.collision', [
                'LicenseId' => (int) $license->LicenseId,
                'Attempt' => $attempt + 1,
            ]);
        }
        throw InternalException::serverError('UnknownServerError',
            'Serial value collision retry budget exhausted.',
            [['Field' => 'SerialValue', 'Rule' => 'CollisionBudget']],
        );
    }

    private function categoryCodeForLicense(License $license): string
    {
        // Shard migration 000008 adds `LicenseCategoryId` NOT NULL, so the
        // steady-state path is a direct config lookup. The default branch
        // stays for legacy rows that predate the migration and emits a
        // Warn log so the gap is visible instead of silently guessed.
        $categoryId = (int) ($license->LicenseCategoryId ?? 0);
        $map = (array) config('lara.license_category_codes', []);
        if ($categoryId > 0 && isset($map[$categoryId])) {
            return (string) $map[$categoryId];
        }
        Log::warning('portal.serial.category_default', [
            'LicenseId' => (int) $license->LicenseId,
            'LicenseCategoryId' => $categoryId,
            'CategoryCode' => self::DEFAULT_CATEGORY_CODE,
        ]);

        return self::DEFAULT_CATEGORY_CODE;
    }


    private function generateRandomGroups(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < self::RANDOM_GROUPS; $g++) {
            $chunk = '';
            for ($i = 0; $i < self::RANDOM_GROUP_LEN; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }

    /**
     * @return array<string, mixed>
     */
    private function project(Serial $serial): array
    {
        return (new \App\Http\Resources\SerialResource($serial))->resolve();
    }


    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }
}
