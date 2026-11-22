<?php

declare(strict_types=1);

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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

        return [
            'name' => [
                'required',
                'string',
                'max:125',
                Rule::unique($rolesTable, 'name')->where('guard_name', 'web'),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'is_super_admin' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists($permissionsTable, 'name')->where('guard_name', 'web'),
            ],
        ];
    }
}
