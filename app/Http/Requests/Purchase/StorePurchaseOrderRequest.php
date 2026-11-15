<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseOrderRequest extends FormRequest
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
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')->where(fn ($q) => $q->where('is_supplier', true)->whereNull('deleted_at')),
            ],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount_type' => ['nullable', 'string', Rule::in(DiscountType::values())],
            'discount_value' => ['nullable', 'numeric', 'min:0'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'string', Rule::in(DiscountType::values())],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Check invoice discount
            $discType = $this->input('discount_type');
            $discVal = $this->input('discount_value');
            if ($discType === DiscountType::PERCENTAGE->value && $discVal !== null && (float) $discVal > 100) {
                $v->errors()->add('discount_value', 'نسبة الخصم الإجمالي لا يمكن أن تتجاوز 100%.');
            }

            // Check item discounts
            $items = $this->input('items', []);
            if (is_array($items)) {
                foreach ($items as $idx => $item) {
                    $itemType = $item['discount_type'] ?? null;
                    $itemVal = $item['discount_value'] ?? null;
                    if ($itemType === DiscountType::PERCENTAGE->value && $itemVal !== null && (float) $itemVal > 100) {
                        $v->errors()->add("items.{$idx}.discount_value", 'نسبة خصم الصنف لا يمكن أن تتجاوز 100%.');
                    }
                }
            }
        });
    }

    /**
     * Custom Arabic attribute names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'المورد',
            'order_date' => 'تاريخ الأمر',
            'expected_delivery_date' => 'تاريخ التسليم المتوقع',
            'notes' => 'الملاحظات',
            'discount_type' => 'نوع الخصم',
            'discount_value' => 'قيمة الخصم',
            'items' => 'الأصناف',
            'items.*.product_id' => 'الصنف',
            'items.*.quantity' => 'الكمية',
            'items.*.unit_price' => 'سعر الوحدة',
            'items.*.discount_type' => 'نوع خصم الصنف',
            'items.*.discount_value' => 'قيمة خصم الصنف',
        ];
    }
}
