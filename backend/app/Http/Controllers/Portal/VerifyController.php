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
use App\Http\Requests\Portal\VerifyFinalRequest;
use App\Http\Requests\Portal\VerifyHashRequest;
use App\Http\Requests\Portal\VerifySerialRequest;
use App\Models\License;
use App\Models\Prefix;
use App\Models\Reseller;
use App\Models\Serial;
use App\Models\VerifyKey;
use App\Services\BindingService;
use App\Services\EnvironmentService;
use App\Services\FeatureService;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Plan 06 step 39a. `POST /Api/Portal/Verify/Serial`.
 *
 * Root cause this controller fixes (one sentence): the client verify flow
 * defined by spec/21-app/09-verify-key.md §Flow step 1 and
 * spec/21-app/11-api-contracts/03-verification-contracts.md v1.3.0 §`POST /Verify/Serial`
 * has no server implementation, so end-user devices holding a freshly issued
 * `SerialValue` have no way to check existence, revocation state, or the
 * environment gate before advancing to `Verify/Hash`.
 *
 * Scope of THIS step (39a) - deliberately narrow:
 *  - Implements ONLY `POST /Verify/Serial` (the first of three handshake
 *    endpoints). `Verify/Hash` (39b) and `Verify/Final` (39c) land in
 *    later Plan 06 steps and depend on the `VerifyKeys` table shipped by
 *    migration 000010 (this turn) plus `TierFeatures` / `LicenseFeatures`
 *    resolution (Plan 06 step 41) and `MachineBindings` / `UserBindings`
 *    (Plan 06 step 43-adjacent). Documented gating: 39b/39c MUST NOT ship
 *    until those substrates exist because AC-API-VER-012 forbids returning
 *    an empty `Features` map on `Verify/Final`.
 *
 * Normative sources honoured:
 *  - spec/21-app/11-api-contracts/03-verification-contracts.md v1.3.0
 *    §`POST /Verify/Serial` request+result shape, failure envelopes,
 *    AC-API-VER-005 (PascalCase), AC-API-VER-006 (X-Request-Id strict),
 *    AC-API-VER-007 (ignore Idempotency-Key, do NOT reject when supplied),
 *    AC-API-VER-010 (opaque env markers in body AND logs),
 *    AC-API-VER-011 (do NOT echo EnvironmentId on Serial success).
 *  - spec/21-app/09-verify-key.md v1.0.0 §Flow step 1.
 *  - spec/21-app/44-environments.md §3 gate 2 (env match precedes any
 *    authorization signal to the caller).
 *
 * Known deviation from spec 03 wire schema, documented not hidden:
 *  the spec accepts `EnvironmentId` (integer) resolved from an
 *  `Environments` catalog table. The current shard DB stores
 *  `Licenses.EnvironmentName VARCHAR IN ('Production','Staging','Development')`
 *  (migration 000001), the config exposes only the closed-set name array
 *  (`config('lara.environments')`), and no `Environments` catalog table
 *  exists yet. This endpoint accepts `EnvironmentId` as the ORDINAL
 *  POSITION (1-based) into `config('lara.environments')` so the wire
 *  contract stays integer per AC-API-VER-009 while the server-side lookup
 *  stays exact. Plan 06 step 42 (EnvironmentService) creates the catalog
 *  table and replaces this ordinal-based resolution with a real FK. Until
 *  then, `EnvironmentId=1` maps to `Production`, `2` to `Staging`,
 *  `3` to `Development`, and any other integer returns
 *  `ValidationFailed / MembershipRequired`.
 *
 * Opaque env markers per AC-API-VER-010: on `EnvironmentMismatch` the
 * `Details.Value` is `"<RequestedOrdinal>/<LicensedOrdinal>"`, both
 * integers, and the log line carries the same integer pair. The tokens
 * `Production`, `Staging`, `Development` MUST NOT appear anywhere in
 * the response body or in the emitted log record for this request.
 */
