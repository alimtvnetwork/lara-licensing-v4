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
 * Plan 10 step 2 (phase D-3). FormRequest for `POST /Api/Portal/Verify/Hash`.
 *
 * Root cause of the parity gap (one sentence):
 * `VerifyController::hash()` validated inline, so `AuditEndpoints.php`
 * reflection flagged the route as `missing-request` even though the
 * shape rules matched spec 03 §Verify/Hash.
 *
 * Wire parity: mirrors `validateHashPayload()` + `throwValidationFailedHash()`:
 * MachineFingerprint.* fields map to Rule = 'OneOfRequired',
 * EnvironmentId maps to Rule = 'MembershipRequired', everything else
 * maps to Rule = 'Required'. The MachineKey-or-BrowserFingerprint
 * cross-field gate stays in the controller because it depends on
 * post-validation shape.
 */
final class VerifyHashRequest extends FormRequest
{
    public const SERIAL_VALUE_REGEX = '/^[A-Z0-9-]{8,64}$/';
    public const HASH_KEY_REGEX = '/^[A-Fa-f0-9]{32,128}$/';
    public const ENV_ORDINAL_MIN = 1;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->environmentMax() < self::ENV_ORDINAL_MIN) {
            throw InternalException::custom('ServerConfigurationError',
                'Environments closed set is not configured.',
                [['Field' => 'Environments', 'Rule' => 'Empty']],
            )
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $envMax = $this->environmentMax();

        return [
            'SerialValue' => ['required', 'string', 'regex:' . self::SERIAL_VALUE_REGEX],
            'HashKey' => ['required', 'string', 'regex:' . self::HASH_KEY_REGEX],
            'EnvironmentId' => ['required', 'integer', 'min:' . self::ENV_ORDINAL_MIN, 'max:' . $envMax],
            'MachineFingerprint' => ['required', 'array'],
            'MachineFingerprint.MachineKey' => ['sometimes', 'string'],
            'MachineFingerprint.BrowserFingerprint' => ['sometimes', 'string'],
            'MachineFingerprint.MotherboardSerial' => ['sometimes', 'string'],
            'MachineFingerprint.MacAddress' => ['sometimes', 'string'],
            'UserIdentifier' => ['sometimes', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $details = [];
        foreach ($validator->errors()->toArray() as $field => $_messages) {
            $rule = str_starts_with((string) $field, 'MachineFingerprint')
                ? 'OneOfRequired'
                : ($field === 'EnvironmentId' ? 'MembershipRequired' : 'Required');
            $details[] = ['Field' => (string) $field, 'Rule' => $rule];
        }
        throw ValidationException::validationFailed(
            'Verify/Hash payload failed validation.',
            $details,
        );
    }

    /**
     * @return array{SerialValue:string, HashKey:string, EnvironmentOrdinal:int, MachineFingerprint:array<string,mixed>, UserIdentifier:?string}
     */
    public function payload(): array
    {
        /** @var array<string,mixed> $v */
        $v = $this->validated();
        $fp = (array) ($v['MachineFingerprint'] ?? []);
        $hasMachineKey = isset($fp['MachineKey']) && (string) $fp['MachineKey'] !== '';
        $hasBrowserFp = isset($fp['BrowserFingerprint']) && (string) $fp['BrowserFingerprint'] !== '';
        if (!$hasMachineKey && !$hasBrowserFp) {
            throw ValidationException::validationFailed(
                'Verify/Hash MachineFingerprint requires MachineKey or BrowserFingerprint.',
                [['Field' => 'MachineFingerprint', 'Rule' => 'OneOfRequired']],
            );
        }

        return [
            'SerialValue' => (string) $v['SerialValue'],
            'HashKey' => (string) $v['HashKey'],
            'EnvironmentOrdinal' => (int) $v['EnvironmentId'],
            'MachineFingerprint' => $fp,
            'UserIdentifier' => isset($v['UserIdentifier']) ? (string) $v['UserIdentifier'] : null,
        ];
    }

    private function environmentMax(): int
    {
        return count((array) Config::get('lara.environments', []));
    }
}
