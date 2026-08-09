<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 06 step 43 + Plan 09 login modernization. Login payload validator.
 *
 * Adds RememberMe (opt-in longer session TTL) and CAPTCHA fields. Captcha
 * enforcement (required-after-N-failures) lives in LoginController; this
 * FormRequest only validates shape when fields are present.
 */
final class LoginRequest extends FormRequest
{
    private const EMAIL_MAX = 254;
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 4096;
    private const CAPTCHA_ID_MAX = 512;
    private const CAPTCHA_ANSWER_MAX = 16;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'Email' => ['required', 'email:rfc', 'max:' . self::EMAIL_MAX],
            'Password' => ['required', 'string', 'min:' . self::PASSWORD_MIN, 'max:' . self::PASSWORD_MAX],
            'RememberMe' => ['sometimes', 'boolean'],
            'CaptchaChallengeId' => ['sometimes', 'nullable', 'string', 'max:' . self::CAPTCHA_ID_MAX],
            'CaptchaAnswer' => ['sometimes', 'nullable', 'string', 'max:' . self::CAPTCHA_ANSWER_MAX],
        ];
    }
}
