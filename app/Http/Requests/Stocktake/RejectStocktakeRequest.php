<?php

declare(strict_types=1);

namespace App\Http\Requests\Stocktake;

use Illuminate\Foundation\Http\FormRequest;

class RejectStocktakeRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }
}
