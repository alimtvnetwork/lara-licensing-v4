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
 * Plan 10 step 2 (phase C). Body validation for
 * `POST /Api/Admin/Users/{UserId}/Roles`, extracted from
 * UserController::validateRoleName (see
 * backend/app/Http/Controllers/Admin/UserController.php lines
 * 293-300). The closed-set membership check against the Roles
 * table (requireKnownRole) remains in the controller because it
 * consumes a DB read that FormRequests should not perform.
 */
final class UserAssignRoleRequest extends FormRequest
{
    public const ROLE_NAME_MAX = 32;

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
            'RoleName' => ['required', 'string', 'max:' . self::ROLE_NAME_MAX],
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
            'Role assignment payload failed validation.',
            $errors,
        );
    }

    public function roleName(): string
    {
        /** @var array{RoleName:string} $v */
        $v = $this->validated();

        return (string) $v['RoleName'];
    }
}
