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
        $this->authorize('viewAny', PaymentMethod::class);

        $methods = PaymentMethod::query()
            ->with('account:id,code,name')
            ->orderBy('id', 'asc')
            ->get(['id', 'name', 'type', 'account_id']);

        return response()->json([
            'success' => true,
            'data' => $methods,
        ]);
    }
}
