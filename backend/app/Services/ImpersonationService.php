<?php

declare(strict_types=1);

namespace App\Services;

use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Models\AuthSession;
use App\Models\ImpersonationIndex;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Plan 06 step 43 (wire-in + shard write path). Transactional
 * impersonation lifecycle.
 *
 * Root cause this service exists (spec 46 §3, spec 47 §2):
 *   The controller cannot open an impersonation session without a
 *   transactional writer that pairs `AuthSessions`, `ImpersonationIndex`,
 *   and `AuditLogs`, respects `UX_ImpersonationIndex_OneActive`
 *   (AC-IMP-004/011), and lands the `AuthSessions` row in the target's
 *   home DB (Root for Root-scoped targets, target shard for shard-scoped
 *   targets per spec 46 §4.3.2).
 *
 * Cross-DB semantics for shard-scoped targets (spec 46 §4.3.5):
 *   True 2PC across two Postgres DBs would require `PREPARE TRANSACTION`
 *   on each connection. We instead use an ordered saga:
 *     1. Root tx OPEN.
 *     2. INSERT Root `ImpersonationIndex` (unique gate, AC-IMP-004/011).
 *     3. INSERT Root `AuditLogs`.
 *     4. Resolve target shard, bind, INSERT shard `AuthSessions`.
 *     5. Root tx COMMIT.
 *   Failure at step 4 rolls back the Root tx and throws. Failure at
 *   step 5 (Root commit) triggers a best-effort compensating DELETE of
 *   the shard row, with the anomaly logged at ERROR for reconcile.
 */
final class ImpersonationService
{
    private const ROOT = 'root';
    private const SQLSTATE_UNIQUE = '23505';
    private const AUDIT_ACTION_STARTED = 'ImpersonationStarted';
    private const AUDIT_ACTION_ENDED = 'ImpersonationEnded';
    private const AUDIT_ACTOR_USER = 'User';
    private const AUDIT_TARGET_TYPE = 'Users';

    public function __construct(
        private readonly AuthSessionService $sessions,
        private readonly ShardResolver $shards,
    ) {}

    /**
     * @return array{SessionId:string, ImpersonatorUserId:int, TargetUserId:int, Kind:string, ParentSessionId:string, ExpiresAt:string, Reason:string, Token:string}
     */
    public function begin(User $operator, AuthSession $parent, User $target, string $reason, string $requestId): array
    {
        $this->assertParentUsable($operator, $parent);
        $this->assertTargetEligible($target);

        return $this->runBeginSaga($operator, $parent, $target, $reason, $requestId);
    }


    /**
     * @return array{SessionId:string, EndedAt:string, EndReason:string}
     */
    public function end(User $caller, AuthSession $impersonation, string $endReason, string $requestId): array
    {
        $normalized = $this->normalizeEndReason($endReason);

        return DB::connection(self::ROOT)->transaction(
            fn (): array => $this->closePair($caller, $impersonation, $normalized, $requestId)
        );
    }

    /**
     * Plan 06 step 43 (forceEnd). Admin-initiated termination of any
     * active impersonation session (spec 47 §2 "AdminForced"). The
     * caller is the Admin, not the impersonator; the audit row records
     * the Admin as actor while the closed `AuthSessions`/`ImpersonationIndex`
     * rows preserve the original `ImpersonatorUserId`/`TargetUserId`.
     * AC-IMP-006 (session ends closed under AdminForced), AC-IMP-007
     * (impersonator lineage preserved in audit payload).
     *
     * @return array{SessionId:string, EndedAt:string, EndReason:string}
     */
    public function forceEnd(User $admin, string $sessionId, string $requestId): array
    {
        if ($sessionId === '') {
            throw ValidationException::validationFailed( 'SessionId is required.', [['Field' => 'SessionId', 'Rule' => 'Required']]);
        }
        $stub = new AuthSession();
        $stub->SessionId = $sessionId;

        return DB::connection(self::ROOT)->transaction(
            fn (): array => $this->closePair($admin, $stub, ImpersonationIndex::END_ADMIN_FORCED, $requestId)
        );
    }


