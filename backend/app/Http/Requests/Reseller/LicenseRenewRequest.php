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
 * `PATCH /Api/Reseller/Licenses/{LicenseKey}/Renew`.
 *
 * Root cause of the parity gap (one sentence): the renew handler
 * validated the ExpiresAt field inline via `$request->validate()`, so
 * the audit sensor flagged the route as `missing-request`. The
 * `Future` check remains in the controller so the wire-facing rule
 * label stays identical.
 */
final class LicenseRenewRequest extends FormRequest
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
            'ExpiresAt' => ['required', 'string', 'date'],
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
            'License renew payload failed validation.',
            $errors,
        );
    }

    public function expiresAt(): string
    {
        /** @var array{ExpiresAt:string} $v */
        $v = $this->validated();

        return (string) $v['ExpiresAt'];
    }
}
