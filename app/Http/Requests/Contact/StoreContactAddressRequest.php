<?php

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared rules for address store and update.
     *
     * @return array<string, mixed>
     */
    public function addressRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'governorate' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function rules(): array
    {
        return $this->addressRules();
    }

    public function messages(): array
    {
        $v = __('contacts.validation');

        return [
            'label.max' => $v['label_max'] ?? 'Label cannot exceed 100 characters.',
            'address.max' => $v['address_max'],
            'city.max' => $v['city_max'],
            'governorate.max' => $v['governorate_max'],
            'postal_code.max' => $v['postal_code_max'],
            'country.max' => $v['country_max'],
        ];
    }
}