    private function assertParentUsable(User $operator, AuthSession $parent): void
    {
        if ($parent->Kind !== AuthSession::KIND_NORMAL) {
            throw DomainConflictException::custom('ImpersonationParentSessionInvalid', 'Parent session must be a Normal session.', [['Field' => 'ParentSessionId', 'Rule' => 'KindNotNormal', 'Value' => (string) $parent->Kind]]);
        }
        if ((int) $parent->UserId !== (int) $operator->getKey() || !$parent->isActive()) {
            throw DomainConflictException::custom('ImpersonationParentSessionInvalid', 'Parent session is not active or does not belong to the caller.', [['Field' => 'ParentSessionId', 'Rule' => 'NotActive', 'Value' => (string) $parent->SessionId]]);
        }
    }

    private function assertTargetEligible(User $target): void
    {
        $isFailed = !$target->IsActive;
        if ($isFailed) {
            throw NotFoundException::notFound('UserNotFound', 'Target user is deactivated.', [['Field' => 'UserId', 'Rule' => 'Deactivated', 'Value' => (string) $target->getKey()]]);
        }
        if ($this->userHasAdminRole((int) $target->getKey())) {
            throw AuthException::custom('AuthzPermissionDenied', 'Cannot impersonate an Admin target.', [['Field' => 'UserId', 'Rule' => 'TargetIsAdmin', 'Value' => (string) $target->getKey()]]);
        }
    }

    private function userHasAdminRole(int $userId): bool
    {
        return DB::connection(self::ROOT)
            ->table('UserRoles as ur')
            ->join('Roles as r', 'ur.RoleId', '=', 'r.RoleId')
            ->where('ur.UserId', $userId)
            ->where('r.RoleName', 'Admin')
            ->exists();
    }

    /**
     * @return array{SessionId:string, ImpersonatorUserId:int, TargetUserId:int, Kind:string, ParentSessionId:string, ExpiresAt:string, Reason:string, Token:string}
     */
    private function runBeginSaga(User $operator, AuthSession $parent, User $target, string $reason, string $requestId): array
    {
        $ttl = (int) config('lara.impersonation_ttl_minutes', 30);
        $now = Carbon::now();
        $sessionId = Uuid::uuid4()->toString();
        $expires = $now->copy()->addMinutes($ttl);
        $shardConn = $this->resolveTargetShardConnection($target);
        $insertedInShard = false;
        try {
            DB::connection(self::ROOT)->beginTransaction();
            $this->insertIndex($sessionId, $operator, $target, $now);
            $this->insertAuditRow($sessionId, $operator, $target, self::AUDIT_ACTION_STARTED, ['Reason' => $reason], $requestId);
            $this->insertAuthSessionOn($shardConn, $sessionId, $operator, $parent, $target, $now, $expires);
            $insertedInShard = true;
            DB::connection(self::ROOT)->commit();
        } catch (Throwable $e) {
            DB::connection(self::ROOT)->rollBack();
            if ($insertedInShard && $shardConn !== self::ROOT) {
                $this->compensateShardInsert($shardConn, $sessionId, $e);
            }
            throw $e;
        }
        // Mint a Sanctum bearer on the TARGET user, pinned to the session
        // ExpiresAt (spec 46 §4.1). Ability tag `impersonation` lets the
        // banner middleware distinguish these tokens without another DB hop.
        $plainToken = $target->createToken($sessionId, ['impersonation'], $expires)->plainTextToken;
        Log::info('impersonation.begin', ['CallerUserId' => (int) $operator->getKey(), 'TargetUserId' => (int) $target->getKey(), 'SessionId' => $sessionId, 'ParentSessionId' => (string) $parent->SessionId, 'ShardConnection' => $shardConn, 'RequestId' => $requestId]);

        return ['SessionId' => $sessionId, 'ImpersonatorUserId' => (int) $operator->getKey(), 'TargetUserId' => (int) $target->getKey(), 'Kind' => AuthSession::KIND_IMPERSONATION, 'ParentSessionId' => (string) $parent->SessionId, 'ExpiresAt' => $expires->format('Y-m-d\TH:i:s\Z'), 'Reason' => $reason, 'Token' => $plainToken];
    }

