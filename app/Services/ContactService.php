<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\ContactAddress;
use Illuminate\Support\Facades\DB;

class ContactService
{
    /**
     * Create a new contact within a database transaction.
     *
     * @param array<string, mixed> $data
     */
    public function createContact(array $data): Contact
    {
        $data = $this->normalizeIndividualCustomerData($data);

        return DB::transaction(function () use ($data) {
            $contact = new Contact();
            $contact->fill($data);
            $contact->save();

            // If an initial address is provided with contact creation, save it.
            if (!empty($data['address']) && is_array($data['address'])) {
                $addressData = $data['address'];
                $addressData['is_default'] = true; // First address defaults to true
                $contact->addresses()->create($addressData);
            }

            return $contact->load('addresses');
        });
    }

    /**
     * Update an existing contact.
     *
     * @param array<string, mixed> $data
     */
    public function updateContact(Contact $contact, array $data): Contact
    {
        $data = $this->normalizeIndividualCustomerData($data, $contact);

        return DB::transaction(function () use ($contact, $data) {
            $contact->update($data);

            return $contact->fresh(['addresses']);
        });
    }

    /**
     * Clear company-only and tax/register fields when a contact is an individual customer only.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeIndividualCustomerData(array $data, ?Contact $existing = null): array
    {
        $rawType = $data['type'] ?? $existing?->type ?? ContactType::INDIVIDUAL->value;
        $type = $rawType instanceof ContactType ? $rawType->value : (string) $rawType;

        $isCustomer = array_key_exists('is_customer', $data)
            ? (bool) $data['is_customer']
            : (bool) ($existing?->is_customer ?? false);

        $isSupplier = array_key_exists('is_supplier', $data)
            ? (bool) $data['is_supplier']
            : (bool) ($existing?->is_supplier ?? false);

        if ($type === ContactType::INDIVIDUAL->value && $isCustomer && !$isSupplier) {
            $data['company_contact_person'] = null;
            $data['tax_number'] = null;
            $data['tax_registration_type'] = null;
            $data['commercial_register'] = null;
        }

        return $data;
    }

    /**
     * Soft delete a contact.
     */
    public function deleteContact(Contact $contact): bool
    {
        return DB::transaction(function () use ($contact) {
            return (bool) $contact->delete();
        });
    }

    /**
     * Add an address to a contact. If it is the contact's first address,
     * it is automatically designated as the default address.
     *
     * @param array<string, mixed> $data
     */
    public function addAddress(Contact $contact, array $data): ContactAddress
    {
        return DB::transaction(function () use ($contact, $data) {
            $hasExistingAddresses = $contact->addresses()->exists();

            if (!$hasExistingAddresses) {
                $data['is_default'] = true;
            } elseif (!empty($data['is_default'])) {
                $contact->addresses()->update(['is_default' => false]);
            }

            return $contact->addresses()->create($data);
        });
    }

    /**
     * Update a contact's address.
     *
     * @param array<string, mixed> $data
     */
    public function updateAddress(ContactAddress $address, array $data): ContactAddress
    {
        return DB::transaction(function () use ($address, $data) {
            if (!empty($data['is_default'])) {
                ContactAddress::where('contact_id', $address->contact_id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            return $address->fresh();
        });
    }

    /**
     * Designate a specific address as the default address for the contact.
     */
    public function setDefaultAddress(Contact $contact, ContactAddress $address): ContactAddress
    {
        return DB::transaction(function () use ($contact, $address) {
            $contact->addresses()
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);

            $address->update(['is_default' => true]);

            return $address->fresh();
        });
    }

    /**
     * Soft delete a contact address.
     */
    public function deleteAddress(ContactAddress $address): bool
    {
        return DB::transaction(function () use ($address) {
            return (bool) $address->delete();
        });
    }
}
