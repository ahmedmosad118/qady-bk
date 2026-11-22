<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\StoreContactAddressRequest;
use App\Http\Requests\Contact\UpdateContactAddressRequest;
use App\Models\Contact;
use App\Models\ContactAddress;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ContactAddressController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ContactService $contactService
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:update,contact', only: ['store', 'update']),
        ];
    }

    /**
     * List all addresses for a given contact.
     */
    public function index(Contact $contact): JsonResponse
    {
        $this->authorize('view', $contact);

        return response()->json([
            'success' => true,
            'data' => $contact->addresses()->orderByDesc('is_default')->get(),
        ]);
    }

    /**
     * Store a new address for the contact.
     */
    public function store(StoreContactAddressRequest $request, Contact $contact): JsonResponse
    {
        $address = $this->contactService->addAddress($contact, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('contacts.address_saved_successfully'),
            'data' => $address,
        ], 201);
    }

    /**
     * Update an address for the contact.
     */
    public function update(
        UpdateContactAddressRequest $request,
        Contact $contact,
        ContactAddress $address
    ): JsonResponse {
        if ($address->contact_id !== $contact->id) {
            abort(404);
        }

        $updated = $this->contactService->updateAddress($address, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('contacts.address_updated_successfully'),
            'data' => $updated,
        ]);
    }

    /**
     * Set the address as the default address for the contact.
     */
    public function setDefault(Contact $contact, ContactAddress $address): JsonResponse
    {
        $this->authorize('update', $contact);

        if ($address->contact_id !== $contact->id) {
            abort(404);
        }

        $updated = $this->contactService->setDefaultAddress($contact, $address);

        return response()->json([
            'success' => true,
            'message' => __('contacts.default_address_set'),
            'data' => $updated,
        ]);
    }

    /**
     * Soft-delete an address.
     */
    public function destroy(Contact $contact, ContactAddress $address): JsonResponse
    {
        $this->authorize('update', $contact);

        if ($address->contact_id !== $contact->id) {
            abort(404);
        }

        $this->contactService->deleteAddress($address);

        return response()->json([
            'success' => true,
            'message' => __('contacts.address_deleted_successfully'),
        ]);
    }
}
