<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\ContactAddress;
use App\Services\Accounting\ContactAccountService;
use Illuminate\Support\Facades\DB;

class ContactService
{
    public function __construct(
        protected ContactAccountService $contactAccountService,
    ) {}

    /**
     * Create a new contact along with role-appropriate receivable/payable accounts
     * within a single database transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createContact(array $data): Contact
    {
        $data = $this->normalizeIndividualContactData($data);

        return DB::transaction(function () use ($data): Contact {
            $contact = new Contact;
            $contact->fill($data);
            $contact->save();

            $this->contactAccountService->syncAccountsForContact($contact);

            // If an initial address is provided with contact creation, save it.
            if (! empty($data['address']) && is_array($data['address'])) {
                $addressData = $data['address'];
                $addressData['is_default'] = true; // First address defaults to true
                $address = $contact->addresses()->create($addressData);
                $contact->setRelation('addresses', $contact->newCollection([$address]));
            } else {
                $contact->setRelation('addresses', $contact->newCollection());
            }

            return $contact;
        });
    }

    /**
     * Update an existing contact and provision any newly required role accounts
     * while preserving historical accounts if a role is removed.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateContact(Contact $contact, array $data): Contact
    {
        $data = $this->normalizeIndividualContactData($data, $contact);

        return DB::transaction(function () use ($contact, $data) {
            $contact->update($data);
            $this->contactAccountService->syncAccountsForContact($contact);

            return $contact->fresh([
                'addresses',
                'receivableAccount:id,code,name',
                'payableAccount:id,code,name',
            ]);
        });
    }

    /**
     * Clear company-only and tax/register fields whenever a contact is an individual (customer or supplier).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeIndividualContactData(array $data, ?Contact $existing = null): array
    {
        $rawType = $data['type'] ?? $existing?->type ?? ContactType::INDIVIDUAL->value;
        $type = $rawType instanceof ContactType ? $rawType->value : (string) $rawType;

        if ($type === ContactType::INDIVIDUAL->value) {
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
     * @param  array<string, mixed>  $data
     */
    public function addAddress(Contact $contact, array $data): ContactAddress
    {
        return DB::transaction(function () use ($contact, $data) {
            $hasExistingAddresses = $contact->addresses()->exists();

            if (! $hasExistingAddresses) {
                $data['is_default'] = true;
            } elseif (! empty($data['is_default'])) {
                $contact->addresses()->update(['is_default' => false]);
            }

            return $contact->addresses()->create($data);
        });
    }

    /**
     * Update a contact's address.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAddress(ContactAddress $address, array $data): ContactAddress
    {
        return DB::transaction(function () use ($address, $data) {
            if (! empty($data['is_default'])) {
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
