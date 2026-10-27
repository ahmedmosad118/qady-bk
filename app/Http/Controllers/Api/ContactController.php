<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\StoreContactRequest;
use App\Http\Requests\Contact\UpdateContactRequest;
use App\Models\Contact;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(
        protected ContactService $contactService
    ) {}

    /**
     * Display a paginated listing of contacts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query()->with('defaultAddress');

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->boolean('is_customer')) {
            $query->customers();
        }

        if ($request->boolean('is_supplier')) {
            $query->suppliers();
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sortBy = in_array($request->input('sort_by'), ['name', 'type', 'created_at'], true)
            ? $request->input('sort_by')
            : 'id';
        $sortOrder = strtolower((string) $request->input('sort_order')) === 'asc' ? 'asc' : 'desc';

        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $paginated = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Store a newly created contact.
     */
    public function store(StoreContactRequest $request): JsonResponse
    {
        $contact = $this->contactService->createContact($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('contacts.saved_successfully'),
            'data' => $contact,
        ], 201);
    }

    /**
     * Display the specified contact with addresses.
     */
    public function show(Contact $contact): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $contact->load('addresses'),
        ]);
    }

    /**
     * Update the specified contact.
     */
    public function update(UpdateContactRequest $request, Contact $contact): JsonResponse
    {
        $updated = $this->contactService->updateContact($contact, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('contacts.updated_successfully'),
            'data' => $updated,
        ]);
    }

    /**
     * Soft-delete the specified contact.
     */
    public function destroy(Contact $contact): JsonResponse
    {
        $this->contactService->deleteContact($contact);

        return response()->json([
            'success' => true,
            'message' => __('contacts.deleted_successfully'),
        ]);
    }
}
