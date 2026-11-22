<?php

declare(strict_types=1);

namespace App\Http\Requests\Access;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rolesTable = (string) config('permission.table_names.roles', 'roles');
        $permissionsTable = (string) config('permission.table_names.permissions', 'permissions');
        $role = $this->route('role');
        $roleId = $role instanceof Role ? $role->getKey() : $role;

        return [
            'name' => [
                'required',
                'string',
                'max:125',
                Rule::unique($rolesTable, 'name')
                    ->where('guard_name', 'web')
                    ->ignore($roleId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists($permissionsTable, 'name')->where('guard_name', 'web'),
            ],
        ];
    }
}
