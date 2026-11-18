<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrintBarcodeLabelsRequest extends FormRequest
{
    public const MAX_ITEMS = 200;

    public const MAX_COPIES = 500;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'items.*.copies' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COPIES],
        ];
    }

    public function messages(): array
    {
        $l = __('products.barcode_print');

        return [
            'items.required' => $l['items_required'],
            'items.min' => $l['items_required'],
            'items.max' => $l['items_max'],
            'items.*.product_id.exists' => $l['product_missing'],
            'items.*.copies.min' => $l['copies_range'],
            'items.*.copies.max' => $l['copies_range'],
            'items.*.copies.integer' => $l['copies_range'],
        ];
    }
}
