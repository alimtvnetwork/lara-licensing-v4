<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 10 step 2 (phase C). Body validation for
 * `POST /Api/Admin/Users`, extracted from
 * UserController::validateCreate so the endpoint audit
 * (backend/app/Console/Commands/AuditEndpoints.php) can see a
 * typed FormRequest parameter on the mutation handler.
 *
 * Root cause of the parity gap (one sentence): the store handler
 * validated inline via runValidator/$request->validate(), so
 * reflection could not detect a FormRequest and flagged the route
 * as missing (backend/app/Http/Controllers/Admin/UserController.php
 * lines 68-79 and 263-278).
 */
final class UserStoreRequest extends FormRequest
{
    public const EMAIL_MAX = 255;
    public const PASSWORD_MIN = 12;
    public const PASSWORD_MAX = 128;

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
            'TenantId' => ['sometimes', 'nullable', 'integer'],
            'IsActive' => ['sometimes', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            foreach ((array) $messages as $msg) {
                $errors[] = ['Field' => (string) $field, 'Rule' => (string) $msg];
            }
        }
        throw ValidationException::validationFailed(
            'User payload failed validation.',
            $errors,
        );
    }

    /**
     * @return array{Email:string, Password:string, TenantId:?int, IsActive:bool}
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        return [
            'Email' => (string) $v['Email'],
            'Password' => (string) $v['Password'],
            'TenantId' => array_key_exists('TenantId', $v) && $v['TenantId'] !== null
                ? (int) $v['TenantId']
                : null,
            'IsActive' => (bool) ($v['IsActive'] ?? true),
        ];
    }
}
