<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\Accounting\JournalEntryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('reference') && is_string($this->input('reference'))) {
            $merged['reference'] = trim($this->input('reference'));
        }

        if ($this->has('description') && is_string($this->input('description'))) {
            $merged['description'] = trim($this->input('description'));
        }

        if ($this->has('lines') && is_array($this->input('lines'))) {
            $merged['lines'] = array_map(static function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                if (isset($line['label']) && is_string($line['label'])) {
                    $line['label'] = trim($line['label']);
                }

                return $line;
            }, $this->input('lines'));
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
        return [
            'journal_id' => ['required', 'integer', 'exists:journals,id'],
            'fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id'],
            'accounting_period_id' => ['required', 'integer', 'exists:accounting_periods,id'],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->whereNull('deleted_at')],
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in([JournalEntryStatus::Draft->value])],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.line_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'lines.*.label' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => (string) __('accounting.errors.journal_entry_status_must_be_draft'),
            'lines.min' => (string) __('accounting.errors.journal_entry_min_lines'),
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
