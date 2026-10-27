<?php

namespace App\Http\Requests\Contact;

use App\Models\Contact;
use Illuminate\Validation\Validator;

class UpdateContactRequest extends StoreContactRequest
{
    public function contactModel(): Contact
    {
        $contact = $this->route('contact');

        return $contact instanceof Contact ? $contact : Contact::findOrFail((int) $contact);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $current = $this->contactModel();

            $isCustomer = $this->has('is_customer')
                ? filter_var($this->input('is_customer'), FILTER_VALIDATE_BOOLEAN)
                : $current->is_customer;

            $isSupplier = $this->has('is_supplier')
                ? filter_var($this->input('is_supplier'), FILTER_VALIDATE_BOOLEAN)
                : $current->is_supplier;

            if (!$isCustomer && !$isSupplier) {
                $v->errors()->add('is_customer', __('contacts.validation.at_least_one_role'));
                $v->errors()->add('is_supplier', __('contacts.validation.at_least_one_role'));
            }
        });
    }
}
