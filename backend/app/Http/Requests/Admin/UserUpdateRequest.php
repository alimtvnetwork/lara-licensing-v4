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
 * `PATCH /Api/Admin/Users/{UserId}`, extracted from
 * UserController::validatePatch. Mirrors the inline rules at
 * backend/app/Http/Controllers/Admin/UserController.php lines
 * 283-291. If-Match ETag concurrency is enforced separately by
 * UserController::enforceIfMatch inside the transaction and is
 * NOT part of this request's contract.
 */
final class UserUpdateRequest extends FormRequest
{
    public const EMAIL_MAX = 255;

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
            'Email' => ['sometimes', 'email:rfc', 'max:' . self::EMAIL_MAX],
            'IsActive' => ['sometimes', 'boolean'],
            'TenantId' => ['sometimes', 'nullable', 'integer'],
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
            'User patch failed validation.',
            $errors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        return $this->validated();
    }
}
