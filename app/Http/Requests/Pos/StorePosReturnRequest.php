<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.return', $this->user()) && Gate::allows('pos.process_returns', $this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'exists:pos_shifts,id'],
            'pos_sale_id' => ['required', 'integer', 'exists:pos_sales,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_uuid' => ['nullable', 'string', 'max:36'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.pos_sale_item_id' => ['required', 'integer', 'exists:pos_sale_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.is_defective' => ['nullable', 'boolean'],
            'items.*.is_damaged' => ['nullable', 'boolean'],
            'items.*.condition' => ['nullable', 'string', 'max:50'],
        ];
    }
}