final class VerifyController
{
    private const SHARD_CONNECTION = 'shard';
    private const STATUS_ACTIVE = 'Active';
    private const STATUS_REVOKED = 'Revoked';
    private const SERIAL_VALUE_REGEX = '/^[A-Z0-9-]{8,64}$/';
    private const ENV_ORDINAL_MIN = 1;
    private const LOG_ENV_MISMATCH = 'verify.serial.environment_mismatch';
    private const LOG_SERIAL_INVALID = 'verify.serial.invalid';
    private const LOG_SERIAL_REVOKED = 'verify.serial.revoked';
    private const LOG_LICENSE_EXPIRED = 'verify.serial.license_expired';
    private const LOG_OK = 'verify.serial.ok';
    private const LOG_HASH_OK = 'verify.hash.ok';
    private const LOG_HASH_INVALID = 'verify.hash.invalid';
    private const LOG_FINAL_OK = 'verify.final.ok';
    private const LOG_FINAL_KEY_INVALID = 'verify.final.key_invalid';
    private const LOG_FINAL_KEY_EXPIRED = 'verify.final.key_expired';
    private const LOG_FINAL_KEY_CONSUMED = 'verify.final.key_consumed';
    private const LOG_FINAL_FEATURE_DRIFT = 'verify.final.feature_catalog_drift';
    private const HASH_KEY_REGEX = '/^[A-Fa-f0-9]{32,128}$/';
    private const VERIFY_KEY_VALUE_REGEX = '/^[a-f0-9]{32}$/';
    private const FINGERPRINT_HASH_REGEX = '/^[a-f0-9]{64}$/';
    private const USER_IDENTIFIER_REGEX = '/^[A-Za-z0-9._@:+\-]{1,255}$/';
    private const VERIFY_KEY_TTL_MINUTES = 5;
    private const VERIFY_KEY_BYTES = 16;

