<?php

declare(strict_types=1);

namespace App\Http\Requests\Expense;

use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreExpenseItemAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('expenses.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attachments' => ['required', 'array', 'min:1', 'max:20'],
            'attachments.*' => [
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
            /** @var ExpenseItem|null $item */
            $item = $this->route('item');

            if ($expense && ! $expense->isDraft()) {
                $v->errors()->add('status', __('expenses.errors.cannot_modify_posted_expense'));

                return;
            }

            if ($expense && $item && (int) $item->expense_id !== (int) $expense->id) {
                $v->errors()->add('item', __('expenses.errors.invalid_item_id'));
            }
        });
    }
}
