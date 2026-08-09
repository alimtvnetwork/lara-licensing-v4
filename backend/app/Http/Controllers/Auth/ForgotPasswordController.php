<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Mail\PasswordResetMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;


/**
 * Plan 09 password recovery. POST /Api/Auth/ForgotPassword.
 *
 * Always returns success (never enumerates). If the email exists on an
 * active Root Users row, we mint a single-use plaintext token, persist
 * its sha256 hash, and log the reset URL for out-of-band delivery. Email
 * transport lands as a separate wire-in (config-driven mailer) so this
 * endpoint is safe to expose today.
 */
final class ForgotPasswordController
{
    private const TOKEN_BYTES = 32;
    private const TTL_MINUTES = 60;

    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $emailRaw = (string) $request->validated()['Email'];
        $emailLower = strtolower(trim($emailRaw));
        $requestId = (string) $request->attributes->get('lara.request_id', '');

        $user = User::query()->whereRaw('LOWER("Email") = ?', [$emailLower])->first();
        if ($user !== null && (bool) $user->IsActive) {
            $this->mintAndPersistToken($emailLower, $request->ip(), $requestId);
        } else {
            Log::info('auth.forgot_password.unknown_or_inactive', ['EmailLower' => $emailLower, 'RequestId' => $requestId]);
        }

        return ApiEnvelope::success([[
            'Message' => 'If an account exists for that email, a reset link has been generated.',
        ]], $requestId);
    }

    private function mintAndPersistToken(string $emailLower, ?string $ip, string $requestId): void
    {
        $plain = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash = hash('sha256', $plain);
        $expires = Carbon::now('UTC')->addMinutes(self::TTL_MINUTES);
        $this->persistToken($emailLower, $hash, $expires, $ip);
        $resetUrl = $this->buildResetUrl($emailLower, $plain);
        $expiresIso = $expires->toIso8601String();
        Log::info('auth.forgot_password.token_minted', ['EmailLower' => $emailLower, 'RequestId' => $requestId, 'ExpiresAt' => $expiresIso]);
        $this->dispatchMail($emailLower, $resetUrl, $expiresIso, $requestId);
    }

    private function persistToken(string $emailLower, string $hash, Carbon $expires, ?string $ip): void
    {
        DB::connection('root')->transaction(function () use ($emailLower, $hash, $expires, $ip): void {
            PasswordResetToken::query()
                ->where('EmailLower', $emailLower)
                ->whereNull('ConsumedAt')
                ->update(['ConsumedAt' => Carbon::now('UTC')]);
            PasswordResetToken::create(['EmailLower' => $emailLower, 'TokenHash' => $hash, 'ExpiresAt' => $expires, 'RequestIp' => $ip]);
        });
    }

    private function buildResetUrl(string $emailLower, string $plain): string
    {
        $frontendBase = (string) (config('lara.frontend_url') ?? config('app.url') ?? '');

        return sprintf('%s/reset-password?Email=%s&Token=%s', rtrim($frontendBase, '/'), urlencode($emailLower), $plain);
    }

    private function dispatchMail(string $emailLower, string $resetUrl, string $expiresIso, string $requestId): void
    {
        try {
            Mail::to($emailLower)->send(new PasswordResetMail($resetUrl, $expiresIso));
            Log::info('auth.forgot_password.email_dispatched', ['EmailLower' => $emailLower, 'RequestId' => $requestId]);
        } catch (Throwable $failure) {
            Log::error('auth.forgot_password.email_dispatch_failed', [
                'EmailLower' => $emailLower, 'RequestId' => $requestId,
                'Exception' => $failure::class, 'Message' => $failure->getMessage(),
            ]);
        }
    }
}


