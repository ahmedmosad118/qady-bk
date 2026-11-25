<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\Accounting\PurchaseExpensesTreatment;
use App\Services\Accounting\AccountingSettingRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountingSettingRequest extends FormRequest
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
        $rules = [
            'settings' => ['required', 'array', 'min:1'],
        ];

        foreach (AccountingSettingRegistry::keys() as $key) {
            $rules["settings.{$key}"] = ['nullable', 'integer', Rule::exists('accounts', 'id')];
        }

        $rules['settings.expenses_treatment'] = [
            'sometimes',
            'nullable',
            'string',
            Rule::in(PurchaseExpensesTreatment::values()),
        ];

        $rules['settings.default_purchase_journal_id'] = [
            'sometimes',
            'nullable',
            'integer',
            Rule::exists('journals', 'id'),
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) __('accounting.attributes');
    }
}
