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
 * Plan 10 phase 2C. Validation for
 * `POST /Api/Admin/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/ClearCooldown?ResellerSlug=...`.
 * Spec 30 line 82 requires a non-empty `Reason` (<=500) for the
 * AdminBreakGlass audit row; the closed-set slug guard mirrors the
 * other Binding requests.
 */
final class BindingClearCooldownRequest extends FormRequest
{
    public const SLUG_REGEX = '/^[a-z][a-z0-9-]{2,63}$/';
    public const REASON_MIN = 1;
    public const REASON_MAX = 500;

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
            'ResellerSlug' => ['required', 'string', 'regex:' . self::SLUG_REGEX],
            'Reason' => ['required', 'string', 'min:' . self::REASON_MIN, 'max:' . self::REASON_MAX],
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
            'Binding clear-cooldown payload failed validation.',
            $errors,
        );
    }

    public function resellerSlug(): string
    {
        /** @var array{ResellerSlug:string,Reason:string} $v */
        $v = $this->validated();

        return trim((string) $v['ResellerSlug']);
    }

    public function reason(): string
    {
        /** @var array{ResellerSlug:string,Reason:string} $v */
        $v = $this->validated();
        $trimmed = trim((string) $v['Reason']);
        if ($trimmed === '') {
            throw ValidationException::validationFailed(
                'Reason cannot be whitespace-only.',
                [['Field' => 'Reason', 'Rule' => 'NotBlank']],
            );
        }

        return $trimmed;
    }
}
