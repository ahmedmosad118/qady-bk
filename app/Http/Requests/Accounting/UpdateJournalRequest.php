<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\Accounting\JournalType;
use App\Models\Journal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJournalRequest extends FormRequest
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
        $journal = $this->route('journal');
        $journalId = $journal instanceof Journal ? $journal->id : (int) $journal;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('journals', 'code')->ignore($journalId)],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => ['sometimes', 'required', Rule::enum(JournalType::class)],
            'is_active' => ['sometimes', 'boolean'],
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
