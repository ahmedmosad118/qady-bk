<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Expense;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\StoreExpenseItemAttachmentRequest;
use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\ExpenseItemAttachment;
use App\Repositories\ExpenseRepository;
use App\Services\Expense\ExpenseAttachmentService;
use App\Services\Expense\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function __construct(
        protected ExpenseRepository $repository,
        protected ExpenseService $expenseService,
        protected ExpenseAttachmentService $attachmentService,
    ) {}

    /**
     * Display a paginated listing of expenses with filters and sorting.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('expenses.view');

        $filters = [
            'search' => $request->input('search'),
            'expense_category_id' => $request->input('expense_category_id'),
            'contact_id' => $request->input('contact_id'),
            'status' => $request->input('status'),
            'expense_date_from' => $request->input('expense_date_from'),
            'expense_date_to' => $request->input('expense_date_to'),
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
     * Store a newly created Draft expense with items and optional attachments.
     */
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenseService->createExpense(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('expenses.created_successfully'),
            'data' => $expense,
        ], 201);
    }

    /**
     * Display a single expense with category, contact, items, and attachments.
     */
    public function show(Expense $expense): JsonResponse
    {
        Gate::authorize('expenses.view');

        return response()->json([
            'success' => true,
            'data' => $this->repository->findWithRelations($expense->id),
        ]);
    }

    /**
     * Update an existing Draft expense, its items, and its attachments.
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $updated = $this->expenseService->updateExpense(
            $expense,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('expenses.updated_successfully'),
            'data' => $updated,
        ]);
    }

    /**
     * Delete a Draft expense along with its items and attachment files.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        Gate::authorize('expenses.delete');

        $this->expenseService->deleteExpense($expense);

        return response()->json([
            'success' => true,
            'message' => __('expenses.deleted_successfully'),
        ]);
    }

    /**
     * Upload additional attachments to a specific Draft expense item.
     */
    public function uploadAttachments(
        StoreExpenseItemAttachmentRequest $request,
        Expense $expense,
        ExpenseItem $item
    ): JsonResponse {
        $this->ensureItemBelongsToExpense($expense, $item);

        $validated = $request->validated();
        $created = $this->expenseService->uploadItemAttachments(
            $expense,
            $item,
            $validated['attachments'] ?? [],
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('expenses.attachment_uploaded_successfully'),
            'data' => $created,
        ], 201);
    }

    /**
     * Stream an attachment inline for authorized viewing.
     */
    public function viewAttachment(
        Expense $expense,
        ExpenseItem $item,
        ExpenseItemAttachment $attachment
    ): StreamedResponse {
        Gate::authorize('expenses.view');
        $this->ensureAttachmentHierarchy($expense, $item, $attachment);

        return $this->attachmentService->streamResponse($attachment, false);
    }

    /**
     * Download an attachment file for authorized users.
     */
    public function downloadAttachment(
        Expense $expense,
        ExpenseItem $item,
        ExpenseItemAttachment $attachment
    ): StreamedResponse {
        Gate::authorize('expenses.view');
        $this->ensureAttachmentHierarchy($expense, $item, $attachment);

        return $this->attachmentService->streamResponse($attachment, true);
    }

    /**
     * Delete an individual attachment from a Draft expense item.
     */
    public function destroyAttachment(
        Expense $expense,
        ExpenseItem $item,
        ExpenseItemAttachment $attachment
    ): JsonResponse {
        Gate::authorize('expenses.update');
        $this->ensureAttachmentHierarchy($expense, $item, $attachment);

        $this->expenseService->deleteItemAttachment($expense, $item, $attachment);

        return response()->json([
            'success' => true,
            'message' => __('expenses.attachment_deleted_successfully'),
        ]);
    }

    /**
     * Enforce that the expense item belongs to the given expense (IDOR guard).
     */
    protected function ensureItemBelongsToExpense(Expense $expense, ExpenseItem $item): void
    {
        if ((int) $item->expense_id !== (int) $expense->id) {
            abort(404);
        }
    }

    /**
     * Enforce full expense -> item -> attachment ownership hierarchy (IDOR guard).
     */
    protected function ensureAttachmentHierarchy(
        Expense $expense,
        ExpenseItem $item,
        ExpenseItemAttachment $attachment
    ): void {
        $this->ensureItemBelongsToExpense($expense, $item);

        if ((int) $attachment->expense_item_id !== (int) $item->id) {
            abort(404);
        }
    }
}
