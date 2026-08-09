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
 * `POST /Api/Admin/QuotaRequests/{RequestId}/Approve`.
 *
 * Root cause of the parity gap (one sentence): the admin approve
 * handler validated `ApprovedDelta`/`PeriodStart`/`PeriodEnd` inline
 * via `$request->validate()`, so the endpoint audit sensor could
 * not see a FormRequest parameter and flagged the route as
 * `missing-request`.
 *
 * The controller still enforces the strict Period ordering rule
 * (PeriodEnd > PeriodStart) because that check needs Carbon
 * parsing plus the specific `Rule = GreaterThan` marker that
 * spec 42 v1.1.0 documents; keeping it there preserves the wire
 * envelope emitted before this refactor.
 */
final class QuotaRequestApproveRequest extends FormRequest
{
    public const APPROVED_DELTA_MIN = 1;
    public const APPROVED_DELTA_MAX = 100000;

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
            'ApprovedDelta' => ['required', 'integer', 'min:' . self::APPROVED_DELTA_MIN, 'max:' . self::APPROVED_DELTA_MAX],
            'PeriodStart' => ['nullable', 'date'],
            'PeriodEnd' => ['nullable', 'date'],
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
            'QuotaRequest.Approve payload failed validation.',
            $errors,
        );
    }

    /**
     * @return array{ApprovedDelta:int, PeriodStart?:string, PeriodEnd?:string}
     */
    public function payload(): array
    {
        /** @var array{ApprovedDelta:int, PeriodStart?:string, PeriodEnd?:string} $v */
        $v = $this->validated();

        return $v;
    }
}
