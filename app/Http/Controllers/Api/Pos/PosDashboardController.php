<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Services\Pos\PosDashboardService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosDashboardController extends Controller
{
    public function __construct(
        protected PosDashboardService $dashboardService,
    ) {}

    /**
     * Get dashboard summary statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $date = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::now();
        $stats = $this->dashboardService->getTodayStats($date);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
