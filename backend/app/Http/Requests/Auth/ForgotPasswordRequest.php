<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 09 password recovery. Forgot password payload validator.
 *
 * Accepts an Email only. Response is always success (never enumerates).
 */
final class ForgotPasswordRequest extends FormRequest
{
    private const EMAIL_MAX = 254;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'Email' => ['required', 'email:rfc', 'max:' . self::EMAIL_MAX],
        ];
    }
}
