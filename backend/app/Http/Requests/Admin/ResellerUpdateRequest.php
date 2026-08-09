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
 * Plan 10 step 2 (phase A). Body validation for
 * `PATCH /Api/Admin/Resellers/{ResellerSlug}`. Slug is immutable
 * (prohibited on update). See ResellerStoreRequest for background.
 */
final class ResellerUpdateRequest extends FormRequest
{
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
            'ResellerName' => ['sometimes', 'string', 'min:2', 'max:128'],
            'ResellerSlug' => ['prohibited', 'string', 'regex:' . ResellerStoreRequest::SLUG_REGEX],
            'ContactEmail' => ['sometimes', 'string', 'email', 'max:255'],
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
            'Reseller payload failed validation.',
            $errors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
