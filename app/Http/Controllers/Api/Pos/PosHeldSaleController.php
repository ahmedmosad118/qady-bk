<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StorePosHeldSaleRequest;
use App\Http\Requests\Pos\UpdatePosHeldSaleRequest;
use App\Models\PosHeldSale;
use App\Services\Pos\PosHeldSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosHeldSaleController extends Controller
{
    public function __construct(
        protected PosHeldSaleService $heldSaleService,
    ) {}

    /**
     * List held sales.
     */
    public function index(Request $request): JsonResponse
    {
        $shiftId = $request->integer('shift_id') ?: null;
        $heldSales = $this->heldSaleService->listHeldSales($shiftId);

        return response()->json([
            'success' => true,
            'data' => $heldSales,
        ]);
    }

    /**
     * Hold a new sale cart.
     */
    public function store(StorePosHeldSaleRequest $request): JsonResponse
    {
        $heldSale = $this->heldSaleService->holdSale(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تعليق الفاتورة بنجاح.',
            'data' => $heldSale,
        ], 201);
    }

    /**
     * Show held sale details.
     */
    public function show(PosHeldSale $posHeldSale): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->heldSaleService->getHeldSale($posHeldSale),
        ]);
    }

    /**
     * Update an existing held sale cart.
     */
    public function update(UpdatePosHeldSaleRequest $request, PosHeldSale $posHeldSale): JsonResponse
    {
        $updated = $this->heldSaleService->updateHeldSale(
            $posHeldSale,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الفاتورة المعلقة بنجاح.',
            'data' => $updated,
        ]);
    }

    /**
     * Remove / discard a held sale cart.
     */
    public function destroy(PosHeldSale $posHeldSale): JsonResponse
    {
        $this->heldSaleService->deleteHeldSale($posHeldSale);

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الفاتورة المعلقة بنجاح.',
        ]);
    }
}
