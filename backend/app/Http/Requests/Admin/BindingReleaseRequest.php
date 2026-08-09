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
 * Plan 10 phase 2C. Validation for
 * `POST /Api/Admin/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/Release?ResellerSlug=...`.
 * Extracted from BindingController inline validators. The
 * MachineBindingId route segment is coerced to int in the controller;
 * shape guarding for the shard slug lives here.
 */
final class BindingReleaseRequest extends FormRequest
{
    public const SLUG_REGEX = '/^[a-z][a-z0-9-]{2,63}$/';

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
            'ResellerSlug' => ['required', 'string', 'regex:' . self::SLUG_REGEX],
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
            'Binding release payload failed validation.',
            $errors,
        );
    }

    public function resellerSlug(): string
    {
        /** @var array{ResellerSlug:string} $v */
        $v = $this->validated();

        return trim((string) $v['ResellerSlug']);
    }
}
