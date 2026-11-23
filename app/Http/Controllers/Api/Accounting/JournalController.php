<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreJournalRequest;
use App\Http\Requests\Accounting\UpdateJournalRequest;
use App\Models\Journal;
use App\Services\Accounting\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(
        protected JournalService $journalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'type' => $request->input('type'),
            'is_active' => $request->input('is_active'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $paginated = $this->journalService->paginate($filters, $request->integer('per_page', 15));

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

    public function store(StoreJournalRequest $request): JsonResponse
    {
        $journal = $this->journalService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.journal_created'),
            'data' => $journal,
        ], 201);
    }

    public function show(Journal $journal): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $journal,
        ]);
    }

    public function update(UpdateJournalRequest $request, Journal $journal): JsonResponse
    {
        $updated = $this->journalService->update($journal, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.journal_updated'),
            'data' => $updated,
        ]);
    }

    public function destroy(Journal $journal): JsonResponse
    {
        $this->journalService->delete($journal);

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.journal_deleted'),
        ]);
    }
}
