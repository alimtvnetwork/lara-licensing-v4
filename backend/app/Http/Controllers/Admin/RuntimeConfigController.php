<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Admin\RuntimeConfigUpdateRequest;
use App\Policies\HasRolePolicy;
use App\Services\RuntimeConfigService;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Plan 16 step 58 (v0.563.0). Admin surface for repo-root `version.json`.
 *
 * Contract: spec/28-runtime-modes/05-admin-runtime-toggle.md.
 *  - GET  /Api/Admin/RuntimeConfig    read + strong ETag on `UpdatedAt`.
 *  - PUT  /Api/Admin/RuntimeConfig    atomic rewrite guarded by If-Match.
 *
 * The route group already enforces `require.role:Admin|SuperAdmin`; the
 * PUT handler additionally requires SuperAdmin because `version.json` is
 * a deploy-level lever (spec §Actors and RBAC A-01/A-02).
 */
final class RuntimeConfigController
{
    public function __construct(
        private readonly RuntimeConfigService $service,
        private readonly HasRolePolicy $roles,
    ) {
    }

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="RuntimeConfigController show",
     *     tags={"RuntimeConfigController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function show(Request $request): JsonResponse
    {
        $view = $this->service->readForResponse();
        $resp = ApiEnvelope::success([$view['Body']], $this->requestId($request));

        return $resp->header('ETag', $view['ETag']);
    }

        /**
     * @OA\Put(
     *     path="/api/placeholder",
     *     summary="RuntimeConfigController update",
     *     tags={"RuntimeConfigController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function update(RuntimeConfigUpdateRequest $request): JsonResponse
    {
        $this->assertSuperAdmin($request);
        $ifMatch = $this->assertIfMatchPresent($request);
        $payload = $request->payload();
        if ($payload === []) {
            throw ValidationException::validationFailed( 'At least one mutable field is required.', [['Field' => 'Body', 'Rule' => 'Empty']]);
        }
        $result = $this->service->update($payload, $ifMatch);
        $this->auditWrite($request, $result);
        $resp = ApiEnvelope::success([$result['After']], $this->requestId($request));

        return $resp->header('ETag', $result['ETag']);
    }

    private function assertSuperAdmin(Request $request): void
    {
        $user = $request->user();
        $userId = $user === null ? null : (string) $user->getAuthIdentifier();
        if ($userId === null || !$this->roles->hasRole($userId, 'SuperAdmin')) {
            $this->logDenial($request, 'ForbiddenNonSuperAdmin');
            throw DomainConflictException::custom('RuntimeConfigForbidden',
                'Only SuperAdmin may mutate runtime configuration.',
                [['Field' => 'Role', 'Rule' => 'SuperAdminRequired']],
            )
        }
    }

    private function assertIfMatchPresent(Request $request): string
    {
        $ifMatch = trim((string) $request->headers->get('If-Match', ''));
        if ($ifMatch === '') {
            throw ValidationException::custom('PreconditionRequired',
                'If-Match header is required for runtime-config writes.',
                [['Field' => 'If-Match', 'Rule' => 'Missing']],
            )
        }

        return $ifMatch;
    }

    /**
     * @param array{Before:array<string,mixed>, After:array<string,mixed>, ChangedKeys:list<string>, ETag:string} $result
     */
    private function auditWrite(Request $request, array $result): void
    {
        AuditWriter::write($request, 'RuntimeConfigUpdated', 'RuntimeConfig', null, [
            'Before' => $result['Before'],
            'After' => $result['After'],
            'ChangedKeys' => $result['ChangedKeys'],
        ]);
    }

    private function logDenial(Request $request, string $reason): void
    {
        Log::warning('runtime_config.denied', [
            'RequestId' => $this->requestId($request),
            'Reason' => $reason,
            'ActorId' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }
}
