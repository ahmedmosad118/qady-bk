<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('accounting.accounts.update');
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('name') && is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
        }

        if ($this->has('code') && is_string($this->input('code'))) {
            $merged['code'] = trim($this->input('code'));
        }

        if ($this->has('parent_id') && $this->input('parent_id') === '') {
            $merged['parent_id'] = null;
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $accountId = $account instanceof ChartOfAccount ? $account->id : (int) $account;

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('chart_of_accounts', 'id')],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('chart_of_accounts', 'code')->ignore($accountId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'account_type' => ['sometimes', 'required', Rule::enum(AccountType::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) __('accounting.attributes');
    }
}
