<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StocktakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stocktake\CancelCountRequest;
use App\Http\Requests\Stocktake\CancelStocktakeRequest;
use App\Http\Requests\Stocktake\RecordCountRequest;
use App\Http\Requests\Stocktake\RecountRequest;
use App\Http\Requests\Stocktake\RejectStocktakeRequest;
use App\Http\Requests\Stocktake\StoreStocktakeRequest;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\User;
use App\Repositories\StocktakeRepository;
use App\Services\Inventory\StocktakeQueryService;
use App\Services\Inventory\StocktakeService;
use App\Services\Inventory\StocktakeWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class StocktakeController extends Controller implements HasMiddleware
{
    public function __construct(
        protected StocktakeRepository $repository,
        protected StocktakeService $stocktakeService,
        protected StocktakeWorkflowService $workflowService,
        protected StocktakeQueryService $queryService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.Stocktake::class, only: ['store']),
            new Middleware('can:reject,stocktake', only: ['reject']),
            new Middleware('can:cancel,stocktake', only: ['cancel']),
            new Middleware('can:count,'.Stocktake::class, only: ['recordCount', 'cancelCount', 'recount']),
        ];
    }

    /**
     * Display a listing of stocktakes.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Stocktake::class);

        $filters = [
            'search' => $request->input('search'),
            'warehouse_id' => $request->input('warehouse_id'),
            'scope' => $request->input('scope'),
            'status' => $request->input('status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'created_by' => $request->input('created_by'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->repository->paginate($filters, $perPage);

        return $this->paginatedResponse($paginated);
    }

    /**
     * Store a newly created draft stocktake.
     */
    public function store(StoreStocktakeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $stocktake = $this->stocktakeService->createStocktake($request->validated(), $user);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء مستند الجرد المخزني كمسودة بنجاح.',
            'data' => $stocktake,
        ], 201);
    }

    /**
     * Display the specified stocktake with items protected by Blind Count.
     */
    public function show(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->authorize('view', $stocktake);

        return $this->successResponse(
            $this->queryService->getDetailsForDisplay($stocktake, $request->user())
        );
    }

    /**
     * Start a draft stocktake.
     */
    public function start(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->authorize('start', $stocktake);

        /** @var User $user */
        $user = $request->user();
        $updated = $this->workflowService->start($stocktake, $user);

        return response()->json([
            'success' => true,
            'message' => 'تم بدء تنفيذ الجرد المخزني وتثبيت قائمة المنتجات بنجاح.',
            'data' => $updated,
        ]);
    }

    /**
     * Submit an in-progress or rejected stocktake for approval.
     */
    public function submit(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->authorize('submit', $stocktake);

        /** @var User $user */
        $user = $request->user();
        $updated = $this->workflowService->submit($stocktake, $user);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال مستند الجرد للاعتماد بنجاح بعد اكتمال عد جميع الأصناف.',
            'data' => $updated,
        ]);
    }

    /**
     * Approve a pending stocktake.
     */
    public function approve(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->authorize('approve', $stocktake);

        /** @var User $user */
        $user = $request->user();
        $updated = $this->workflowService->approve($stocktake, $user);

        $msg = $updated->status === StocktakeStatus::COMPLETED
            ? 'تم اعتماد الجرد بنجاح؛ واكتمال المستند فوراً لعدم وجود أية فروقات مخزنية.'
            : 'تم اعتماد نتائج الجرد بنجاح، وتوليد مسودات التسوية المخزنية الناتجة عن الفروقات.';

        return response()->json([
            'success' => true,
            'message' => $msg,
            'data' => $updated,
        ]);
    }

    /**
     * Reject a pending stocktake.
     */
    public function reject(RejectStocktakeRequest $request, Stocktake $stocktake): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reason = (string) $request->input('rejection_reason');
        $updated = $this->workflowService->reject($stocktake, $user, $reason);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض مستند الجرد المخزني بنجاح.',
            'data' => $updated,
        ]);
    }

    /**
     * Cancel a stocktake.
     */
    public function cancel(CancelStocktakeRequest $request, Stocktake $stocktake): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reason = $request->input('reason');
        $updated = $this->workflowService->cancel($stocktake, $user, $reason);

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء مستند الجرد المخزني وتحرير أية أقفال جارية.',
            'data' => $updated,
        ]);
    }

    /**
     * Start counting a single item.
     */
    public function startCounting(Request $request, StocktakeItem $item): JsonResponse
    {
        $this->authorize('count', Stocktake::class);

        /** @var User $user */
        $user = $request->user();
        $updated = $this->stocktakeService->startCounting($item, $user);

        return response()->json([
            'success' => true,
            'message' => 'تم بدء عد المنتج بنجاح وقفل حركته مؤقتاً في هذا المخزن.',
            'data' => [
                'id' => $updated->id,
                'count_status' => $updated->count_status->value,
                'count_status_label' => $updated->count_status->label(),
                'count_started_by' => $updated->countStartedBy?->name,
                'count_started_at' => $updated->count_started_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Record counted quantity for an item.
     */
    public function recordCount(RecordCountRequest $request, StocktakeItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $qty = $request->input('counted_quantity');
        $notes = $request->input('notes');

        $updated = $this->stocktakeService->recordCount($item, $user, $qty, $notes);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الكمية الفعلية للمنتج بنجاح وتحرير قفل حركته المخزنية.',
            'data' => [
                'id' => $updated->id,
                'counted_quantity' => (float) $updated->counted_quantity,
                'count_status' => $updated->count_status->value,
                'count_status_label' => $updated->count_status->label(),
                'counted_by' => $updated->countedBy?->name,
                'counted_at' => $updated->counted_at?->toIso8601String(),
                'notes' => $updated->notes,
            ],
        ]);
    }

    /**
     * Cancel count for an item.
     */
    public function cancelCount(CancelCountRequest $request, StocktakeItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reason = (string) $request->input('reason');
        $updated = $this->stocktakeService->cancelCount($item, $user, $reason);

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء عملية العد وإعادة السطر للحالة الأولية وتحرير القفل.',
            'data' => [
                'id' => $updated->id,
                'count_status' => $updated->count_status->value,
                'count_status_label' => $updated->count_status->label(),
            ],
        ]);
    }

    /**
     * Recount an item.
     */
    public function recount(RecountRequest $request, StocktakeItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reason = (string) $request->input('reason');
        $updated = $this->stocktakeService->recount($item, $user, $reason);

        return response()->json([
            'success' => true,
            'message' => 'تم بدء إعادة العد للمنتج وقفل حركته مجدداً.',
            'data' => [
                'id' => $updated->id,
                'count_status' => $updated->count_status->value,
                'count_status_label' => $updated->count_status->label(),
                'count_started_by' => $updated->countStartedBy?->name,
                'count_started_at' => $updated->count_started_at?->toIso8601String(),
            ],
        ]);
    }
}
