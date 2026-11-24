<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Activity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ActivityQueryService
{
    public function __construct(
        protected ActivityModuleRegistry $registry,
    ) {}

    /**
     * Paginate activity logs with filters and eager-loaded causers.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Activity>
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $limit = min(max($perPage, 1), 100);

        $query = Activity::query()
            ->with(['causer'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters, true);

        /** @var LengthAwarePaginator<int, Activity> $paginator */
        $paginator = $query->paginate($limit);

        return $paginator;
    }

    /**
     * Apply search, event, module, causer, month, and date-range filters.
     *
     * @param  Builder<Activity>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    public function applyFilters(Builder $query, array $filters, bool $includeEvent = true): Builder
    {
        if ($includeEvent && ! empty($filters['event'])) {
            $this->applyEventFilter($query, (string) $filters['event']);
        }

        if (! empty($filters['module'])) {
            $module = $filters['module'];
            if (is_array($module)) {
                $query->whereIn('log_name', $module);
            } else {
                $query->where('log_name', (string) $module);
            }
        }

        if (! empty($filters['causer_id'])) {
            $causerId = $filters['causer_id'];
            $query->where('causer_type', User::class);
            if (is_array($causerId)) {
                $query->whereIn('causer_id', array_map('intval', $causerId));
            } else {
                $query->where('causer_id', (int) $causerId);
            }
        }

        if (! empty($filters['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $filters['month']) === 1) {
            $monthStart = Carbon::createFromFormat('!Y-m', (string) $filters['month'])->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $query->whereBetween('created_at', [$monthStart, $monthEnd]);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(static function (Builder $q) use ($search): void {
                $q->where('log_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('properties', 'like', "%{$search}%")
                    ->orWhereHasMorph('causer', [User::class], static function (Builder $causerQuery) use ($search): void {
                        $causerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    /**
     * Batch-load subject models grouped by class (zero N+1), supporting morphMap and SoftDeletes.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<string, Model>
     */
    public function batchResolveSubjects(Collection $activities): array
    {
        /** @var array<string, list<int|string>> $idsByType */
        $idsByType = [];

        foreach ($activities as $activity) {
            if (empty($activity->subject_type) || empty($activity->subject_id)) {
                continue;
            }

            $idsByType[(string) $activity->subject_type][] = $activity->subject_id;
        }

        /** @var array<string, Model> $resolved */
        $resolved = [];

        foreach ($idsByType as $rawType => $ids) {
            $modelClass = $this->registry->resolveSubjectClass($rawType);
            if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            $uniqueIds = array_values(array_unique($ids));
            $modelQuery = $modelClass::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                /** @phpstan-ignore method.notFound */
                $modelQuery->withTrashed();
            }

            /** @var Collection<int, Model> $models */
            $models = $modelQuery->whereKey($uniqueIds)->get();

            foreach ($models as $model) {
                $resolved[$this->subjectCacheKey($rawType, $model->getKey())] = $model;
            }
        }

        return $resolved;
    }

    public function subjectCacheKey(?string $subjectType, mixed $subjectId): string
    {
        return ((string) $subjectType).'#'.((string) $subjectId);
    }

    /**
     * @param  Builder<Activity>  $query
     */
    protected function applyEventFilter(Builder $query, string $event): void
    {
        $normalized = mb_strtolower(trim($event));

        if ($normalized === 'created') {
            $query->where(static function (Builder $q): void {
                $q->where('event', 'created')
                    ->orWhere('description', 'created');
            });

            return;
        }

        if ($normalized === 'updated') {
            $query->where(static function (Builder $q): void {
                $q->where('event', 'updated')
                    ->orWhere('description', 'updated');
            });

            return;
        }

        if ($normalized === 'deleted') {
            $query->where(static function (Builder $q): void {
                $q->where('event', 'deleted')
                    ->orWhere('description', 'deleted');
            });

            return;
        }

        $query->where('event', $event);
    }
}
