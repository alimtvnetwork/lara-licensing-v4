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
 * Plan 10 step 2 (phase C-cont). Body validation for
 * `POST /Api/Admin/Users/{UserId}/Impersonate`, extracted from
 * UserController::validateImpersonationReason (see
 * backend/app/Http/Controllers/Admin/UserController.php lines
 * 186-195). Trims Reason in prepareForValidation() so a purely
 * whitespace payload fails `min:8` after normalisation, matching
 * the prior `NotBlank` guard exactly.
 */
final class ImpersonateBeginRequest extends FormRequest
{
    public const REASON_MIN = 8;
    public const REASON_MAX = 500;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('Reason');
        if (is_string($reason)) {
            $this->merge(['Reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'Reason' => ['required', 'string', 'min:' . self::REASON_MIN, 'max:' . self::REASON_MAX],
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
            'Impersonation payload failed validation.',
            $errors,
        );
    }

    public function reason(): string
    {
        /** @var array{Reason:string} $v */
        $v = $this->validated();

        return (string) $v['Reason'];
    }
}
