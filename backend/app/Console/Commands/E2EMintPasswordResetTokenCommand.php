<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PasswordResetToken;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Plan 10 step 30. E2E helper: mint a single-use password reset token
 * for a given email and print the plaintext token as JSON on stdout.
 *
 * Root cause this command exists: Playwright e2e cannot read the
 * plaintext token from the outbound reset email (Mail fake, no SMTP)
 * or from logs (`auth.forgot_password.token_minted` logs only the sha256
 * hash side, not the plaintext). Without a deterministic minting hook,
 * the reset spec cannot exercise the redemption path end-to-end.
 *
 * Guardrails:
 *  - Refuses to run unless `LARA_E2E_SEED=1` OR APP_ENV in [testing, ci, local].
 *  - Never returns whether the email exists; always mints when the guard
 *    passes and the User row exists+active, otherwise emits a no-op
 *    JSON with `Sent: false` so the caller can fail with context.
 *  - Uses the same TokenHash+ExpiresAt shape as
 *    `ForgotPasswordController::persistToken()` so redemption via
 *    `ResetPasswordController` succeeds unchanged.
 */
final class E2EMintPasswordResetTokenCommand extends Command
{
    protected $signature = 'e2e:mint-reset-token {email : Email address to mint a token for}';

    protected $description = 'E2E-only: mint a single-use password reset token and print the plaintext token as JSON.';

    private const TOKEN_BYTES = 32;
    private const TTL_MINUTES = 60;

    public function handle(): int
    {
        if (! $this->isEnabled()) {
            $this->error('e2e:mint-reset-token refused: APP_ENV must be testing/ci/local or LARA_E2E_SEED=1.');

            return self::FAILURE;
        }

        $emailLower = strtolower(trim((string) $this->argument('email')));
        $user = DB::connection('root')->table('users')
            ->whereRaw('LOWER("Email") = ?', [$emailLower])
            ->where('IsActive', true)
            ->first();

        if ($user === null) {
            $this->line(json_encode([
                'Sent' => false,
                'Reason' => 'user_missing_or_inactive',
                'EmailLower' => $emailLower,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $plain = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash = hash('sha256', $plain);
        $expires = Carbon::now('UTC')->addMinutes(self::TTL_MINUTES);

        DB::connection('root')->transaction(function () use ($emailLower, $hash, $expires): void {
            PasswordResetToken::query()
                ->where('EmailLower', $emailLower)
                ->whereNull('ConsumedAt')
                ->update(['ConsumedAt' => Carbon::now('UTC')]);
            PasswordResetToken::create([
                'EmailLower' => $emailLower,
                'TokenHash' => $hash,
                'ExpiresAt' => $expires,
                'RequestIp' => '127.0.0.1',
            ]);
        });

        $this->line(json_encode([
            'Sent' => true,
            'EmailLower' => $emailLower,
            'Token' => $plain,
            'ExpiresAt' => $expires->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function isE2EEnabled(): bool
    {
        if ((bool) env('LARA_E2E_SEED', false)) {
            return true;
        }
        $env = (string) env('APP_ENV', 'production');

        return in_array($env, ['testing', 'ci', 'local'], true);
    }
}
