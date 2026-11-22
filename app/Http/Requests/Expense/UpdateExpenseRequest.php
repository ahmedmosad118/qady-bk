<?php

declare(strict_types=1);

namespace App\Http\Requests\Expense;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('contact_id') && $this->input('contact_id') === '') {
            $this->merge(['contact_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expense_category_id' => [
                'required',
                'integer',
                Rule::exists('expense_categories', 'id'),
            ],
            'contact_id' => [
                'nullable',
                'integer',
                Rule::exists('contacts', 'id')->whereNull('deleted_at'),
            ],
            'expense_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'removed_attachment_ids' => ['nullable', 'array'],
            'removed_attachment_ids.*' => ['required', 'integer'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.amount' => ['required', 'numeric', 'gt:0'],
            'items.*.remove_attachment_ids' => ['nullable', 'array'],
            'items.*.remove_attachment_ids.*' => ['required', 'integer'],
            'items.*.attachments' => ['nullable', 'array', 'max:20'],
            'items.*.attachments.*' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,gif,webp,pdf',
                'max:4096',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var Expense|null $expense */
            $expense = $this->route('expense');

            if ($expense && ! $expense->isDraft()) {
                $v->errors()->add('status', __('expenses.errors.cannot_modify_posted_expense'));
            }
        });
    }
}