    private function resolveTargetShardConnection(User $target): string
    {
        if ($target->TenantId === null) {
            return self::ROOT;
        }
        $reseller = Reseller::query()->whereKey((int) $target->TenantId)->first();
        if ($reseller === null || (string) $reseller->ResellerSlug === '') {
            throw NotFoundException::notFound('ResellerNotFound', 'Target user references a reseller that no longer exists.', [['Field' => 'TenantId', 'Rule' => 'MissingReseller', 'Value' => (string) $target->TenantId]]);
        }
        $this->shards->bind((string) $reseller->ResellerSlug);

        return ShardResolver::alias();
    }

    private function insertAuthSessionOn(string $connection, string $sessionId, User $operator, AuthSession $parent, User $target, Carbon $now, Carbon $expires): void
    {
        try {
            DB::connection($connection)->table('AuthSessions')->insert([
                'SessionId' => $sessionId,
                'UserId' => (int) $target->getKey(),
                'Kind' => AuthSession::KIND_IMPERSONATION,
                'ImpersonatorUserId' => (int) $operator->getKey(),
                'ParentSessionId' => (string) $parent->SessionId,
                'CreatedAt' => $now,
                'ExpiresAt' => $expires,
            ]);
        } catch (QueryException $e) {
            $this->translateUniqueViolation($e, (int) $operator->getKey());
        }
    }

    private function compensateShardInsert(string $shardConn, string $sessionId, Throwable $cause): void
    {
        try {
            DB::connection($shardConn)->table('AuthSessions')->where('SessionId', $sessionId)->delete();
            Log::warning('impersonation.begin.compensated', ['SessionId' => $sessionId, 'ShardConnection' => $shardConn, 'Cause' => $cause->getMessage()]);
        } catch (Throwable $inner) {
            Log::error('impersonation.begin.compensation_failed', ['SessionId' => $sessionId, 'ShardConnection' => $shardConn, 'Cause' => $cause->getMessage(), 'CompensationError' => $inner->getMessage()]);
        }
    }

    private function translateUniqueViolation(QueryException $e, int $operatorId): never
    {
        if ($e->getCode() === self::SQLSTATE_UNIQUE) {
            throw DomainConflictException::custom('ImpersonationAlreadyActive', 'Operator already holds an active impersonation session.', [['Field' => 'ImpersonatorUserId', 'Rule' => 'UniqueActive', 'Value' => (string) $operatorId]], $e);
        }
        throw $e;
    }

    private function insertIndex(string $sessionId, User $operator, User $target, Carbon $now): void
    {
        try {
            $row = new ImpersonationIndex();
            $row->SessionId = $sessionId;
            $row->ImpersonatorUserId = (int) $operator->getKey();
            $row->TargetUserId = (int) $target->getKey();
            $row->TargetResellerId = $target->TenantId === null ? null : (int) $target->TenantId;
            $row->StartedAt = $now;
            $row->save();
        } catch (QueryException $e) {
            $this->translateUniqueViolation($e, (int) $operator->getKey());
        }
    }


    /**
     * @param array<string,mixed> $extra
     */
    private function insertAuditRow(string $sessionId, User $operator, User $target, string $action, array $extra, string $requestId): void
    {
        $payload = array_merge(['SessionId' => $sessionId, 'TargetUserId' => (int) $target->getKey(), 'ImpersonatorUserId' => (int) $operator->getKey()], $extra);
        DB::connection(self::ROOT)->insert(
            \App\Support\AuditWriter::insertSql(self::ROOT),
            [self::AUDIT_ACTOR_USER, (int) $operator->getKey(), $action, self::AUDIT_TARGET_TYPE, (int) $target->getKey(), $requestId, (string) json_encode($payload)]
        );
    }

