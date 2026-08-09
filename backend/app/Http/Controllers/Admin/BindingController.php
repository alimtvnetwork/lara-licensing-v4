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
use App\Http\Requests\Admin\BindingClearCooldownRequest;
use App\Http\Requests\Admin\BindingIndexRequest;
use App\Http\Requests\Admin\BindingReleaseRequest;
use App\Models\License;
use App\Models\Reseller;
use App\Services\BindingService;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 06 step 39 (admin bindings surface). Read + release +
 * cooldown-clear operations on `MachineBindings`.
 *
 * Root cause this controller fixes (one sentence): spec
 * `spec/21-app/30-machine-bindings.md` §"Admin operations" lines 76-82
 * mandates three admin endpoints (list, force-release, clear-cooldown)
 * to keep licensing operators unblocked when a customer machine is
 * stuck, but no controller existed so admins had no supported path
 * short of raw SQL against a reseller shard.
 *
 * Shard is selected via `?ResellerSlug=` query parameter, matching the
 * pattern used by `Admin\LicenseController` (there is no reverse
 * License->Reseller index on Root, so the caller must be explicit).
 * All writes run inside the shard transaction so `ReleasedAt` and
 * `RebindCooldownUntil` are set as a pair per AC-MB-005.
 */
final class BindingController
{
    private const SHARD_CONNECTION = 'shard';
    private const LICENSE_KEY_REGEX = '/^[A-Z0-9-]{4,80}$/';
    
    private const RELEASE_REASON_ADMIN = 'AdminInitiated';
    private const LOG_RELEASE = 'admin.binding.release';
    private const LOG_CLEAR_COOLDOWN = 'admin.binding.clear_cooldown';

    public function __construct(
        private readonly ShardResolver $shardResolver,
    ) {
    }

    /**
     * `GET /Api/Admin/Licenses/{LicenseKey}/Bindings?ResellerSlug=...`.
     */
    public function index(BindingIndexRequest $request, string $licenseKey): JsonResponse
    {
        $license = $this->bindAndLoadLicense($licenseKey, $request->resellerSlug());
        $rows = DB::connection(self::SHARD_CONNECTION)
            ->table('MachineBindings')
            ->where('LicenseId', (int) $license->LicenseId)
            ->orderByDesc('MachineBindingId')
            ->get();
        $results = $rows->map(fn ($r) => $this->projectMachine($r))->all();

        return ApiEnvelope::success($results, $this->requestId($request));
    }

