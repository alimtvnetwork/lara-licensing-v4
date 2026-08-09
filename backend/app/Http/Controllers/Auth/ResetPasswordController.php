<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\ValidationException;


use App\Exceptions\LaraException;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Plan 09 password recovery. POST /Api/Auth/ResetPassword.
 *
 * Consumes a single-use token minted by ForgotPassword, updates the user's
 * PasswordHash, marks the token row Consumed, and revokes every active
 * Sanctum PAT for the user so a stolen session cannot survive the reset.
 * Failures collapse into `PasswordResetTokenInvalid` (400) with no side
 * channel for existence, expiry, or ownership.
 */
final class ResetPasswordController
{
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $emailLower = strtolower(trim((string) $data['Email']));
        $plainToken = (string) $data['Token'];
        $newPassword = (string) $data['NewPassword'];
        $requestId = (string) $request->attributes->get('lara.request_id', '');

        $tokenHash = hash('sha256', $plainToken);
        $row = PasswordResetToken::query()
            ->where('EmailLower', $emailLower)
            ->where('TokenHash', $tokenHash)
            ->first();
        $this->assertUsable($row, $emailLower, $requestId);

        $user = User::query()->whereRaw('LOWER("Email") = ?', [$emailLower])->first();
        if ($user === null || ! (bool) $user->IsActive) {
            Log::warning('auth.reset_password.user_missing', ['EmailLower' => $emailLower, 'RequestId' => $requestId]);
            throw ValidationException::custom('PasswordResetTokenInvalid', 'Reset token is invalid or expired.', []);
        }

        DB::connection('root')->transaction(function () use ($row, $user, $newPassword): void {
            $user->PasswordHash = Hash::make($newPassword);
            $user->save();
            $row->ConsumedAt = Carbon::now('UTC');
            $row->save();
            DB::connection('root')->table('personal_access_tokens')
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->delete();
        });
        Log::info('auth.reset_password.ok', ['UserId' => (int) $user->getKey(), 'RequestId' => $requestId]);

        return ApiEnvelope::success([['Message' => 'Password updated. Please sign in.']], $requestId);
    }

    private function assertUsable(?PasswordResetToken $row, string $emailLower, string $requestId): void
    {
        if ($row === null || $row->ConsumedAt !== null || $row->ExpiresAt < Carbon::now('UTC')) {
            Log::warning('auth.reset_password.rejected', [
                'EmailLower' => $emailLower,
                'RequestId' => $requestId,
                'Reason' => $row === null ? 'Unknown' : ($row->ConsumedAt !== null ? 'Consumed' : 'Expired'),
            ]);
            throw ValidationException::custom('PasswordResetTokenInvalid', 'Reset token is invalid or expired.', []);
        }
    }
}
