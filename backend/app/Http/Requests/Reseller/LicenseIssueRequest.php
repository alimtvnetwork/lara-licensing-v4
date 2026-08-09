<?php

declare(strict_types=1);

namespace App\Http\Requests\Reseller;

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
 * `POST /Api/Reseller/Licenses`.
 *
 * Root cause of the parity gap (one sentence): the reseller issue
 * handler validated inline via `$request->validate()`, so the audit
 * sensor flagged the route as `missing-request`. Closed-set membership
 * checks for Tier/Environment/Category remain in the controller so
 * ValidationFailed error details keep the existing `MembershipRequired`
 * rule label (wire-behaviour parity).
 */
final class LicenseIssueRequest extends FormRequest
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
            'PrefixValue' => ['required', 'string', 'max:32'],
            'TierName' => ['required', 'string'],
            'EnvironmentName' => ['required', 'string'],
            'LicenseCategory' => ['nullable', 'string'],
            'ExpiresAt' => ['nullable', 'string', 'date'],
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
    public function raw(): array
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        return $v;
    }
}
