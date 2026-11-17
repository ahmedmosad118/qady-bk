<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\SyncPosSaleRequest;
use App\Services\Pos\PosSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosSyncController extends Controller
{
    public function __construct(
        protected PosSyncService $syncService,
    ) {}

    /**
     * Bootstrap data for local offline client (products, current stock, prices, payment methods).
     */
    public function bootstrap(): JsonResponse
    {
        $data = $this->syncService->getBootstrapData();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Synchronize a local offline sale transaction.
     */
    public function syncSales(SyncPosSaleRequest $request): JsonResponse
    {
        $result = $this->syncService->syncOfflineSale(
            $request->validated(),
            $request->user()
        );

        if ($result['status'] === 'conflict') {
            return response()->json([
                'success' => false,
                'status' => 'conflict',
                'is_idempotent' => $result['is_idempotent'],
                'message' => $result['conflict_reason'] ?? 'تعارض في مزامنة الفاتورة.',
                'conflict_reason' => $result['conflict_reason'],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'status' => 'synced',
            'is_idempotent' => $result['is_idempotent'],
            'message' => $result['is_idempotent']
                ? 'تم استرجاع الفاتورة المزامنة مسبقاً بنجاح (Idempotent).'
                : 'تمت مزامنة الفاتورة وترحيل حركاتها بنجاح.',
            'data' => $result['sale'],
        ], $result['is_idempotent'] ? 200 : 201);
    }
}
