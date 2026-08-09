<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Services\EnvironmentService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 10 step 2 (phase D-3). FormRequest for `POST /Api/Portal/Verify/Final`.
 *
 * Root cause of the parity gap (one sentence):
 * `VerifyController::final()` validated inline via
 * `$request->validate()`, so `AuditEndpoints.php` reflection flagged
 * the route as `missing-request`.
 *
 * Wire parity: mirrors `validateFinalPayload()` +
 * `throwValidationFailedFinal()`: `EnvironmentId` maps to
 * Rule = 'MembershipRequired'; every other field maps to Rule = 'Required'.
 */
final class VerifyFinalRequest extends FormRequest
{
    public const SERIAL_VALUE_REGEX = '/^[A-Z0-9-]{8,64}$/';
    public const HASH_KEY_REGEX = '/^[A-Fa-f0-9]{32,128}$/';
    public const VERIFY_KEY_VALUE_REGEX = '/^[a-f0-9]{32}$/';
    public const FINGERPRINT_HASH_REGEX = '/^[a-f0-9]{64}$/';
    public const USER_IDENTIFIER_REGEX = '/^[A-Za-z0-9._@:+\-]{1,255}$/';
    public const ENV_ORDINAL_MIN = 1;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        /** @var EnvironmentService $envSvc */
        $envSvc = app(EnvironmentService::class);
        $envMax = $envSvc->ordinalMax();

        return [
            'SerialValue' => ['required', 'string', 'regex:' . self::SERIAL_VALUE_REGEX],
            'HashKey' => ['required', 'string', 'regex:' . self::HASH_KEY_REGEX],
            'VerifyKey' => ['required', 'string', 'regex:' . self::VERIFY_KEY_VALUE_REGEX],
            'EnvironmentId' => ['required', 'integer', 'min:' . self::ENV_ORDINAL_MIN, 'max:' . $envMax],
            'FingerprintHash' => ['nullable', 'string', 'regex:' . self::FINGERPRINT_HASH_REGEX],
            'UserIdentifier' => ['nullable', 'string', 'regex:' . self::USER_IDENTIFIER_REGEX],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $details = [];
        foreach ($validator->errors()->toArray() as $field => $_messages) {
            $rule = $field === 'EnvironmentId' ? 'MembershipRequired' : 'Required';
            $details[] = ['Field' => (string) $field, 'Rule' => $rule];
        }
        throw ValidationException::validationFailed(
            'Verify/Final payload failed validation.',
            $details,
        );
    }

    /**
     * @return array{SerialValue:string,HashKey:string,VerifyKey:string,EnvironmentOrdinal:int,FingerprintHash:?string,UserIdentifier:?string}
     */
    public function payload(): array
    {
        /** @var array<string,mixed> $v */
        $v = $this->validated();

        return [
            'SerialValue' => (string) $v['SerialValue'],
            'HashKey' => (string) $v['HashKey'],
            'VerifyKey' => (string) $v['VerifyKey'],
            'EnvironmentOrdinal' => (int) $v['EnvironmentId'],
            'FingerprintHash' => isset($v['FingerprintHash']) ? (string) $v['FingerprintHash'] : null,
            'UserIdentifier' => isset($v['UserIdentifier']) ? (string) $v['UserIdentifier'] : null,
        ];
    }
}