    /**
     * @return array{SessionId:string, EndedAt:string, EndReason:string}
     */
    private function closePair(User $caller, AuthSession $impersonation, string $endReason, string $requestId): array
    {
        $sessionId = (string) $impersonation->SessionId;
        $indexRow = ImpersonationIndex::query()->where('SessionId', $sessionId)->lockForUpdate()->first();
        if ($indexRow === null || $indexRow->EndedAt !== null) {
            throw NotFoundException::notFound('UserNotFound', 'No active impersonation session.', [['Field' => 'SessionId', 'Rule' => 'NotActive', 'Value' => $sessionId]]);
        }
        $shardConn = $this->resolveShardConnectionForIndex($indexRow);
        $now = Carbon::now();
        $updated = DB::connection($shardConn)
            ->table('AuthSessions')
            ->where('SessionId', $sessionId)
            ->whereNull('EndedAt')
            ->update(['EndedAt' => $now, 'RevokeReason' => $endReason]);
        if ($updated === 0) {
            throw NotFoundException::notFound('UserNotFound', 'AuthSessions row missing or already ended for this SessionId.', [['Field' => 'SessionId', 'Rule' => 'NotActiveInShard', 'Value' => $sessionId]]);
        }
        $this->stampIndexEnd($sessionId, $now, $endReason);
        // Plan 06 step 45: revoke the impersonation bearer so it cannot be
        // replayed until its pinned expiry. Defence-in-depth alongside
        // AssertActiveSessionMiddleware. Root connection is the source of
        // truth for personal_access_tokens.
        $this->sessions->revokeTokensForSession($sessionId);
        $target = User::query()->whereKey((int) $indexRow->TargetUserId)->first();
        $operator = User::query()->whereKey((int) $indexRow->ImpersonatorUserId)->first();
        $this->insertAuditRow($sessionId, $operator ?? $caller, $target ?? $caller, self::AUDIT_ACTION_ENDED, ['EndReason' => $endReason], $requestId);
        Log::info('impersonation.end', ['CallerUserId' => (int) $caller->getKey(), 'SessionId' => $sessionId, 'EndReason' => $endReason, 'ShardConnection' => $shardConn, 'RequestId' => $requestId]);

        return ['SessionId' => $sessionId, 'EndedAt' => $now->format('Y-m-d\TH:i:s\Z'), 'EndReason' => $endReason];
    }

    private function resolveShardConnectionForIndex(ImpersonationIndex $indexRow): string
    {
        if ($indexRow->TargetResellerId === null) {
            return self::ROOT;
        }
        $reseller = Reseller::query()->whereKey((int) $indexRow->TargetResellerId)->first();
        if ($reseller === null || (string) $reseller->ResellerSlug === '') {
            throw NotFoundException::notFound('ResellerNotFound', 'Impersonation index references a reseller that no longer exists.', [['Field' => 'TargetResellerId', 'Rule' => 'MissingReseller', 'Value' => (string) $indexRow->TargetResellerId]]);
        }
        $this->shards->bind((string) $reseller->ResellerSlug);

        return ShardResolver::alias();
    }

    private function stampIndexEnd(string $sessionId, Carbon $now, string $endReason): void
    {
        ImpersonationIndex::query()
            ->where('SessionId', $sessionId)
            ->update(['EndedAt' => $now, 'EndReason' => $endReason]);
    }


    private function normalizeEndReason(string $reason): string
    {
        $client = [ImpersonationIndex::END_OPERATOR, ImpersonationIndex::END_ADMIN_FORCED];
        if (in_array($reason, $client, true) === false) {
            throw ValidationException::validationFailed( 'EndReason must be OperatorEnded or AdminForced.', [['Field' => 'EndReason', 'Rule' => 'ClosedSet', 'Value' => $reason]]);
        }

        return $reason;
    }
}
