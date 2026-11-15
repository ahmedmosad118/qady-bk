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
     * Paginated list of finalized POS sales invoices.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'shift_id' => $request->input('shift_id'),
            'payment_method_id' => $request->input('payment_method_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $perPage = $request->integer('per_page', 12);
        $paginated = $this->saleService->paginateSales($filters, $perPage);

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
     * Search and paginate products for POS catalog lookup (by name, sku, or barcode).
     */
    public function products(Request $request): JsonResponse
    {
        $query = (string) $request->input('search', '');
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;
        $perPage = min(100, max(1, $request->integer('per_page', 24)));

        $paginated = $this->saleService->paginateProducts($query, $warehouseId, $categoryId, $perPage);

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
