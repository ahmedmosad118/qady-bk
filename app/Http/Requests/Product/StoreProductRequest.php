<?php

namespace App\Http\Requests\Product;

use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared product rules for store/update.
     */
    public function productRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')],
            'barcode' => [
                'nullable', 'string', 'max:100',
                Rule::unique('products', 'barcode')->ignore(null),
            ],
            'author' => ['nullable', 'string', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'edition' => ['nullable', 'string', 'max:100'],
            'publication_year' => ['nullable', 'integer', 'min:1000', 'max:2100'],
            'cover_image' => ['nullable', 'string', 'max:255'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'is_active' => ['required', 'boolean'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists(ProductCategory::class, 'id')->where('is_active', true),
            ],
        ];
    }

    public function rules(): array
    {
        return $this->productRules();
    }

    public function messages(): array
    {
        $l = __('products.validation');

        return [
            'name.required' => $l['name_required'],
            'name.max' => $l['name_max'],
            'sku.required' => $l['sku_required'],
            'sku.unique' => $l['sku_unique'],
            'barcode.unique' => $l['barcode_unique'],
            'purchase_price.required' => $l['purchase_price_required'],
            'purchase_price.numeric' => $l['purchase_price_required'],
            'purchase_price.min' => $l['purchase_price_min'],
            'selling_price.required' => $l['selling_price_required'],
            'selling_price.numeric' => $l['selling_price_required'],
            'selling_price.min' => $l['selling_price_min'],
            'category_id.exists' => $l['category_id_exists'],
            'publication_year.integer' => $l['publication_year_range'],
            'publication_year.min' => $l['publication_year_range'],
            'publication_year.max' => $l['publication_year_range'],
        ];
    }

    /**
     * Empty text values for nullable string fields are normalized to null so a
     * blank form input never stores "" (e.g. barcode must stay nullable).
     */
    protected function prepareForValidation(): void
    {
        foreach (['sku', 'barcode', 'author', 'publisher', 'edition', 'cover_image'] as $field) {
            if (is_string($this->input($field)) && trim($this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }
}
