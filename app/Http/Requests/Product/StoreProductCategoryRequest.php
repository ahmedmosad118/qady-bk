<?php

namespace App\Http\Requests\Product;

use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules shared by store and update.
     *
     * The taxonomy is limited to two levels, so a valid parent must itself be
     * a top-level category (parent_id IS NULL). This single rule also blocks
     * circular links: a category that already has a parent can never be
     * chosen as someone else's parent.
     */
    public function categoryRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists(ProductCategory::class, 'id')->where('parent_id', null),
            ],
        ];
    }

    public function rules(): array
    {
        return $this->categoryRules();
    }

    public function messages(): array
    {
        return [
            'parent_id.exists' => __('products.parent_must_be_top_level'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $parentId = $this->input('parent_id');

            // A category can never be its own parent.
            if ($parentId !== null && $parentId !== '' && (int) $parentId === (int) $this->subjectId()) {
                $v->errors()->add('parent_id', __('products.parent_invalid'));
            }
        });
    }

    /**
     * The id of the category being edited when applicable (null on store).
     */
    protected function subjectId(): ?int
    {
        return null;
    }
}
