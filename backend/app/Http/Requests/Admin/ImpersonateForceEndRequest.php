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
 * Plan 10 phase 2C. Route-shape validation for
 * `POST /Api/Admin/Impersonation/{SessionId}/ForceEnd`. Confirms
 * SessionId is a bounded string so downstream services can rely on
 * shape without re-guarding. Existence and Active-state checks stay
 * in ImpersonationService.
 */
final class ImpersonateForceEndRequest extends FormRequest
{
    public const SESSION_ID_MAX = 128;
    public const SESSION_ID_REGEX = '/^[A-Za-z0-9_-]{8,128}$/';

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
            'SessionId' => ['required', 'string', 'max:' . self::SESSION_ID_MAX, 'regex:' . self::SESSION_ID_REGEX],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge(parent::all(), [
            'SessionId' => (string) $this->route('SessionId', ''),
        ]);
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
            'ForceEnd session id failed validation.',
            $errors,
        );
    }

    public function sessionId(): string
    {
        /** @var array{SessionId:string} $v */
        $v = $this->validated();

        return (string) $v['SessionId'];
    }
}
