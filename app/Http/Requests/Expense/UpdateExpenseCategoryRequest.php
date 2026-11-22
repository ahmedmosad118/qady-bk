<?php

declare(strict_types=1);

namespace App\Http\Requests\Expense;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ExpenseCategory|null $category */
        $category = $this->route('expenseCategory') ?? $this->route('expense_category');

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('expense_categories', 'name')->ignore($category?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
