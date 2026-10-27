<?php

namespace App\Http\Requests\Product;

use App\Models\ProductCategory;
use Illuminate\Validation\Rule;

class UpdateProductCategoryRequest extends StoreProductCategoryRequest
{
    protected function categoryModel(): ?ProductCategory
    {
        $category = $this->route('category');

        if ($category instanceof ProductCategory) {
            return $category;
        }

        return is_numeric($category) ? ProductCategory::find((int) $category) : null;
    }

    public function rules(): array
    {
        $rules = $this->categoryRules();

        // A subcategory cannot be re-attached under a different top-level
        // parent — it would implicitly move a whole subtree (and the table
        // only supports one subcategory level).
        $current = $this->categoryModel();

        if ($current && $current->parent_id !== null) {
            $rules['parent_id'] = ['nullable', 'integer', 'prohibited'];
        }

        return $rules;
    }

    protected function subjectId(): ?int
    {
        return $this->categoryModel()?->id;
    }
}
