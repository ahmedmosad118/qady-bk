<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RejectInventoryDocumentRequest;
use App\Http\Requests\Inventory\ReverseInventoryDocumentRequest;
use App\Http\Requests\Inventory\StoreInventoryDocumentRequest;
use App\Http\Requests\Inventory\UpdateInventoryDocumentRequest;
use App\Models\InventoryDocument;
use App\Repositories\InventoryDocumentRepository;
use App\Services\InventoryDocumentService;
use App\Services\InventoryDocumentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InventoryDocumentController extends Controller implements HasMiddleware
{
    public function __construct(
        protected InventoryDocumentRepository $repository,
        protected InventoryDocumentService $documentService,
        protected InventoryDocumentWorkflowService $workflowService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.InventoryDocument::class, only: ['store']),
            new Middleware('can:update,inventory_document', only: ['update']),
            new Middleware('can:reject,inventory_document', only: ['reject']),
            new Middleware('can:reverse,inventory_document', only: ['reverse']),
        ];
    }

    /**
     * Display a listing of inventory documents.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryDocument::class);

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
        $this->authorize('view', $inventoryDocument);

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
        $this->authorize('delete', $inventoryDocument);

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
        $this->authorize('submit', $inventoryDocument);

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
        $this->authorize('approve', $inventoryDocument);

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
    public function reject(RejectInventoryDocumentRequest $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $document = $this->workflowService->reject(
            $inventoryDocument,
            $request->user(),
            (string) $request->validated('rejection_reason')
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
        $this->authorize('cancel', $inventoryDocument);

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
        $this->authorize('post', $inventoryDocument);

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
    public function reverse(ReverseInventoryDocumentRequest $request, InventoryDocument $inventoryDocument): JsonResponse
    {
        $document = $this->workflowService->reverse(
            $inventoryDocument,
            $request->user(),
            (string) $request->validated('reversal_reason')
        );

        return response()->json([
            'success' => true,
            'message' => __('inventory.workflow.reversed_successfully'),
            'data' => $this->repository->findWithRelations($document),
        ]);
    }
}
