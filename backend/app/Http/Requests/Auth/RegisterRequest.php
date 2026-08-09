<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bootstrap registration payload validator.
 *
 * Only the very first Root user is accepted by RegisterController; the
 * controller enforces that gate atomically. This FormRequest only shapes
 * input. Password thresholds mirror Admin\UserController create rules
 * (min 12) so a bootstrap SuperAdmin cannot be weaker than a later-issued
 * account.
 */
final class RegisterRequest extends FormRequest
{
    private const EMAIL_MAX = 254;
    private const PASSWORD_MIN = 12;
    private const PASSWORD_MAX = 128;

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
        ];
    }
}
