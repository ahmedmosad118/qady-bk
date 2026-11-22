<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StorePosSaleRequest;
use App\Models\PosSale;
use App\Services\Pos\PosProductCatalogService;
use App\Services\Pos\PosSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PosSaleController extends Controller implements HasMiddleware
{
    public function __construct(
        protected PosSaleService $saleService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:sell,'.PosSale::class, only: ['store']),
        ];
    }

    /**
     * Paginated list of finalized POS sales invoices.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PosSale::class);

        $paginated = $this->saleService->paginateSales(
            $request->only(['search', 'shift_id', 'payment_method_id', 'date_from', 'date_to']),
            $request->integer('per_page', 12)
        );

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
     * Search and paginate products for POS catalog lookup (by name or barcode).
     */
    public function products(Request $request): JsonResponse
    {
        $this->authorize('sell', PosSale::class);

        $paginated = $this->saleService->paginateProducts(
            (string) $request->input('search', ''),
            null,
            $request->integer('category_id') ?: null,
            min(100, max(1, $request->integer('per_page', PosProductCatalogService::DEFAULT_PER_PAGE)))
        );

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem() ?? 0,
                'to' => $paginated->lastItem() ?? 0,
            ],
        ]);
    }

    /**
     * Lookup product by exact barcode.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('sell', PosSale::class);

        $barcode = trim((string) $request->input('barcode', ''));

        if ($barcode === '') {
            return response()->json([
                'success' => false,
                'message' => __('pos.errors.barcode_required'),
            ], 422);
        }

        $product = $this->saleService->lookupBarcode($barcode);

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => __('pos.errors.barcode_not_found'),
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
        $sale = $this->saleService->createSale($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => __('pos.messages.sale_created'),
            'data' => $sale,
        ], 201);
    }

    /**
     * Display a sale invoice by its official unique invoice number.
     */
    public function byNumber(string $invoiceNumber): JsonResponse
    {
        $this->authorize('view', PosSale::class);

        return response()->json([
            'success' => true,
            'data' => $this->saleService->getSaleByInvoiceNumber($invoiceNumber),
        ]);
    }

    /**
     * Display a sale invoice by its ID.
     */
    public function show(PosSale $posSale): JsonResponse
    {
        $this->authorize('view', PosSale::class);

        return response()->json([
            'success' => true,
            'data' => $this->saleService->getSaleDetails($posSale),
        ]);
    }
}
