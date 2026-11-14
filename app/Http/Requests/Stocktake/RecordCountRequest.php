<?php

declare(strict_types=1);

namespace App\Http\Requests\Stocktake;

use Illuminate\Foundation\Http\FormRequest;

class RecordCountRequest extends FormRequest
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
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
