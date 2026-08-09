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
 * Plan 10 step 2 (phase D-1). Body validation for
 * `POST /Api/Reseller/QuotaRequests`.
 *
 * Root cause of the parity gap (one sentence): the reseller store
 * handler validated the four body fields inline, so the endpoint
 * audit sensor flagged the route as `missing-request`.
 *
 * Closed-set membership (`LicenseCategoryId`, `LicenseTierId` must
 * live in the `lara.license_categories` / `lara.license_tiers`
 * config catalogs) is intentionally still enforced in the
 * controller after basic shape validation, because that check
 * emits the specific `Rule = ClosedSetMember` marker used by
 * frontend copy and by the closed-set snapshot tests.
 */
final class QuotaRequestStoreRequest extends FormRequest
{
    public const REQUESTED_DELTA_MIN = 1;
    public const REQUESTED_DELTA_MAX = 100000;
    public const JUSTIFICATION_MIN = 8;
    public const JUSTIFICATION_MAX = 1024;

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
            'LicenseCategoryId' => ['required', 'integer', 'min:1'],
            'LicenseTierId' => ['required', 'integer', 'min:1'],
            'RequestedDelta' => ['required', 'integer', 'min:' . self::REQUESTED_DELTA_MIN, 'max:' . self::REQUESTED_DELTA_MAX],
            'Justification' => ['required', 'string', 'min:' . self::JUSTIFICATION_MIN, 'max:' . self::JUSTIFICATION_MAX],
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
            'QuotaRequest.Store payload failed validation.',
            $errors,
        );
    }

    /**
     * @return array{LicenseCategoryId:int, LicenseTierId:int, RequestedDelta:int, Justification:string}
     */
    public function payload(): array
    {
        /** @var array{LicenseCategoryId:int, LicenseTierId:int, RequestedDelta:int, Justification:string} $v */
        $v = $this->validated();

        return [
            'LicenseCategoryId' => (int) $v['LicenseCategoryId'],
            'LicenseTierId' => (int) $v['LicenseTierId'],
            'RequestedDelta' => (int) $v['RequestedDelta'],
            'Justification' => (string) $v['Justification'],
        ];
    }
}
