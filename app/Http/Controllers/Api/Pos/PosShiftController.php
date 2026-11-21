<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CloseShiftRequest;
use App\Http\Requests\Pos\OpenShiftRequest;
use App\Http\Requests\Pos\ShiftExpenseRequest;
use App\Http\Requests\Pos\ShiftWithdrawalRequest;
use App\Models\PosShift;
use App\Services\Pos\PosShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosShiftController extends Controller
{
    public function __construct(
        protected PosShiftService $shiftService,
    ) {}

    /**
     * Paginated list of all POS shifts.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->input('status'),
            'search' => $request->input('search'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $perPage = $request->integer('per_page', 12);
        $paginated = $this->shiftService->paginateShifts($filters, $perPage);

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
     * List currently open shift(s) belonging strictly to the authenticated user.
     */
    public function openShifts(Request $request): JsonResponse
    {
        $shifts = $this->shiftService->listOpenShifts($request->user());

        return response()->json([
            'success' => true,
            'data' => $shifts,
        ]);
    }

    /**
     * Open a new shift.
     */
    public function store(OpenShiftRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $shift = $this->shiftService->openShift(
            $request->user(),
            $validated['opening_balance'] ?? '0.00',
            $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'تم فتح الوردية بنجاح.',
            'data' => $shift,
        ], 201);
    }

    /**
     * Show shift details and comprehensive financial summary.
     */
    public function show(PosShift $posShift): JsonResponse
    {
        $summary = $this->shiftService->getShiftSummary($posShift);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Close an active shift.
     */
    public function close(CloseShiftRequest $request, PosShift $posShift): JsonResponse
    {
        $validated = $request->validated();

        $closed = $this->shiftService->closeShift(
            $posShift,
            $request->user(),
            $validated['actual_cash'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إغلاق الوردية وتوثيق فروقات النقدية بنجاح.',
            'data' => $this->shiftService->getShiftSummary($closed),
        ]);
    }

    /**
     * List all financial transactions recorded in this shift.
     */
    public function transactions(PosShift $posShift): JsonResponse
    {
        $transactions = $posShift->transactions()
            ->with(['creator', 'sale', 'return'])
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * Record cash expense from an open shift.
     */
    public function recordExpense(ShiftExpenseRequest $request, PosShift $posShift): JsonResponse
    {
        $validated = $request->validated();

        $tx = $this->shiftService->recordExpense(
            $posShift,
            $request->user(),
            $validated['amount'],
            (string) $validated['name'],
            $validated['description'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل المصروف النقدي بنجاح.',
            'data' => $tx,
        ], 201);
    }

    /**
     * Record cash withdrawal from an open shift.
     */
    public function recordWithdrawal(ShiftWithdrawalRequest $request, PosShift $posShift): JsonResponse
    {
        $validated = $request->validated();

        $tx = $this->shiftService->recordWithdrawal(
            $posShift,
            $request->user(),
            $validated['amount'],
            (string) $validated['name'],
            $validated['description'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل السحب النقدي بنجاح.',
            'data' => $tx,
        ], 201);
    }

    /**
     * List all sales belonging to this shift with pagination.
     */
    public function sales(PosShift $posShift, Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 20);

        $paginated = $posShift->sales()
            ->with(['customer:id,name,phone', 'creator:id,name', 'paymentMethod:id,name'])
            ->orderByDesc('id')
            ->paginate($perPage);

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
}
