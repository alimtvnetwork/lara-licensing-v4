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
 * Plan 10 step 2 (phase D-1). Body validation for
 * `POST /Api/Admin/QuotaRequests/{RequestId}/Deny`.
 *
 * Root cause of the parity gap (one sentence): the admin deny
 * handler validated `DenialReason` inline, so the audit sensor
 * flagged the route as `missing-request`.
 */
final class QuotaRequestDenyRequest extends FormRequest
{
    public const DENIAL_REASON_MIN = 8;
    public const DENIAL_REASON_MAX = 1024;

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
            'DenialReason' => ['required', 'string', 'min:' . self::DENIAL_REASON_MIN, 'max:' . self::DENIAL_REASON_MAX],
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
            'QuotaRequest.Deny payload failed validation.',
            $errors,
        );
    }

    public function denialReason(): string
    {
        /** @var array{DenialReason:string} $v */
        $v = $this->validated();

        return (string) $v['DenialReason'];
    }
}
