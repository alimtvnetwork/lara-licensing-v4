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
 * Plan 10 step 2 (phase B). Body validation for
 * `PATCH /Api/Admin/Licenses/{LicenseKey}`.
 *
 * Root cause of the parity gap (one sentence): the update handler
 * validated inline via `$request->validate()`, so the audit sensor
 * flagged the route as `missing-request`.
 */
final class LicenseUpdateRequest extends FormRequest
{
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_SUSPENDED = 'Suspended';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $tiers = array_keys((array) config('lara.license_tiers', []));

        return [
            'TierName' => ['sometimes', 'string', 'in:' . implode(',', $tiers)],
            'ProductVersion' => ['sometimes', 'string', 'max:16'],
            'ExpiresAt' => ['sometimes', 'nullable', 'string', 'date'],
            'Status' => ['sometimes', 'string', 'in:' . self::STATUS_ACTIVE . ',' . self::STATUS_SUSPENDED],
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
            'License payload failed validation.',
            $errors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        return $v;
    }
}
