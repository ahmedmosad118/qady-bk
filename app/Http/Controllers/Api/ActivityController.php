<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\ActivityIndexRequest;
use App\Models\Activity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class ActivityController extends Controller
{
    /**
     * List paginated activity logs with filters (ported directly from plamx-v-5 ActivitiesController).
     */
    public function index(ActivityIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = min(max((int) ($validated['per_page'] ?? 15), 1), 100);

        $event = $validated['event'] ?? null;
        $module = $validated['module'] ?? null;
        $causerId = $validated['causer_id'] ?? null;
        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;
        $search = trim((string) ($validated['search'] ?? ''));

        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $query->when($event, static function (Builder $q) use ($event): void {
            if ($event === 'created') {
                $q->where(static fn (Builder $sub) => $sub->where('event', 'created')->orWhere('description', 'created'));
            } elseif ($event === 'updated') {
                $q->where(static fn (Builder $sub) => $sub->where('event', 'updated')->orWhere('description', 'updated'));
            } elseif ($event === 'deleted') {
                $q->where(static fn (Builder $sub) => $sub->where('event', 'deleted')->orWhere('description', 'deleted'));
            } else {
                $q->where('event', $event);
            }
        });

        $query->when($module, static fn (Builder $q) => $q->where('log_name', $module));

        $query->when($causerId, static function (Builder $q) use ($causerId): void {
            $q->where('causer_type', User::class)->where('causer_id', (int) $causerId);
        });

        $query->when($dateFrom, static function (Builder $q) use ($dateFrom): void {
            $q->where('created_at', '>=', Carbon::parse((string) $dateFrom)->startOfDay());
        });

        $query->when($dateTo, static function (Builder $q) use ($dateTo): void {
            $q->where('created_at', '<=', Carbon::parse((string) $dateTo)->endOfDay());
        });

        $query->when($search !== '', static function (Builder $q) use ($search): void {
            $q->where(static function (Builder $sub) use ($search): void {
                $sub->where('log_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('properties', 'like', "%{$search}%")
                    ->orWhereHasMorph('causer', [User::class], static function (Builder $causerQuery) use ($search): void {
                        $causerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        });

        $paginator = $query->paginate($perPage);

        return $this->paginatedResponse($paginator, [
            'filters' => [
                'modules' => $this->modulesList(),
                'users' => User::query()->select(['id', 'name'])->orderBy('name')->get()->toArray(),
            ],
        ]);
    }

    /**
     * Delete a single activity log record.
     */
    public function destroy(Activity $activity): JsonResponse
    {
        $this->authorize('delete', $activity);

        $activity->delete();

        return $this->successResponse(null, (string) __('activities.deleted'));
    }

    /**
     * Clear all activity log records.
     */
    public function clearAll(): JsonResponse
    {
        $this->authorize('clear', Activity::class);

        Activity::query()->delete();

        return $this->successResponse(null, (string) __('activities.cleared'));
    }

    /**
     * Build sorted module dropdown options from Activity::$activityData + DB log names.
     *
     * @return list<array{key: string, label: string}>
     */
    protected function modulesList(): array
    {
        /** @var list<string> $dbModules */
        $dbModules = Activity::query()
            ->select('log_name')
            ->distinct()
            ->whereNotNull('log_name')
            ->where('log_name', '!=', '')
            ->pluck('log_name')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();

        $allKeys = array_unique(array_merge(array_keys((new Activity)->activityData), $dbModules));

        $modules = array_map(static function (string $key): array {
            $langKey = "activities.modules.{$key}";
            $label = Lang::has($langKey) ? (string) __($langKey) : Str::headline(str_replace('_', ' ', $key));

            return [
                'key' => $key,
                'label' => $label,
            ];
        }, $allKeys);

        usort($modules, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $modules;
    }
}
