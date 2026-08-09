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
 * Plan 16 step 58 (v0.563.0). Body validation for
 * `PUT /Api/Admin/RuntimeConfig`. Only the four mutable keys are accepted;
 * any other key is rejected by RuntimeConfigService with
 * `RuntimeConfigInvalidField`. Conditional Mode/ApiBaseUrl/PreviewSeed
 * invariants are enforced in the service (single source of truth).
 */
final class RuntimeConfigUpdateRequest extends FormRequest
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
            'Mode' => ['sometimes', 'string', 'in:preview,dev,production'],
            'ApiBaseUrl' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'PreviewSeed' => ['sometimes', 'string', 'in:default,empty,error'],
            'AllowRuntimeToggle' => ['sometimes', 'boolean'],
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
        throw ValidationException::validationFailed( 'RuntimeConfig payload failed validation.', $errors);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
