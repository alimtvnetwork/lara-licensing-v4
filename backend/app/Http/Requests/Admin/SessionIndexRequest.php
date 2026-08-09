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
 * Plan 10 phase 2C. Query validation for
 * `GET /Api/Admin/Users/{UserId}/Sessions?Limit=&IncludeEnded=`.
 * Extracted from SessionController::parseLimit / parseBool. The
 * UserId route segment continues to be coerced in the controller
 * because existence checks require a DB read that FormRequests
 * should not perform.
 */
final class SessionIndexRequest extends FormRequest
{
    public const LIMIT_DEFAULT = 50;
    public const LIMIT_MIN = 1;
    public const LIMIT_MAX = 200;

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
            'Limit' => ['sometimes', 'integer', 'min:' . self::LIMIT_MIN, 'max:' . self::LIMIT_MAX],
            'IncludeEnded' => ['sometimes'],
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
            'Session index query failed validation.',
            $errors,
        );
    }

    public function limit(): int
    {
        $raw = (int) ($this->validated()['Limit'] ?? self::LIMIT_DEFAULT);

        return max(self::LIMIT_MIN, min(self::LIMIT_MAX, $raw));
    }

    public function includeEnded(): bool
    {
        $raw = $this->validated()['IncludeEnded'] ?? false;

        return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN);
    }
}
