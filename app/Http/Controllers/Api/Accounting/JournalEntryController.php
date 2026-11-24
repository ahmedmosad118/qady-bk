<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreJournalEntryRequest;
use App\Http\Requests\Accounting\UpdateJournalEntryRequest;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'entry_number' => $request->input('entry_number'),
            'label' => $request->input('label'),
            'reference' => $request->input('reference'),
            'entry_date' => $request->input('entry_date'),
            'entry_date_from' => $request->input('entry_date_from'),
            'entry_date_to' => $request->input('entry_date_to'),
            'status' => $request->input('status'),
            'journal_id' => $request->input('journal_id'),
            'fiscal_year_id' => $request->input('fiscal_year_id'),
            'accounting_period_id' => $request->input('accounting_period_id'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $paginated = $this->journalEntryService->paginate($filters, $request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'links' => $paginated->linkCollection()->toArray(),
            ],
        ]);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journalEntryService->create($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.journal_entry_created'),
            'data' => $entry,
        ], 201);
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->journalEntryService->getDetails($journalEntry),
        ]);
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $updated = $this->journalEntryService->update($journalEntry, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.journal_entry_updated'),
            'data' => $updated,
        ]);
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        $this->journalEntryService->delete($journalEntry);

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.journal_entry_deleted'),
        ]);
    }
}
