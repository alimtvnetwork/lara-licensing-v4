<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 09 password recovery. Reset password payload validator.
 *
 * Token is the plaintext single-use token minted by ForgotPassword.
 * NewPassword bounds mirror LoginRequest.
 */
final class ResetPasswordRequest extends FormRequest
{
    private const EMAIL_MAX = 254;
    private const TOKEN_MAX = 128;
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 4096;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'Email' => ['required', 'email:rfc', 'max:' . self::EMAIL_MAX],
            'Token' => ['required', 'string', 'min:32', 'max:' . self::TOKEN_MAX],
            'NewPassword' => ['required', 'string', 'min:' . self::PASSWORD_MIN, 'max:' . self::PASSWORD_MAX],
        ];
    }
}
