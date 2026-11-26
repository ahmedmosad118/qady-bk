<?php

declare(strict_types=1);

namespace App\Http\Requests\Expense;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreExpenseRequest extends FormRequest
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
            'contact_id' => [
                'nullable',
                'integer',
                Rule::exists('contacts', 'id')
                    ->where('is_active', true)
                    ->where('is_supplier', true)
                    ->whereNull('deleted_at'),
            ],
            'journal_id' => [
                'required',
                'integer',
                Rule::exists('journals', 'id')
                    ->where('is_active', true)
                    ->where('type', JournalType::Expense->value),
            ],
            'expense_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('is_active', true),
            ],
            'items.*.expense_category_id' => [
                'required',
                'integer',
                Rule::exists('expense_categories', 'id'),
            ],
            'items.*.reference_number' => ['nullable', 'string', 'max:100'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
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
            $items = $this->input('items');
            if (! is_array($items)) {
                return;
            }

            $accountIds = collect($items)
                ->pluck('account_id')
                ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($accountIds === []) {
                return;
            }

            $accounts = Account::query()
                ->with('accountType:id,code')
                ->whereIn('id', $accountIds)
                ->get(['id', 'account_type_id', 'is_active'])
                ->keyBy('id');

            foreach ($items as $idx => $item) {
                $accountId = isset($item['account_id']) && is_numeric($item['account_id'])
                    ? (int) $item['account_id']
                    : 0;

                if ($accountId <= 0) {
                    continue;
                }

                $account = $accounts->get($accountId);
                if ($account && $account->is_active && $account->accountType?->code !== AccountTypeCode::Expenses->value) {
                    $v->errors()->add(
                        "items.{$idx}.account_id",
                        (string) __('validation.in', ['attribute' => "items.{$idx}.account_id"])
                    );
                }
            }
        });
    }
}
