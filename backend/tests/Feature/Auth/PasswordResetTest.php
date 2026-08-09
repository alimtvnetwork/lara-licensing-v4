<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Pest matrix, `Auth/PasswordResetTest` row).
 *
 * Locks the two-endpoint password recovery flow end-to-end:
 *   - `POST /Api/Auth/ForgotPassword` (App\Http\Controllers\Auth\ForgotPasswordController)
 *   - `POST /Api/Auth/ResetPassword`  (App\Http\Controllers\Auth\ResetPasswordController)
 *
 * Root cause guarded (one sentence): v0.295.0 shipped the recovery
 * substrate (PasswordResetTokens table, mail template, rate-limit
 * middleware) but no HTTP-level lock existed, so a refactor that
 * enumerated unknown emails (different envelope for unknown vs known),
 * stored the plaintext token instead of its sha256, forgot to consume
 * the row on success (replayable reset link), forgot to revoke live
 * Sanctum PATs on password change (stolen session survives a reset,
 * violating spec 46 §4.3), or leaked the failure reason via distinct
 * error codes for Unknown / Expired / Consumed would all ship green.
 *
 * Branches guarded:
 *   1. `ForgotPassword` for an active user: 200 envelope, persisted
 *      `PasswordResetTokens` row with `TokenHash` = sha256(plain) and
 *      `ExpiresAt` in the future, `auth.forgot_password.token_minted`
 *      log line fires. Any prior unconsumed row for the same email
 *      is stamped `ConsumedAt` (single live token per address).
 *   2. `ForgotPassword` for an unknown email: identical 200 envelope
 *      (no enumeration), NO new row written, and
 *      `auth.forgot_password.unknown_or_inactive` fires instead of
 *      `token_minted`.
 *   3. `ResetPassword` happy path: 200, `Users.PasswordHash` rotated
 *      (old password stops verifying, new password verifies), the
 *      token row is stamped `ConsumedAt`, and every existing
 *      `personal_access_tokens` row for the user is deleted.
 *   4. `ResetPassword` with an unknown token, an already-consumed
 *      token, and an expired token all collapse to the same 400
 *      `PasswordResetTokenInvalid` envelope (no side-channel).
 */
final class PasswordResetTest extends TestCase
{
    use AssertsLaraException;

