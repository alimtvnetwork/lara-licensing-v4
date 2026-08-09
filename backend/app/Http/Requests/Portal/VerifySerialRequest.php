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
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

/**
 * Plan 10 step 2 (phase D-3). FormRequest for `POST /Api/Portal/Verify/Serial`.
 *
 * Root cause of the parity gap (one sentence):
 * `VerifyController::serial()` validated inline via
 * `$request->validate()`, so `AuditEndpoints.php` reflection flagged
 * the route as `missing-request`.
 *
 * Wire parity: mirrors `VerifyController::validatePayload()` and
 * `throwValidationFailed()` byte-for-byte: `EnvironmentId` maps to
 * Rule = 'MembershipRequired'; every other field maps to Rule = 'Required'.
 */
final class VerifySerialRequest extends FormRequest
{
    public const SERIAL_VALUE_REGEX = '/^[A-Z0-9-]{8,64}$/';
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
        $envMax = $this->environmentMax();

        return [
            'SerialValue' => ['required', 'string', 'regex:' . self::SERIAL_VALUE_REGEX],
            'EnvironmentId' => ['required', 'integer', 'min:' . self::ENV_ORDINAL_MIN, 'max:' . $envMax],
        ];
    }

    protected function prepareForValidation(): void
    {
        $envMax = $this->environmentMax();
        if ($envMax < self::ENV_ORDINAL_MIN) {
            throw InternalException::custom('ServerConfigurationError',
                'Environments closed set is not configured.',
                [['Field' => 'Environments', 'Rule' => 'Empty']],
            )
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        $details = [];
        foreach ($validator->errors()->toArray() as $field => $_messages) {
            $rule = $field === 'EnvironmentId' ? 'MembershipRequired' : 'Required';
            $details[] = ['Field' => (string) $field, 'Rule' => $rule];
        }
        throw ValidationException::validationFailed(
            'Verify/Serial payload failed validation.',
            $details,
        );
    }

    /**
     * @return array{SerialValue:string, EnvironmentOrdinal:int}
     */
    public function payload(): array
    {
        /** @var array{SerialValue:string, EnvironmentId:int} $v */
        $v = $this->validated();

        return [
            'SerialValue' => (string) $v['SerialValue'],
            'EnvironmentOrdinal' => (int) $v['EnvironmentId'],
        ];
    }

    private function environmentMax(): int
    {
        return count((array) Config::get('lara.environments', []));
    }
}
