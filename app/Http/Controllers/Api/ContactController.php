<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\StoreContactRequest;
use App\Http\Requests\Contact\UpdateContactRequest;
use App\Models\Contact;
use App\Repositories\ContactRepository;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(
        protected ContactService $contactService,
        protected ContactRepository $contactRepository,
    ) {}

    /**
     * Display a paginated listing of contacts.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'type' => $request->input('type'),
            'is_customer' => $request->boolean('is_customer'),
            'is_supplier' => $request->boolean('is_supplier'),
            'is_active' => $request->input('is_active'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->contactRepository->paginate($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'stats' => $this->contactRepository->stats(),
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