    private const EMAIL = 'reset-user@example.test';
    private const OLD_PASSWORD = 'OldPass!Word123';
    private const NEW_PASSWORD = 'NewPass!Word456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
    }

    public function test_forgot_password_active_user_mints_hashed_token_and_logs(): void
    {
        Log::spy();
        $user = $this->makeUser(active: true);

        // Seed a prior unconsumed row so we prove it gets consumed on re-mint.
        $stale = new PasswordResetToken();
        $stale->EmailLower = strtolower(self::EMAIL);
        $stale->TokenHash = str_repeat('a', 64);
        $stale->ExpiresAt = Carbon::now('UTC')->addMinutes(30);
        $stale->RequestIp = '10.0.0.1';
        $stale->save();

        $res = $this->postJson('/Api/Auth/ForgotPassword', ['Email' => self::EMAIL]);
        $res->assertStatus(200);
        $this->assertTrue($res->json('Status.IsSuccess'));

        // Prior row must be stamped consumed.
        $staleAfter = PasswordResetToken::query()->where('PasswordResetTokenId', $stale->PasswordResetTokenId)->first();
        $this->assertNotNull($staleAfter?->ConsumedAt, 'Prior unconsumed reset row must be stamped ConsumedAt when a new one is minted.');

        // Fresh row must exist, with future ExpiresAt and a sha256-shaped hash.
        $fresh = PasswordResetToken::query()
            ->where('EmailLower', strtolower(self::EMAIL))
            ->whereNull('ConsumedAt')
            ->orderByDesc('PasswordResetTokenId')
            ->first();
        $this->assertNotNull($fresh, 'ForgotPassword must persist a fresh unconsumed token for an active user.');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $fresh->TokenHash, 'TokenHash must be sha256 hex, never the plaintext.');
        $this->assertTrue(Carbon::parse((string) $fresh->ExpiresAt)->greaterThan(Carbon::now('UTC')), 'ExpiresAt must be in the future.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.forgot_password.token_minted' && ($ctx['EmailLower'] ?? null) === strtolower(self::EMAIL))
            ->atLeast()->once();

        unset($user);
    }

    public function test_forgot_password_unknown_email_returns_same_envelope_without_row(): void
    {
        Log::spy();

        $res = $this->postJson('/Api/Auth/ForgotPassword', ['Email' => 'ghost@example.test']);
        $res->assertStatus(200);
        $this->assertTrue($res->json('Status.IsSuccess'), 'Unknown email must not enumerate: envelope shape must match the active-user branch.');

        $count = PasswordResetToken::query()->where('EmailLower', 'ghost@example.test')->count();
        $this->assertSame(0, $count, 'No reset row may be written for an unknown email.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.forgot_password.unknown_or_inactive')
            ->atLeast()->once();
        Log::shouldNotHaveReceived('info', [
            'auth.forgot_password.token_minted',
            \Mockery::type('array'),
        ]);
    }

    public function test_reset_password_consumes_token_rotates_hash_and_revokes_tokens(): void
    {
        Log::spy();
        $user = $this->makeUser(active: true);

        // Simulate a live Sanctum PAT so we can prove it gets revoked.
        $user->createToken('some-session-id');
        $this->assertSame(1, DB::connection('root')->table('personal_access_tokens')->count(), 'Precondition: one live PAT.');

        [$plain, $row] = $this->mintToken(strtolower(self::EMAIL));

        $res = $this->postJson('/Api/Auth/ResetPassword', [
            'Email' => self::EMAIL,
            'Token' => $plain,
            'NewPassword' => self::NEW_PASSWORD,
        ]);
        $res->assertStatus(200);
        $this->assertTrue($res->json('Status.IsSuccess'));

        $fresh = $user->fresh();
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, (string) $fresh->PasswordHash), 'Old password must no longer verify after reset.');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $fresh->PasswordHash), 'New password must verify after reset.');

        $rowAfter = PasswordResetToken::query()->where('PasswordResetTokenId', $row->PasswordResetTokenId)->first();
        $this->assertNotNull($rowAfter?->ConsumedAt, 'Token row must be stamped ConsumedAt so the link cannot be replayed.');

        $this->assertSame(0, DB::connection('root')->table('personal_access_tokens')->count(), 'All Sanctum PATs for the user must be revoked after a password reset.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.reset_password.ok' && ($ctx['UserId'] ?? null) === (int) $user->getKey())
            ->atLeast()->once();
    }

    public function test_reset_password_unknown_token_is_rejected_with_same_error(): void
    {
        $this->makeUser(active: true);
        $res = $this->postJson('/Api/Auth/ResetPassword', [
            'Email' => self::EMAIL,
            'Token' => str_repeat('f', 64),
            'NewPassword' => self::NEW_PASSWORD,
        ]);
        $this->assertLaraException($res, 'PasswordResetTokenInvalid', 400);
    }

    public function test_reset_password_expired_token_is_rejected_with_same_error(): void
    {
        $this->makeUser(active: true);
        [$plain] = $this->mintToken(strtolower(self::EMAIL), expiresAt: Carbon::now('UTC')->subMinute());

        $res = $this->postJson('/Api/Auth/ResetPassword', [
            'Email' => self::EMAIL,
            'Token' => $plain,
            'NewPassword' => self::NEW_PASSWORD,
        ]);
        $this->assertLaraException($res, 'PasswordResetTokenInvalid', 400);
    }

    public function test_reset_password_consumed_token_is_rejected_with_same_error(): void
    {
        $this->makeUser(active: true);
        [$plain, $row] = $this->mintToken(strtolower(self::EMAIL));
        $row->ConsumedAt = Carbon::now('UTC');
        $row->save();

        $res = $this->postJson('/Api/Auth/ResetPassword', [
            'Email' => self::EMAIL,
            'Token' => $plain,
            'NewPassword' => self::NEW_PASSWORD,
        ]);
        $this->assertLaraException($res, 'PasswordResetTokenInvalid', 400);
    }

    private function createRootFixtures(): void
    {
        $root = DB::connection('root');
        $root->statement('CREATE TABLE IF NOT EXISTS "Users" (
            "UserId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "Email" TEXT NOT NULL UNIQUE,
            "PasswordHash" TEXT NOT NULL,
            "TenantId" INTEGER NULL,
            "IsActive" INTEGER NOT NULL DEFAULT 1,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "UpdatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "DeletedAt" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "PasswordResetTokens" (
            "PasswordResetTokenId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "EmailLower" TEXT NOT NULL,
            "TokenHash" TEXT NOT NULL,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "ExpiresAt" TEXT NOT NULL,
            "ConsumedAt" TEXT NULL,
            "RequestIp" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "personal_access_tokens" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT,
            "tokenable_type" TEXT NOT NULL,
            "tokenable_id" INTEGER NOT NULL,
            "name" TEXT NOT NULL,
            "token" TEXT NOT NULL UNIQUE,
            "abilities" TEXT NULL,
            "last_used_at" TEXT NULL,
            "expires_at" TEXT NULL,
            "created_at" TEXT NULL,
            "updated_at" TEXT NULL
        )');
    }

    private function makeUser(bool $active): User
    {
        $user = new User();
        $user->Email = self::EMAIL;
        $user->PasswordHash = Hash::make(self::OLD_PASSWORD);
        $user->TenantId = null;
        $user->IsActive = $active;
        $user->save();

        return $user->refresh();
    }

    /**
     * @return array{0:string,1:PasswordResetToken}
     */
    private function mintToken(string $emailLower, ?Carbon $expiresAt = null): array
    {
        $plain = bin2hex(random_bytes(32));
        $row = new PasswordResetToken();
        $row->EmailLower = $emailLower;
        $row->TokenHash = hash('sha256', $plain);
        $row->ExpiresAt = $expiresAt ?? Carbon::now('UTC')->addMinutes(60);
        $row->RequestIp = '127.0.0.1';
        $row->save();

        return [$plain, $row->refresh()];
    }
}
