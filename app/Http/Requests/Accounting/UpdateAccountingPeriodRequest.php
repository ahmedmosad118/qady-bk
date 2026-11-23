<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fiscal_year_id' => ['sometimes', 'required', 'integer', Rule::exists('fiscal_years', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'period_number' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', Rule::in([AccountingPeriodStatus::Open->value])],
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
