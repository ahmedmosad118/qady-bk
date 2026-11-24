<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Activity;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActivityService
{
    public function __construct(
        protected ActivityQueryService $queryService,
        protected ActivityAnalyticsService $analyticsService,
        protected ActivityPresenter $presenter,
    ) {}

    /**
     * Retrieve paginated activity entries, formatted items, and analytics summary.
     *
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator<int, Activity>, items: list<array<string, mixed>>, analytics: array<string, mixed>}
     */
    public function index(array $filters = [], int $perPage = 15): array
    {
        $paginator = $this->queryService->paginate($filters, $perPage);

        /** @var Collection<int, Activity> $collection */
        $collection = $paginator->getCollection();
        $resolvedSubjects = $this->queryService->batchResolveSubjects($collection);

        $items = $collection
            ->map(function (Activity $activity) use ($resolvedSubjects): array {
                $key = $this->queryService->subjectCacheKey($activity->subject_type, $activity->subject_id);

                return $this->presenter->present($activity, $resolvedSubjects[$key] ?? null);
            })
            ->values()
            ->all();

        $analytics = $this->analyticsService->getSummary($filters);

        return [
            'paginator' => $paginator,
            'items' => $items,
            'analytics' => $analytics,
        ];
    }

    /**
     * Permanently clear all activity log records.
     */
    public function clearAll(): void
    {
        DB::transaction(static function (): void {
            Activity::query()->delete();
        });
    }
}
