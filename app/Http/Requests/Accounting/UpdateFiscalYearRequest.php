<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\Accounting\FiscalYearStatus;
use App\Models\FiscalYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];
        if ($this->has('code') && is_string($this->input('code'))) {
            $merged['code'] = trim($this->input('code'));
        }
        if ($this->has('name') && is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
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
        $fiscalYear = $this->route('fiscalYear');
        $fiscalYearId = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : (int) $fiscalYear;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('fiscal_years', 'code')->ignore($fiscalYearId)],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', Rule::enum(FiscalYearStatus::class)],
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
