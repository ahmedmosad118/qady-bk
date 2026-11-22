<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Expense;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\StoreExpenseItemAttachmentRequest;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\ExpenseItemAttachment;
use App\Services\Expense\ExpenseAttachmentService;
use App\Services\Expense\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseAttachmentController extends Controller
{
    public function __construct(
        protected ExpenseService $expenseService,
        protected ExpenseAttachmentService $attachmentService,
    ) {}

    /**
     * Upload additional attachments to a specific Draft expense item.
     */
    public function upload(
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

        return $this->successResponse(
            $created,
            (string) __('expenses.attachment_uploaded_successfully'),
            201
        );
    }

    /**
     * Stream an attachment inline for authorized viewing.
     */
    public function view(
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
    public function download(
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
    public function destroy(
        Expense $expense,
        ExpenseItem $item,
        ExpenseItemAttachment $attachment
    ): JsonResponse {
        Gate::authorize('expenses.update');
        $this->ensureAttachmentHierarchy($expense, $item, $attachment);

        $this->expenseService->deleteItemAttachment($expense, $item, $attachment);

        return $this->successResponse(
            null,
            (string) __('expenses.attachment_deleted_successfully')
        );
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
