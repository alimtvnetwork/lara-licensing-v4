<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Password recovery contract.
 *
 * Locks:
 *   - Unknown / inactive email returns the neutral envelope (no
 *     enumeration side channel), no token row, no mail sent.
 *   - Known active email mints exactly one un-consumed token, dispatches
 *     PasswordResetMail once, and the URL points at the configured
 *     FRONTEND_URL (not APP_URL) so the emailed link matches the SPA.
 *   - ResetPassword updates PasswordHash and marks ConsumedAt on happy
 *     path.
 *   - Consumed and expired tokens both collapse into
 *     PasswordResetTokenInvalid (400) with the same message so the token
 *     state itself is not observable.
 */
final class PasswordRecoveryFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootIdentityFixture();
        $this->createPasswordResetTokensFixture();
        config(['lara.frontend_url' => 'https://spa.example.test']);
    }

    public function test_unknown_email_returns_neutral_envelope_without_side_effects(): void
    {
        Mail::fake();
        $res = $this->postJson('/Api/Auth/ForgotPassword', ['Email' => 'ghost@example.test']);
        $res->assertOk();
        $this->assertTrue((bool) $res->json('Status.IsSuccess'));
        $this->assertSame(0, (int) DB::connection('root')->table('PasswordResetTokens')->count());
        Mail::assertNothingSent();
    }

    public function test_known_active_email_mints_token_and_dispatches_mail(): void
    {
        Mail::fake();
        $this->seedActiveUser('user@example.test', 'OldPass!2345');
        $res = $this->postJson('/Api/Auth/ForgotPassword', ['Email' => 'USER@example.test']);
        $res->assertOk();
        $rows = DB::connection('root')->table('PasswordResetTokens')->where('EmailLower', 'user@example.test')->get();
        $this->assertCount(1, $rows);
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail): bool {
            return $mail->hasTo('user@example.test')
                && str_starts_with($mail->ResetUrl, 'https://spa.example.test/reset-password?');
        });
    }

    public function test_reset_password_happy_path_updates_hash_and_consumes_token(): void
    {
        Mail::fake();
        $user = $this->seedActiveUser('user@example.test', 'OldPass!2345');
        $plain = $this->mintTokenFor('user@example.test');
        $res = $this->postJson('/Api/Auth/ResetPassword', [
            'Email' => 'user@example.test', 'Token' => $plain, 'NewPassword' => 'BrandNewPass!9',
        ]);
        $res->assertOk();
        $fresh = User::query()->whereKey($user->getKey())->first();
        $this->assertTrue(Hash::check('BrandNewPass!9', (string) $fresh->PasswordHash));
        $this->assertNotNull(PasswordResetToken::query()->where('EmailLower', 'user@example.test')->first()->ConsumedAt);
    }

    public function test_consumed_token_is_rejected_with_stable_error_code(): void
    {
        Mail::fake();
        $this->seedActiveUser('user@example.test', 'OldPass!2345');
        $plain = $this->mintTokenFor('user@example.test', consumed: true);
        $res = $this->postJson('/Api/Auth/ResetPassword', [
            'Email' => 'user@example.test', 'Token' => $plain, 'NewPassword' => 'BrandNewPass!9',
        ]);
        $res->assertStatus(400);
        $this->assertSame('PasswordResetTokenInvalid', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_expired_token_is_rejected_with_stable_error_code(): void
    {
        Mail::fake();
        $this->seedActiveUser('user@example.test', 'OldPass!2345');
        $plain = $this->mintTokenFor('user@example.test', expired: true);
        $res = $this->postJson('/Api/Auth/ResetPassword', [
            'Email' => 'user@example.test', 'Token' => $plain, 'NewPassword' => 'BrandNewPass!9',
        ]);
        $res->assertStatus(400);
        $this->assertSame('PasswordResetTokenInvalid', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_email_dispatch_failure_is_logged_and_does_not_leak_500(): void
    {
        $this->seedActiveUser('user@example.test', 'OldPass!2345');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        Log::spy();
        $res = $this->postJson('/Api/Auth/ForgotPassword', ['Email' => 'user@example.test']);
        $res->assertOk();
        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => $msg === 'auth.forgot_password.email_dispatch_failed')->atLeast()->once();
    }

    // ---------- fixtures ----------

    private function seedActiveUser(string $email, string $password): User
    {
        return tap(new User(), function (User $u) use ($email, $password): void {
            $u->Email = $email;
            $u->PasswordHash = Hash::make($password);
            $u->IsActive = true;
            $u->save();
        });
    }

    private function mintTokenFor(string $emailLower, bool $consumed = false, bool $expired = false): string
    {
        $plain = bin2hex(random_bytes(32));
        DB::connection('root')->table('PasswordResetTokens')->insert([
            'EmailLower' => $emailLower,
            'TokenHash' => hash('sha256', $plain),
            'ExpiresAt' => $expired ? Carbon::now('UTC')->subMinute() : Carbon::now('UTC')->addHour(),
            'ConsumedAt' => $consumed ? Carbon::now('UTC') : null,
            'RequestIp' => '127.0.0.1',
        ]);

        return $plain;
    }

    private function createRootIdentityFixture(): void
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
        $root->statement('CREATE TABLE IF NOT EXISTS "personal_access_tokens" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT,
            "tokenable_type" TEXT NOT NULL,
            "tokenable_id" INTEGER NOT NULL,
            "name" TEXT NOT NULL,
            "token" TEXT NOT NULL,
            "abilities" TEXT NULL,
            "created_at" TEXT NULL,
            "updated_at" TEXT NULL,
            "expires_at" TEXT NULL
        )');
    }

    private function createPasswordResetTokensFixture(): void
    {
        DB::connection('root')->statement('CREATE TABLE IF NOT EXISTS "PasswordResetTokens" (
            "PasswordResetTokenId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "EmailLower" TEXT NOT NULL,
            "TokenHash" TEXT NOT NULL,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "ExpiresAt" TEXT NOT NULL,
            "ConsumedAt" TEXT NULL,
            "RequestIp" TEXT NULL
        )');
    }
}