    /**
     * `POST /Api/Admin/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/Release?ResellerSlug=...`.
     * Sets `ReleasedAt = NOW()` and `RebindCooldownUntil = NOW() + config
     * minutes` in one UPDATE (AC-MB-005). Cooldown still applies to admin
     * releases per spec 30 line 81.
     */
    public function release(BindingReleaseRequest $request, string $licenseKey, string $bindingId): JsonResponse
    {
        $license = $this->bindAndLoadLicense($licenseKey, $request->resellerSlug());
        $id = (int) $bindingId;
        $result = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): array => $this->applyRelease((int) $license->LicenseId, $id, $this->requestId($request))
        );
        AuditWriter::write($request, 'BindingReleased', 'MachineBindings', $id, [
            'LicenseId' => (int) $license->LicenseId,
            'LicenseKey' => (string) $license->LicenseKey,
            'ReleaseReason' => self::RELEASE_REASON_ADMIN,
        ]);

        return ApiEnvelope::success([$result], $this->requestId($request));
    }

    /**
     * `POST /Api/Admin/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/ClearCooldown?ResellerSlug=...`.
     * Spec 30 line 82: emits an audit "AdminBreakGlassUsed" row; audit
     * sink is out of scope here so the intent is logged at WARN with
     * the actor and reason so operators have durable trail today.
     */
    public function clearCooldown(BindingClearCooldownRequest $request, string $licenseKey, string $bindingId): JsonResponse
    {
        $license = $this->bindAndLoadLicense($licenseKey, $request->resellerSlug());
        $reason = $request->reason();
        $id = (int) $bindingId;
        $result = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): array => $this->applyClearCooldown((int) $license->LicenseId, $id, $reason, $request)
        );
        AuditWriter::write($request, 'BindingCooldownCleared', 'MachineBindings', $id, [
            'LicenseId' => (int) $license->LicenseId,
            'LicenseKey' => (string) $license->LicenseKey,
            'BreakGlassReason' => (string) $reason,
        ]);

        return ApiEnvelope::success([$result], $this->requestId($request));
    }

    private function bindAndLoadLicense(string $licenseKey, string $slug): License
    {
        $this->assertLicenseKey($licenseKey);
        $reseller = $this->requireReseller($slug);
        $this->shardResolver->bind($reseller->ResellerSlug);

        return $this->requireLicense($licenseKey);
    }


    /**
     * @return array<string,mixed>
     */
    private function applyRelease(int $licenseId, int $bindingId, string $requestId): array
    {
        $now = Carbon::now();
        $cooldownUntil = $now->copy()->addMinutes(BindingService::rebindCooldownMinutes());
        $row = DB::connection(self::SHARD_CONNECTION)
            ->table('MachineBindings')
            ->where('MachineBindingId', $bindingId)
            ->where('LicenseId', $licenseId)
            ->lockForUpdate()
            ->first();
        $this->assertBindingActive($row, $bindingId);
        DB::connection(self::SHARD_CONNECTION)
            ->table('MachineBindings')
            ->where('MachineBindingId', $bindingId)
            ->update([
                'ReleasedAt' => $now,
                'RebindCooldownUntil' => $cooldownUntil,
                'UpdatedAt' => $now,
            ]);
        Log::info(self::LOG_RELEASE, [
            'requestId' => $requestId,
            'licenseId' => $licenseId,
            'machineBindingId' => $bindingId,
            'releaseReason' => self::RELEASE_REASON_ADMIN,
        ]);

        return $this->projectMachine((object) array_merge((array) $row, [
            'ReleasedAt' => (string) $now,
            'RebindCooldownUntil' => (string) $cooldownUntil,
            'UpdatedAt' => (string) $now,
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function applyClearCooldown(int $licenseId, int $bindingId, string $reason, Request $request): array
    {
        $now = Carbon::now();
        $row = DB::connection(self::SHARD_CONNECTION)
            ->table('MachineBindings')
            ->where('MachineBindingId', $bindingId)
            ->where('LicenseId', $licenseId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw NotFoundException::notFound('LicenseNotFound',
                'Machine binding not found on this license shard.',
                [['Field' => 'MachineBindingId', 'Rule' => 'NotFound', 'Value' => (string) $bindingId]],
            );
        }
        DB::connection(self::SHARD_CONNECTION)
            ->table('MachineBindings')
            ->where('MachineBindingId', $bindingId)
            ->update(['RebindCooldownUntil' => null, 'UpdatedAt' => $now]);
        Log::warning(self::LOG_CLEAR_COOLDOWN, [
            'requestId' => $this->requestId($request),
            'licenseId' => $licenseId,
            'machineBindingId' => $bindingId,
            'reason' => $reason,
            'actorUserId' => (string) ($request->user()?->getAuthIdentifier() ?? ''),
        ]);

        return $this->projectMachine((object) array_merge((array) $row, [
            'RebindCooldownUntil' => null,
            'UpdatedAt' => (string) $now,
        ]));
    }

    private function assertBindingActive(?object $row, int $bindingId): void
    {
        if ($row === null) {
            throw NotFoundException::notFound('LicenseNotFound',
                'Machine binding not found on this license shard.',
                [['Field' => 'MachineBindingId', 'Rule' => 'NotFound', 'Value' => (string) $bindingId]],
            );
        }
        if ($row->ReleasedAt !== null) {
            throw DomainConflictException::conflict('LicenseConflict',
                'Machine binding is already released.',
                [['Field' => 'ReleasedAt', 'Rule' => 'AlreadySet', 'Actual' => (string) $row->ReleasedAt]],
            );
        }
    }

    private function assertLicenseKey(string $licenseKey): void
    {
        if (preg_match(self::LICENSE_KEY_REGEX, $licenseKey) !== 1) {
            throw ValidationException::validationFailed(
                'LicenseKey shape is invalid.',
                [['Field' => 'LicenseKey', 'Rule' => 'Regex']],
            );
        }
    }


    private function requireReseller(string $slug): Reseller
    {
        $reseller = Reseller::query()->where('ResellerSlug', $slug)->first();
        if ($reseller === null) {
            throw NotFoundException::notFound('ResellerNotFound',
                'Reseller shard is not registered.',
                [['Field' => 'ResellerSlug', 'Rule' => 'NotFound', 'Value' => $slug]],
            );
        }

        return $reseller;
    }

    private function requireLicense(string $licenseKey): License
    {
        $license = License::query()->where('LicenseKey', $licenseKey)->first();
        if ($license === null) {
            throw NotFoundException::notFound('LicenseNotFound',
                'License not found on the requested reseller shard.',
                [['Field' => 'LicenseKey', 'Rule' => 'NotFound', 'Value' => $licenseKey]],
            );
        }

        return $license;
    }


    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function projectMachine(object $row): array
    {
        return [
            'MachineBindingId' => (int) $row->MachineBindingId,
            'LicenseId' => (int) $row->LicenseId,
            'FingerprintHash' => (string) $row->FingerprintHash,
            'FirstSeenAt' => (string) $row->FirstSeenAt,
            'LastSeenAt' => (string) $row->LastSeenAt,
            'ReleasedAt' => $row->ReleasedAt === null ? null : (string) $row->ReleasedAt,
            'RebindCooldownUntil' => $row->RebindCooldownUntil === null ? null : (string) $row->RebindCooldownUntil,
        ];
    }
}
