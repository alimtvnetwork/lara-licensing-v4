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
 * Plan 10 step 2 (phase C-cont). Body validation for
 * `POST /Api/Admin/Impersonation/End`, extracted from
 * UserController::validateEndReason (see
 * backend/app/Http/Controllers/Admin/UserController.php lines
 * 197-202). Closed set matches spec 47 (`OperatorEnded`,
 * `AdminForced`).
 */
final class ImpersonateEndRequest extends FormRequest
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
            'EndReason' => ['required', 'string', 'in:OperatorEnded,AdminForced'],
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
            'Impersonation end payload failed validation.',
            $errors,
        );
    }

    public function endReason(): string
    {
        /** @var array{EndReason:string} $v */
        $v = $this->validated();

        return (string) $v['EndReason'];
    }
}
