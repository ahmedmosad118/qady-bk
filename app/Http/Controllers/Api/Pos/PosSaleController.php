<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StorePosSaleRequest;
use App\Models\PosSale;
use App\Services\Pos\PosSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosSaleController extends Controller
{
    public function __construct(
        protected PosSaleService $saleService,
    ) {}

    /**
     * Search products for POS catalog lookup (by name, sku, or barcode).
     */
    public function products(Request $request): JsonResponse
    {
        $query = (string) $request->input('search', '');
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $products = $this->saleService->searchProducts($query, $warehouseId);

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Lookup product by exact barcode.
     */
    public function lookup(Request $request): JsonResponse
    {
        $barcode = trim((string) $request->input('barcode', ''));
        $warehouseId = $request->integer('warehouse_id') ?: null;

        if ($barcode === '') {
            return response()->json([
                'success' => false,
                'message' => 'يرجى إدخال الباركود للبحث.',
            ], 422);
        }

        $product = $this->saleService->lookupBarcode($barcode, $warehouseId);

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم العثور على أي منتج مرتبط بهذا الباركود.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    /**
     * Store a finalized POS sale invoice.
     */
    public function store(StorePosSaleRequest $request): JsonResponse
    {
        $sale = $this->saleService->createSale(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء وترحيل فاتورة البيع وخصم المخزون بنجاح.',
            'data' => $sale,
        ], 201);
    }

    /**
     * Display a sale invoice by its official unique invoice number.
     */
    public function byNumber(string $invoiceNumber): JsonResponse
    {
        $sale = $this->saleService->getSaleByInvoiceNumber($invoiceNumber);

        return response()->json([
            'success' => true,
            'data' => $sale,
        ]);
    }

    /**
     * Display a sale invoice by its ID.
     */
    public function show(PosSale $posSale): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->saleService->getSaleDetails($posSale),
        ]);
    }
}
