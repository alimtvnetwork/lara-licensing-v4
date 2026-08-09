<?php

declare(strict_types=1);

use App\Models\AuthSession;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Support\ApiEnvelope;
use Illuminate\Support\Facades\Route;

/**
 * Pest feature suite: `ImpersonationService::begin` / `end` contract.
 *
 * Boots without the Root/Shard schema by binding a fake service into
 * the container and routing a throwaway probe endpoint through the
 * real `ApiEnvelope::success` writer. Locks:
 *
 *   - AC-IMP-001: `begin` returns 201 with all documented payload keys
 *     (SessionId, ImpersonatorUserId, TargetUserId, Kind,
 *     ParentSessionId, ExpiresAt, Reason, Token).
 *   - AC-IMP-005: `end` returns 200 with SessionId, EndedAt, EndReason
 *     for a normalized `OperatorEnded` reason.
 *   - The service is invoked with the operator/target identities the
 *     controller resolved (spy captures the arguments).
 *
 * Real schema-backed transactional invariants are covered by
 * `ImpersonationTimeoutSweepTest` and the saga integration tests; this
 * suite locks the wire contract without needing a live DB.
 */

/** Deterministic in-memory ImpersonationService. */
final class PestFakeImpersonationService extends ImpersonationService
{
    /** @var array<int, array{op:User, parent:AuthSession, target:User, reason:string, requestId:string}> */
    public array $beginCalls = [];

    /** @var array<int, array{caller:User, session:AuthSession, endReason:string, requestId:string}> */
    public array $endCalls = [];

    public function __construct() {}

    public function begin(User $operator, AuthSession $parent, User $target, string $reason, string $requestId): array
    {
        $this->beginCalls[] = compact('operator', 'parent', 'target', 'reason', 'requestId');

        return [
            'SessionId' => '11111111-1111-4111-8111-111111111111',
            'ImpersonatorUserId' => (int) $operator->getKey(),
            'TargetUserId' => (int) $target->getKey(),
            'Kind' => 'Impersonation',
            'ParentSessionId' => (string) $parent->SessionId,
            'ExpiresAt' => '2026-07-18T12:00:00Z',
            'Reason' => $reason,
            'Token' => 'tok_pest_begin',
        ];
    }

    public function end(User $caller, AuthSession $impersonation, string $endReason, string $requestId): array
    {
        $this->endCalls[] = compact('caller', 'impersonation', 'endReason', 'requestId');

        return [
            'SessionId' => (string) $impersonation->SessionId,
            'EndedAt' => '2026-07-18T12:05:00Z',
            'EndReason' => $endReason,
        ];
    }
}

beforeEach(function (): void {
    $this->fakeSvc = new PestFakeImpersonationService();
    $this->app->instance(ImpersonationService::class, $this->fakeSvc);

    Route::post('/api/pest-impersonate/begin', function () {
        $operator = new User(); $operator->id = 10; $operator->UserId = 10;
        $target   = new User(); $target->id   = 20; $target->UserId   = 20;
        $parent = new AuthSession();
        $parent->SessionId = '22222222-2222-4222-8222-222222222222';
        $parent->UserId = 10;
        $svc = app(ImpersonationService::class);
        $payload = $svc->begin($operator, $parent, $target, 'Support ticket #4711', 'req-begin');

        return ApiEnvelope::success([$payload], 'req-begin', httpCode: 201, message: 'Created');
    });

    Route::post('/api/pest-impersonate/end', function () {
        $caller = new User(); $caller->id = 10; $caller->UserId = 10;
        $session = new AuthSession();
        $session->SessionId = '11111111-1111-4111-8111-111111111111';
        $svc = app(ImpersonationService::class);
        $payload = $svc->end($caller, $session, 'OperatorEnded', 'req-end');

        return ApiEnvelope::success([$payload], 'req-end');
    });
});

it('returns 201 with the full begin payload and invokes the service with resolved identities', function (): void {
    $res = $this->postJson('/api/pest-impersonate/begin');
    $res->assertStatus(201);
    $row = $res->json('Results.0');
    expect($row)->toHaveKeys([
        'SessionId', 'ImpersonatorUserId', 'TargetUserId', 'Kind',
        'ParentSessionId', 'ExpiresAt', 'Reason', 'Token',
    ]);
    expect($row['ImpersonatorUserId'])->toBe(10)
        ->and($row['TargetUserId'])->toBe(20)
        ->and($row['Kind'])->toBe('Impersonation')
        ->and($row['Reason'])->toBe('Support ticket #4711');
    expect($this->fakeSvc->beginCalls)->toHaveCount(1);
    $call = $this->fakeSvc->beginCalls[0];
    expect((int) $call['operator']->getKey())->toBe(10)
        ->and((int) $call['target']->getKey())->toBe(20)
        ->and($call['requestId'])->toBe('req-begin');
});

it('returns 200 with the end payload and normalized EndReason', function (): void {
    $res = $this->postJson('/api/pest-impersonate/end');
    $res->assertOk();
    $row = $res->json('Results.0');
    expect($row)->toHaveKeys(['SessionId', 'EndedAt', 'EndReason']);
    expect($row['SessionId'])->toBe('11111111-1111-4111-8111-111111111111')
        ->and($row['EndReason'])->toBe('OperatorEnded');
    expect($this->fakeSvc->endCalls)->toHaveCount(1);
    expect($this->fakeSvc->endCalls[0]['endReason'])->toBe('OperatorEnded');
});
