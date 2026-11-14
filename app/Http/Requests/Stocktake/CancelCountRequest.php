<?php

declare(strict_types=1);

namespace App\Http\Requests\Stocktake;

use Illuminate\Foundation\Http\FormRequest;

class CancelCountRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }
}
