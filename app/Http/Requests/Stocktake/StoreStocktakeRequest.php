<?php

declare(strict_types=1);

namespace App\Http\Requests\Stocktake;

use App\Enums\StocktakeScope;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStocktakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation: resolve the single default warehouse if not specified.
     */
    protected function prepareForValidation(): void
    {
        if (empty($this->warehouse_id)) {
            $default = Warehouse::query()->default()->first();

            if ($default) {
                $this->merge(['warehouse_id' => $default->id]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'scope' => ['required', Rule::enum(StocktakeScope::class)],
            'stocktake_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'product_ids' => [
                'required_if:scope,' . StocktakeScope::SELECTED->value,
                'array',
                'min:1',
            ],
            'product_ids.*' => ['integer', 'exists:products,id', 'distinct'],
        ];
    }
}
