<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;

class PosPaymentMethodController extends Controller
{
    /**
     * List payment methods for POS.
     */
    public function index(): JsonResponse
    {
        $methods = PaymentMethod::query()
            ->orderBy('id', 'asc')
            ->get(['id', 'name', 'type']);

        return response()->json([
            'success' => true,
            'data' => $methods,
        ]);
    }
}
