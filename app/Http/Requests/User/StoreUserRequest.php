<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rolesTable = (string) config('permission.table_names.roles', 'roles');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', 'string', Rule::enum(UserStatus::class)],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'roles' => ['nullable', 'array', 'max:1'],
            'roles.*' => ['integer', Rule::exists($rolesTable, 'id')->where('guard_name', 'web')],
        ];
    }
}
