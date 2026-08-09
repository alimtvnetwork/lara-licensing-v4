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
 * `POST /Api/Admin/Licenses/{LicenseKey}/Revoke` and its REST-symmetric
 * alias `DELETE /Api/Admin/Licenses/{LicenseKey}`.
 *
 * Root cause of the parity gap (one sentence): the revoke handler
 * validated the Reason field inline via `$request->validate()`, so
 * the audit sensor flagged both revoke and destroy as `missing-request`.
 */
final class LicenseRevokeRequest extends FormRequest
{
    public const REASON_MAX = 512;

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
            'Reason' => ['required', 'string', 'min:1', 'max:' . self::REASON_MAX],
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

    public function reason(): string
    {
        /** @var array{Reason:string} $v */
        $v = $this->validated();

        return (string) $v['Reason'];
    }
}
