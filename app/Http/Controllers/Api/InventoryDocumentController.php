<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryDocumentRequest;
use App\Http\Requests\Inventory\UpdateInventoryDocumentRequest;
use App\Models\InventoryDocument;
use App\Repositories\InventoryDocumentRepository;
use App\Services\InventoryDocumentService;
use App\Services\InventoryDocumentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryDocumentController extends Controller
{
    public function __construct(
        protected InventoryDocumentRepository $repository,
        protected InventoryDocumentService $documentService,
        protected InventoryDocumentWorkflowService $workflowService,
    ) {}

    /**
     * Display a listing of inventory documents.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'document_type' => $request->input('document_type'),
            'status' => $request->input('status'),
            'source_warehouse_id' => $request->input('source_warehouse_id'),
            'destination_warehouse_id' => $request->input('destination_warehouse_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'created_by' => $request->input('created_by'),
            'product_id' => $request->input('product_id'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->repository->paginate($filters, $perPage);

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
     * Display the specified inventory document.
     */
    public function show(InventoryDocument $inventoryDocument): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->repository->findWithRelations($inventoryDocument),
        ]);
    }

    /**
     * Store a newly created inventory document.
     */
    public function store(StoreInventoryDocumentRequest $request): JsonResponse
    {
        $document = $this->documentService->create(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('inventory.document_created_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ], 201);
    }

    /**
     * Update the specified inventory document.
     */
    public function update(UpdateInventoryDocumentRequest $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $document = $this->documentService->update(
            $inventoryDocument,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('inventory.document_updated_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ]);
    }

    /**
     * Remove the specified inventory document.
     */
    public function destroy(InventoryDocument $inventoryDocument): JsonResponse
    {
        $this->documentService->delete($inventoryDocument);

        return response()->json([
            'success' => true,
            'message' => __('inventory.document_deleted_successfully'),
        ]);
    }

    /**
     * Submit document for approval.
     */
    public function submit(Request $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $document = $this->workflowService->submit($inventoryDocument, $request->user());

        return response()->json([
            'success' => true,
            'message' => __('inventory.workflow.submitted_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ]);
    }

    /**
     * Approve document.
     */
    public function approve(Request $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $document = $this->workflowService->approve($inventoryDocument, $request->user());

        return response()->json([
            'success' => true,
            'message' => __('inventory.workflow.approved_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ]);
    }

    /**
     * Reject document.
     */
    public function reject(Request $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $document = $this->workflowService->reject(
            $inventoryDocument,
            $request->user(),
            (string) $request->input('rejection_reason')
        );

        return response()->json([
            'success' => true,
            'message' => __('inventory.workflow.rejected_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ]);
    }

    /**
     * Cancel document.
     */
    public function cancel(Request $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $document = $this->workflowService->cancel($inventoryDocument, $request->user());

        return response()->json([
            'success' => true,
            'message' => __('inventory.workflow.cancelled_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ]);
    }

    /**
     * Post document.
     */
    public function post(Request $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $document = $this->workflowService->post($inventoryDocument, $request->user());

        return response()->json([
            'success' => true,
            'message' => __('inventory.workflow.posted_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ]);
    }

    /**
     * Reverse document.
     */
    public function reverse(Request $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $request->validate([
            'reversal_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $document = $this->workflowService->reverse(
            $inventoryDocument,
            $request->user(),
            (string) $request->input('reversal_reason')
        );

        return response()->json([
            'success' => true,
            'message' => __('inventory.workflow.reversed_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ]);
    }
}
