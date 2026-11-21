<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use App\Enums\ContactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['phone', 'mobile'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->normalizePhoneValue($this->input($field));
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    protected function normalizePhoneValue(mixed $value): mixed
    {
        if ($value === null || (!is_string($value) && !is_int($value))) {
            return $value;
        }

        $trimmed = trim(strtr((string) $value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Shared contact rules for store and update.
     *
     * @return array<string, mixed>
     */
    public function contactRules(): array
    {
        return [
            'type' => ['required', Rule::enum(ContactType::class)],
            'name' => ['required', 'string', 'max:255'],
            'company_contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => [
                'nullable', 'string', 'max:30',
                Rule::unique('contacts', 'phone')->ignore($this->route('contact'))->whereNull('deleted_at'),
            ],
            'mobile' => [
                'required', 'string', 'max:30',
                Rule::unique('contacts', 'mobile')->ignore($this->route('contact'))->whereNull('deleted_at'),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'tax_registration_type' => ['nullable', 'string', 'max:50'],
            'commercial_register' => ['nullable', 'string', 'max:100'],
            'is_customer' => ['nullable', 'boolean'],
            'is_supplier' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function rules(): array
    {
        return $this->contactRules();
    }

    public function messages(): array
    {
        $v = __('contacts.validation');

        return [
            'type.required' => $v['type_required'],
            'name.required' => $v['name_required'],
            'name.max' => $v['name_max'],
            'email.email' => $v['email_invalid'],
            'phone.max' => $v['phone_max'],
            'mobile.required' => $v['mobile_required'],
            'mobile.max' => $v['mobile_max'],
            'tax_number.max' => $v['tax_number_max'],
            'tax_registration_type.max' => $v['tax_registration_type_max'],
            'commercial_register.max' => $v['commercial_register_max'],
            'company_contact_person.max' => $v['company_contact_person_max'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Require at least one role: is_customer or is_supplier must be true.
            $isCustomer = filter_var($this->input('is_customer', false), FILTER_VALIDATE_BOOLEAN);
            $isSupplier = filter_var($this->input('is_supplier', false), FILTER_VALIDATE_BOOLEAN);

            if (!$isCustomer && !$isSupplier) {
                $v->errors()->add('is_customer', __('contacts.validation.at_least_one_role'));
                $v->errors()->add('is_supplier', __('contacts.validation.at_least_one_role'));
            }
        });
    }
}