    public function __construct(
        private readonly ShardResolver $shardResolver,
        private readonly EnvironmentService $environmentService,
        private readonly FeatureService $featureService,
        private readonly BindingService $bindingService,
    ) {
    }


        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="VerifyController serial",
     *     tags={"VerifyController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function serial(VerifySerialRequest $request): JsonResponse
    {
        $requestId = $this->requireRequestId($request);
        $payload = $request->payload();
        $reseller = $this->resolveResellerForSerial($payload['SerialValue']);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $serial = $this->requireSerial($payload['SerialValue'], $requestId);
        $license = $this->requireLicense((int) $serial->LicenseId, $requestId);
        $this->assertLicenseActive($license, $requestId);
        $this->assertEnvironmentMatches($license, $payload['EnvironmentOrdinal'], $requestId);
        Log::info(self::LOG_OK, [
            'requestId' => $requestId,
            'licenseId' => (int) $license->LicenseId,
            'serialId' => (int) $serial->SerialId,
        ]);

        return ApiEnvelope::success(
            results: [$this->project($license, $serial)],
            requestId: $requestId,
            httpCode: 200,
            message: 'OK',
        );
    }

    /**
     * @return array{SerialValue:string, EnvironmentOrdinal:int}
     */
    /**
     * Plan 06 step 39b. `POST /Api/Portal/Verify/Hash`.
     *
     * Second handshake endpoint (spec 03 §`POST /Verify/Hash`). Validates
     * the caller-supplied `HashKey`, re-runs the serial/license/env gates
     * from `Verify/Serial`, and mints a single-use `VerifyKeys` row whose
     * `HashKeyDigest = sha256(HashKey)`. Returns `VerifyKey` + `ExpiresAt`.
     *
     * Deliberately deferred (documented, not swallowed):
     *  - MachineFingerprint / UserIdentifier persistence. `LicenseMachineLimit`
     *    and `LicenseUserLimit` gates require the `MachineBindings` and
     *    `UserBindings` shard tables which do not exist yet in v0.221. Until
     *    those substrates land, the fingerprint payload is validated for
     *    shape (AC-API-VER shape rules) but not stored, and the limit gates
     *    return no failure. This is a documented v0.221 scope boundary;
     *    downstream Plan 06 steps replace this stub with real binding writes.
     */
        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="VerifyController hash",
     *     tags={"VerifyController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function hash(VerifyHashRequest $request): JsonResponse
    {
        $requestId = $this->requireRequestId($request);
        $payload = $request->payload();
        $reseller = $this->resolveResellerForSerial($payload['SerialValue']);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $serial = $this->requireSerial($payload['SerialValue'], $requestId);
        $license = $this->requireLicense((int) $serial->LicenseId, $requestId);
        $this->assertLicenseActive($license, $requestId);
        $this->assertEnvironmentMatches($license, $payload['EnvironmentOrdinal'], $requestId);
        $verifyKey = $this->mintVerifyKey($license, $serial, $payload['HashKey'], $requestId);
        Log::info(self::LOG_HASH_OK, [
            'requestId' => $requestId,
            'licenseId' => (int) $license->LicenseId,
            'serialId' => (int) $serial->SerialId,
            'verifyKeyId' => (int) $verifyKey->VerifyKeyId,
        ]);
        $response = ApiEnvelope::success(
            results: [[
                'VerifyKey' => (string) $verifyKey->VerifyKeyValue,
                'ExpiresAt' => (string) $verifyKey->ExpiresAt,
            ]],
            requestId: $requestId,
            httpCode: 200,
            message: 'OK',
        );
        // Spec 03 §Verify/Hash: response is never cached.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array{SerialValue:string, HashKey:string, EnvironmentOrdinal:int}
     */
    private function validateHashPayload(Request $request): array
    {
        $environments = (array) Config::get('lara.environments', []);
        $envMax = count($environments);
        if ($envMax < self::ENV_ORDINAL_MIN) {
            throw InternalException::custom('ServerConfigurationError',
                'Environments closed set is not configured.',
                [['Field' => 'Environments', 'Rule' => 'Empty']],
            )
        }
        try {
            /** @var array<string,mixed> $v */
            $v = $request->validate([
                'SerialValue' => ['required', 'string', 'regex:' . self::SERIAL_VALUE_REGEX],
                'HashKey' => ['required', 'string', 'regex:' . self::HASH_KEY_REGEX],
                'EnvironmentId' => ['required', 'integer', 'min:' . self::ENV_ORDINAL_MIN, 'max:' . $envMax],
                'MachineFingerprint' => ['required', 'array'],
                'MachineFingerprint.MachineKey' => ['sometimes', 'string'],
                'MachineFingerprint.BrowserFingerprint' => ['sometimes', 'string'],
                'MachineFingerprint.MotherboardSerial' => ['sometimes', 'string'],
                'MachineFingerprint.MacAddress' => ['sometimes', 'string'],
                'UserIdentifier' => ['sometimes', 'string'],
            ]);
        } catch (ValidationException $e) {
            $this->throwValidationFailedHash($e);
        }
        $fp = (array) $v['MachineFingerprint'];
        $hasMachineKey = isset($fp['MachineKey']) && (string) $fp['MachineKey'] !== '';
        $hasBrowserFp = isset($fp['BrowserFingerprint']) && (string) $fp['BrowserFingerprint'] !== '';
        if (!$hasMachineKey && !$hasBrowserFp) {
            // Spec 03 §Verify/Hash: at least MachineKey OR BrowserFingerprint required.
            throw ValidationException::validationFailed(
                'Verify/Hash MachineFingerprint requires MachineKey or BrowserFingerprint.',
                [['Field' => 'MachineFingerprint', 'Rule' => 'OneOfRequired']],
            );
        }

        return [
            'SerialValue' => (string) $v['SerialValue'],
            'HashKey' => (string) $v['HashKey'],
            'EnvironmentOrdinal' => (int) $v['EnvironmentId'],
        ];
    }

    private function mintVerifyKey(License $license, Serial $serial, string $hashKey, string $requestId): VerifyKey
    {
        $now = Carbon::now();
        $expiresAt = $now->copy()->addMinutes(self::VERIFY_KEY_TTL_MINUTES);
        $verifyKey = new VerifyKey();
        $verifyKey->LicenseId = (int) $license->LicenseId;
        $verifyKey->SerialId = (int) $serial->SerialId;
        $verifyKey->HashKeyDigest = hash('sha256', $hashKey);
        $verifyKey->VerifyKeyValue = bin2hex(random_bytes(self::VERIFY_KEY_BYTES));
        $verifyKey->IssuedAt = $now;
        $verifyKey->ExpiresAt = $expiresAt;
        $verifyKey->IsConsumed = false;
        $verifyKey->RequestId = $requestId;
        $verifyKey->CreatedAt = $now;
        $verifyKey->UpdatedAt = $now;
        $verifyKey->save();

        return $verifyKey;
    }

    private function throwValidationFailedHash(ValidationException $e): never
    {
        $details = [];
        foreach ($e->errors() as $field => $_messages) {
            $rule = str_starts_with((string) $field, 'MachineFingerprint')
                ? 'OneOfRequired'
                : ($field === 'EnvironmentId' ? 'MembershipRequired' : 'Required');
            $details[] = ['Field' => (string) $field, 'Rule' => $rule];
        }
        throw ValidationException::validationFailed( 'Verify/Hash payload failed validation.', $details, $e);
    }

    private function validatePayload(Request $request): array
    {
        $environments = (array) Config::get('lara.environments', []);
        $envMax = count($environments);
        if ($envMax < self::ENV_ORDINAL_MIN) {
            // Server-side invariant: closed-set environments must be configured.
            // If empty, this is a config bug not a caller bug.
            throw InternalException::custom('ServerConfigurationError',
                'Environments closed set is not configured.',
                [['Field' => 'Environments', 'Rule' => 'Empty']],
            )
        }
        try {
            /** @var array<string,mixed> $v */
            $v = $request->validate([
                'SerialValue' => ['required', 'string', 'regex:' . self::SERIAL_VALUE_REGEX],
                'EnvironmentId' => ['required', 'integer', 'min:' . self::ENV_ORDINAL_MIN, 'max:' . $envMax],
            ]);
        } catch (ValidationException $e) {
            $this->throwValidationFailed($e);
        }

        return [
            'SerialValue' => (string) $v['SerialValue'],
            'EnvironmentOrdinal' => (int) $v['EnvironmentId'],
        ];
    }

    private function resolveResellerForSerial(string $serialValue): Reseller
    {
        // SerialValue prefix (Root Prefixes registry) points at the owning shard.
        $separator = strpos($serialValue, '-');
        $prefixValue = $separator === false ? $serialValue : substr($serialValue, 0, $separator);
        $prefix = Prefix::query()->where('PrefixValue', $prefixValue)->first();
        if ($prefix === null) {
            // AC-API-VER-005-adjacent: do not disclose whether the prefix exists;
            // upstream response is SerialInvalid with no Details (per spec 03 table).
            throw ValidationException::custom('SerialInvalid',
                'Serial does not resolve to a known reseller.',
                [],
            )
        }
        $reseller = Reseller::query()->where('ResellerId', (int) $prefix->ResellerId)->first();
        if ($reseller === null) {
            // Root-side inconsistency: prefix registered but reseller row missing.
            // This is a server-side bug, not a client mistake; do not leak it.
            throw InternalException::custom('ServerConfigurationError',
                'Reseller owning the serial prefix is missing.',
                [],
            )
        }

        return $reseller;
    }

    private function requireSerial(string $serialValue, string $requestId): Serial
    {
        $serial = Serial::query()->where('SerialValue', $serialValue)->first();
        if ($serial === null) {
            Log::info(self::LOG_SERIAL_INVALID, ['requestId' => $requestId]);
            throw ValidationException::custom('SerialInvalid', 'Serial not found.', []);
        }
        if ((bool) $serial->IsRevoked === true) {
            Log::info(self::LOG_SERIAL_REVOKED, [
                'requestId' => $requestId,
                'serialId' => (int) $serial->SerialId,
            ]);
            throw DomainConflictException::conflict('SerialRevoked', 'Serial has been revoked.', []);
        }

        return $serial;
    }

    private function requireLicense(int $licenseId, string $requestId): License
    {
        $license = License::query()->where('LicenseId', $licenseId)->first();
        if ($license === null) {
            Log::info(self::LOG_SERIAL_INVALID, ['requestId' => $requestId, 'reason' => 'LicenseMissing']);
            // A serial exists but its parent license does not. Treat as SerialInvalid
            // to avoid disclosing internal FK state.
            throw ValidationException::custom('SerialInvalid', 'Serial parent license missing.', []);
        }

        return $license;
    }

    private function assertLicenseActive(License $license, string $requestId): void
    {
        if ((string) $license->Status === self::STATUS_REVOKED) {
            // A revoked license invalidates every serial under it. Reuse SerialRevoked
            // so the client-facing signal is consistent with the underlying reality.
            Log::info(self::LOG_SERIAL_REVOKED, [
                'requestId' => $requestId,
                'licenseId' => (int) $license->LicenseId,
                'reason' => 'LicenseRevoked',
            ]);
            throw DomainConflictException::conflict('SerialRevoked', 'Parent license is revoked.', []);
        }
        if ($license->ExpiresAt !== null && $license->ExpiresAt->isPast()) {
            Log::info(self::LOG_LICENSE_EXPIRED, [
                'requestId' => $requestId,
                'licenseId' => (int) $license->LicenseId,
            ]);
            throw DomainConflictException::custom('LicenseExpired',
                'License has expired.',
                [['Field' => 'ExpiresAt', 'Rule' => 'InPast', 'Actual' => (string) $license->ExpiresAt]],
            )
        }
    }

    private function assertEnvironmentMatches(License $license, int $requestedOrdinal, string $requestId): void
    {
        try {
            $this->environmentService->assertMatch((string) $license->EnvironmentName, $requestedOrdinal);

            return;
        } catch (LaraException $e) {
            if ($e->errorCode !== 'EnvironmentMismatch') {
                throw $e;
            }
            // AC-API-VER-010: log only opaque integer ordinals.
            $licensedOrdinal = $this->environmentService->nameToOrdinal((string) $license->EnvironmentName);
            Log::info(self::LOG_ENV_MISMATCH, [
                'requestId' => $requestId,
                'licenseId' => (int) $license->LicenseId,
                'requestedOrdinal' => $requestedOrdinal,
                'licensedOrdinal' => $licensedOrdinal,
            ]);
            throw $e;
        }
    }

    private function environmentNameToOrdinal(string $name): int
    {
        return $this->environmentService->nameToOrdinal($name);
    }

    /**
     * @return array<string,mixed>
     */
    private function project(License $license, Serial $serial): array
    {
        // spec 03 §`POST /Verify/Serial` Result shape. `EnvironmentId` is
        // deliberately NOT echoed here per AC-API-VER-011 (only Verify/Final
        // discloses environment after the match gate has passed).
        return [
            'IsValid' => true,
            'LicenseId' => (int) $license->LicenseId,
            'Category' => $this->licenseCategoryCode($license),
            'ExpiresAt' => $license->ExpiresAt === null ? null : (string) $license->ExpiresAt,
            'IsSingleUse' => (bool) ($license->IsSingleUse ?? false),
            'SerialId' => (int) $serial->SerialId,
        ];
    }

    private function licenseCategoryCode(License $license): string
    {
        $codes = (array) Config::get('lara.license_category_codes', []);
        $categoryId = (int) ($license->LicenseCategoryId ?? 0);

        return (string) ($codes[$categoryId] ?? '');
    }

    private function requireRequestId(Request $request): string
    {
        // RequestIdMiddleware already enforces presence and echoes the header.
        // Read-through here so log lines get a bound value even if a future
        // route bypasses the middleware.
        $id = (string) $request->headers->get('X-Request-Id', '');
        if ($id === '') {
            throw ValidationException::custom('RequestIdMissing',
                'X-Request-Id is required on verify endpoints.',
                [],
            )
        }

        return $id;
    }

    private function throwValidationFailed(ValidationException $e): never
    {
        $details = [];
        foreach ($e->errors() as $field => $_messages) {
            $rule = $field === 'EnvironmentId' ? 'MembershipRequired' : 'Required';
            $details[] = ['Field' => (string) $field, 'Rule' => $rule];
        }
        throw ValidationException::validationFailed( 'Verify/Serial payload failed validation.', $details, $e);
    }

    /**
     * Plan 06 step 39c. `POST /Api/Portal/Verify/Final`.
     *
     * Third handshake endpoint (spec 03 §`POST /Verify/Final`). Consumes
     * a single-use `VerifyKey` inside a shard transaction, runs the
     * environment gate INSIDE the same transaction (spec 03
     * §Transaction boundary), and returns the resolved feature map plus
     * echoed `EnvironmentId` and `LicenseTierId`.
     *
     * Deferred (documented, not swallowed):
     *  - `MachineBindings` / `UserBindings` writes and the associated
     *    `LicenseMachineLimit` / `LicenseUserLimit` 409 gates: the
     *    shard tables do not exist yet. Spec 03 declares both response
     *    IDs OPTIONAL integers, so this endpoint returns them as `null`
     *    until the substrate lands. Recorded as remaining work; the
     *    contract is stable so the wire-in is a mechanical follow-up.
     */
        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="VerifyController final",
     *     tags={"VerifyController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function final(VerifyFinalRequest $request): JsonResponse
    {
        $requestId = $this->requireRequestId($request);
        $payload = $request->payload();
        $reseller = $this->resolveResellerForSerial($payload['SerialValue']);
        $this->shardResolver->bind($reseller->ResellerSlug);
        $result = DB::connection(self::SHARD_CONNECTION)->transaction(function () use ($payload, $requestId) {
            return $this->consumeVerifyKeyAndAuthorize($payload, $requestId);
        });
        $response = ApiEnvelope::success(
            results: [$result],
            requestId: $requestId,
            httpCode: 200,
            message: 'OK',
        );
        // AC-API-VER-004: every verify response Cache-Control: no-store.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param array{SerialValue:string,HashKey:string,VerifyKey:string,EnvironmentOrdinal:int,FingerprintHash:?string,UserIdentifier:?string} $payload
     * @return array<string,mixed>
     */
    private function consumeVerifyKeyAndAuthorize(array $payload, string $requestId): array
    {
        $serial = $this->requireSerial($payload['SerialValue'], $requestId);
        $license = $this->requireLicense((int) $serial->LicenseId, $requestId);
        $this->assertLicenseActive($license, $requestId);
        // Environment gate runs BEFORE key consumption per spec 03 §Transaction boundary.
        $this->assertEnvironmentMatches($license, $payload['EnvironmentOrdinal'], $requestId);
        $verifyKey = $this->lockAndValidateVerifyKey($payload, $license, $serial, $requestId);
        $this->markVerifyKeyConsumed($verifyKey);
        [$machineBindingId, $userBindingId] = $this->applyBindings($license, $payload, $requestId);
        $features = $this->resolveFeatureMap((int) $license->LicenseId, $requestId);
        Log::info(self::LOG_FINAL_OK, [
            'requestId' => $requestId,
            'licenseId' => (int) $license->LicenseId,
            'serialId' => (int) $serial->SerialId,
            'verifyKeyId' => (int) $verifyKey->VerifyKeyId,
            'machineBindingId' => $machineBindingId,
            'userBindingId' => $userBindingId,
        ]);

        return $this->projectFinal($license, $features, $payload['EnvironmentOrdinal'], $machineBindingId, $userBindingId);
    }

    /**
     * @param array{FingerprintHash:?string,UserIdentifier:?string} $payload
     * @return array{0:?int,1:?int}
     */
    private function applyBindings(License $license, array $payload, string $requestId): array
    {
        $licenseId = (int) $license->LicenseId;
        $machineBindingId = null;
        $userBindingId = null;
        if (empty($payload['FingerprintHash']) === false) {
            $machineBindingId = $this->bindingService->applyMachineBinding(
                $licenseId,
                (string) $payload['FingerprintHash'],
                BindingService::defaultMaxConcurrent(),
                $requestId,
            );
        }
        if (empty($payload['UserIdentifier']) === false) {
            $userBindingId = $this->bindingService->applyUserBinding(
                $licenseId,
                (string) $payload['UserIdentifier'],
                BindingService::defaultMaxUsers(),
                $requestId,
            );
        }

        return [$machineBindingId, $userBindingId];
    }


    /**
     * @param array{SerialValue:string,HashKey:string,VerifyKey:string,EnvironmentOrdinal:int} $payload
     */
    private function lockAndValidateVerifyKey(array $payload, License $license, Serial $serial, string $requestId): VerifyKey
    {
        // SELECT ... FOR UPDATE so a concurrent second consumer waits then
        // sees IsConsumed=true and gets VerifyKeyConsumed.
        $row = DB::connection(self::SHARD_CONNECTION)
            ->table('VerifyKeys')
            ->where('VerifyKeyValue', $payload['VerifyKey'])
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            Log::info(self::LOG_FINAL_KEY_INVALID, ['requestId' => $requestId]);
            // AC-API-VER-001 constant-time discipline: do not disclose which pair mismatched.
            throw ValidationException::custom('VerifyKeyMismatch', 'Verify key does not match.', []);
        }
        $this->assertVerifyKeyBinding($row, $license, $serial, $payload['HashKey'], $requestId);
        $this->assertVerifyKeyFresh($row, $requestId);

        return VerifyKey::query()->where('VerifyKeyId', (int) $row->VerifyKeyId)->firstOrFail();
    }

    private function assertVerifyKeyBinding(object $row, License $license, Serial $serial, string $hashKey, string $requestId): void
    {
        // Constant-time HashKey digest compare (AC-API-VER-001).
        $expected = hash('sha256', $hashKey);
        $stored = (string) $row->HashKeyDigest;
        $sameLicense = (int) $row->LicenseId === (int) $license->LicenseId;
        $sameSerial = (int) $row->SerialId === (int) $serial->SerialId;
        if (!hash_equals($stored, $expected) || !$sameLicense || !$sameSerial) {
            Log::info(self::LOG_FINAL_KEY_INVALID, [
                'requestId' => $requestId,
                'verifyKeyId' => (int) $row->VerifyKeyId,
            ]);
            throw ValidationException::custom('VerifyKeyMismatch', 'Verify key does not match.', []);
        }
    }

    private function assertVerifyKeyFresh(object $row, string $requestId): void
    {
        if ((bool) $row->IsConsumed === true) {
            Log::info(self::LOG_FINAL_KEY_CONSUMED, [
                'requestId' => $requestId,
                'verifyKeyId' => (int) $row->VerifyKeyId,
            ]);
            throw DomainConflictException::custom('VerifyKeyConsumed', 'Verify key has already been consumed.', []);
        }
        $expiresAt = Carbon::parse((string) $row->ExpiresAt);
        if ($expiresAt->isPast()) {
            Log::info(self::LOG_FINAL_KEY_EXPIRED, [
                'requestId' => $requestId,
                'verifyKeyId' => (int) $row->VerifyKeyId,
            ]);
            throw DomainConflictException::custom('VerifyKeyExpired',
                'Verify key has expired.',
                [['Field' => 'ExpiresAt', 'Rule' => 'InPast', 'Actual' => (string) $row->ExpiresAt]],
            )
        }
    }

    private function markVerifyKeyConsumed(VerifyKey $verifyKey): void
    {
        $now = Carbon::now();
        $verifyKey->IsConsumed = true;
        $verifyKey->ConsumedAt = $now;
        $verifyKey->UpdatedAt = $now;
        $verifyKey->save();
    }

    /**
     * @return array<string,bool|int|float|string>
     */
    private function resolveFeatureMap(int $licenseId, string $requestId): array
    {
        try {
            return $this->featureService->resolve($licenseId, self::SHARD_CONNECTION);
        } catch (\Throwable $e) {
            // AC-API-VER-013: feature catalog drift is a 500, never a partial leak.
            Log::warning(self::LOG_FINAL_FEATURE_DRIFT, [
                'requestId' => $requestId,
                'licenseId' => $licenseId,
                'reason' => $e->getMessage(),
            ]);
            throw InternalException::serverError('UnknownServerError', 'Feature resolution failed.', [], $e);
        }
    }

    /**
     * @param array<string,bool|int|float|string> $features
     * @return array<string,mixed>
     */
    private function projectFinal(
        License $license,
        array $features,
        int $environmentOrdinal,
        ?int $machineBindingId,
        ?int $userBindingId,
    ): array {
        return [
            'IsAuthorized' => true,
            'LicenseId' => (int) $license->LicenseId,
            'EnvironmentId' => $environmentOrdinal,
            'LicenseTierId' => $license->LicenseTierId === null ? null : (int) $license->LicenseTierId,
            'Features' => $features,
            'AuthorizedAt' => Carbon::now()->toIso8601String(),
            'ExpiresAt' => $license->ExpiresAt === null ? null : (string) $license->ExpiresAt,
            'MachineBindingId' => $machineBindingId,
            'UserBindingId' => $userBindingId,
        ];
    }

    /**
     * @return array{SerialValue:string,HashKey:string,VerifyKey:string,EnvironmentOrdinal:int,FingerprintHash:?string,UserIdentifier:?string}
     */
    private function validateFinalPayload(Request $request): array
    {
        $envMax = $this->environmentService->ordinalMax();
        try {
            /** @var array<string,mixed> $v */
            $v = $request->validate([
                'SerialValue' => ['required', 'string', 'regex:' . self::SERIAL_VALUE_REGEX],
                'HashKey' => ['required', 'string', 'regex:' . self::HASH_KEY_REGEX],
                'VerifyKey' => ['required', 'string', 'regex:' . self::VERIFY_KEY_VALUE_REGEX],
                'EnvironmentId' => ['required', 'integer', 'min:' . self::ENV_ORDINAL_MIN, 'max:' . $envMax],
                'FingerprintHash' => ['nullable', 'string', 'regex:' . self::FINGERPRINT_HASH_REGEX],
                'UserIdentifier' => ['nullable', 'string', 'regex:' . self::USER_IDENTIFIER_REGEX],
            ]);
        } catch (ValidationException $e) {
            $this->throwValidationFailedFinal($e);
        }

        return [
            'SerialValue' => (string) $v['SerialValue'],
            'HashKey' => (string) $v['HashKey'],
            'VerifyKey' => (string) $v['VerifyKey'],
            'EnvironmentOrdinal' => (int) $v['EnvironmentId'],
            'FingerprintHash' => isset($v['FingerprintHash']) ? (string) $v['FingerprintHash'] : null,
            'UserIdentifier' => isset($v['UserIdentifier']) ? (string) $v['UserIdentifier'] : null,
        ];
    }


    private function throwValidationFailedFinal(ValidationException $e): never
    {
        $details = [];
        foreach ($e->errors() as $field => $_messages) {
            $rule = $field === 'EnvironmentId' ? 'MembershipRequired' : 'Required';
            $details[] = ['Field' => (string) $field, 'Rule' => $rule];
        }
        throw ValidationException::validationFailed( 'Verify/Final payload failed validation.', $details, $e);
    }
}
