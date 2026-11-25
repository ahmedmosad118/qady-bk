<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OutstandingSupplierInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('supplier_id') && $this->has('contact_id')) {
            $this->merge(['supplier_id' => $this->input('contact_id')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')->where(
                    static fn ($q) => $q->where('is_supplier', true)->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
        ];
    }
}
