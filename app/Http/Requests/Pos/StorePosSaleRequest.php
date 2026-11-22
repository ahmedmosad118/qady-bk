<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class StorePosSaleRequest extends FormRequest
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
        return [
            'shift_id' => ['required', 'integer', 'exists:pos_shifts,id'],
            'customer_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'invoice_discount_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'invoice_discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_uuid' => ['nullable', 'string', 'max:36'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
