<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Support\ApiEnvelope;
use App\Support\LoginCaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Plan 09 login modernization. GET /Api/Auth/Captcha.
 *
 * Issues a stateless HMAC-signed math CAPTCHA. Public endpoint; the response
 * carries the ChallengeId (opaque wire token), Question (human-readable),
 * and ExpiresAt (ISO-8601 UTC). LoginController verifies via LoginCaptcha.
 */
final class CaptchaController
{
    public function __invoke(Request $request): JsonResponse
    {
        $challenge = LoginCaptcha::issue();
        $requestId = (string) $request->attributes->get('X-Request-Id', '');
        Log::info('auth.login.captcha_issued', ['RequestId' => $requestId]);

        return ApiEnvelope::success([$challenge], $requestId);
    }
}
