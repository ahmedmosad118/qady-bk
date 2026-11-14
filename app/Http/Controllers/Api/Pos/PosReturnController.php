<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StorePosReturnRequest;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Services\Pos\PosReturnService;
use Illuminate\Http\JsonResponse;

class PosReturnController extends Controller
{
    public function __construct(
        protected PosReturnService $returnService,
    ) {}

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
            'data' => $posReturn->load(['items.product', 'items.saleItem', 'paymentMethod', 'sale', 'shift', 'creator']),
        ]);
    }
}
