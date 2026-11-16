<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StorePosReturnRequest;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Services\Pos\PosReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosReturnController extends Controller
{
    public function __construct(
        protected PosReturnService $returnService,
    ) {}

    /**
     * Paginated list of POS returns.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'shift_id' => $request->input('shift_id'),
            'pos_sale_id' => $request->input('pos_sale_id'),
            'payment_method_id' => $request->input('payment_method_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $perPage = $request->integer('per_page', 12);
        $paginated = $this->returnService->paginateReturns($filters, $perPage);

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
     * Inspect returnable quantities and amounts for a sale invoice.
     */
    public function returnableItems(PosSale $posSale): JsonResponse
    {
        $details = $this->returnService->getReturnableItems($posSale);

        return response()->json([
            'success' => true,
            'data' => $details,
        ]);
    }

    /**
     * Process a new POS return.
     */
    public function store(StorePosReturnRequest $request): JsonResponse
    {
        $return = $this->returnService->processReturn(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل المرتجع وترحيل حركة إرجاع المخزون بنجاح.',
            'data' => $return,
        ], 201);
    }

    /**
     * Display the specified POS return.
     */
    public function show(PosReturn $posReturn): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->returnService->getReturnDetails($posReturn),
        ]);
    }
}
