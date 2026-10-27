<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    /**
     * The product being edited ($id bound by the route).
     */
    public function product(): \App\Models\Product
    {
        return $this->route('product');
    }

    public function rules(): array
    {
        $rules = $this->productRules();

        $product = $this->product();

        $rules['sku'] = ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)];
        $rules['barcode'] = [
            'nullable', 'string', 'max:100',
            Rule::unique('products', 'barcode')->ignore($product->id),
        ];

        return $rules;
    }
}
