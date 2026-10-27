<?php

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
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
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
