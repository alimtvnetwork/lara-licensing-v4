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
 * `GET /Api/Admin/Licenses/{LicenseKey}/Bindings?ResellerSlug=...`,
 * extracted from BindingController inline `requireResellerSlug`.
 * The License key is a route param; slug shape mirrors spec 08
 * (Reseller registry) `^[a-z][a-z0-9-]{2,63}$`.
 */
final class BindingIndexRequest extends FormRequest
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
            'Binding index query failed validation.',
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
