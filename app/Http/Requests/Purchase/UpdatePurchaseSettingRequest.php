<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Enums\PurchaseExpensesTreatment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseSettingRequest extends FormRequest
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
            'expenses_treatment' => [
                'required',
                'string',
                Rule::in(PurchaseExpensesTreatment::values()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'expenses_treatment' => 'purchase expenses treatment',
        ];
    }
}
