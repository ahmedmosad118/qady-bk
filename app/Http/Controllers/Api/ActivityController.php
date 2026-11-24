<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\ActivityIndexRequest;
use App\Models\Activity;
use App\Services\Activity\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class ActivityController extends Controller
{
    public function __construct(
        protected ActivityService $activityService,
    ) {}

    /**
     * List paginated activity logs with analytics and filter options.
     */
    public function index(ActivityIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 15);

        $result = $this->activityService->index($validated, $perPage);
        $paginator = $result['paginator'];
        $paginator->setCollection(new Collection($result['items']));

        return $this->paginatedResponse($paginator, [
            'analytics' => $result['analytics'],
        ]);
    }

    /**
     * Clear all activity log records.
     */
    public function clearAll(): JsonResponse
    {
        $this->authorize('clear', Activity::class);

        $this->activityService->clearAll();

        return $this->successResponse(null, (string) __('activities.cleared'));
    }
}
