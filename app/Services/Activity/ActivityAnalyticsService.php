<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Activity;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class ActivityAnalyticsService
{
    public function __construct(
        protected ActivityQueryService $queryService,
        protected ActivityModuleRegistry $registry,
    ) {}

    /**
     * Compute activity summary cards, monthly breakdown, top contributors, and filter options.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getSummary(array $filters = []): array
    {
        $globalCounts = $this->countByEvent(Activity::query());
        $totalActivities = $globalCounts['total'];

        $thisMonthCount = Activity::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $monthlyBreakdown = $this->buildMonthlyBreakdown($totalActivities);
        $mostActiveMonth = $monthlyBreakdown[0] ?? null;

        // Sort monthly breakdown chronologically descending for dropdowns & tables.
        usort($monthlyBreakdown, static fn (array $a, array $b): int => strcmp($b['key'], $a['key']));

        $topUsersList = $this->buildTopUsers($totalActivities);
        $topUser = $topUsersList[0] ?? null;
        $topModule = $this->resolveTopModule();

        $filteredQuery = Activity::query();
        $this->queryService->applyFilters($filteredQuery, $filters, false);
        $filteredStats = $this->countByEvent($filteredQuery);

        /** @var list<string> $dbModules */
        $dbModules = Activity::query()
            ->select('log_name')
            ->distinct()
            ->whereNotNull('log_name')
            ->where('log_name', '!=', '')
            ->pluck('log_name')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();

        $usersList = User::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
            ])
            ->values()
            ->all();

        return [
            'total_activities' => $totalActivities,
            'created_count' => $globalCounts['created'],
            'updated_count' => $globalCounts['updated'],
            'deleted_count' => $globalCounts['deleted'],
            'this_month_count' => $thisMonthCount,
            'most_active_month' => $mostActiveMonth,
            'monthly_breakdown' => $monthlyBreakdown,
            'top_user' => $topUser,
            'top_users_list' => $topUsersList,
            'top_module' => $topModule,
            'filtered_stats' => $filteredStats,
            'modules_list' => $this->registry->options($dbModules),
            'users_list' => $usersList,
        ];
    }

    /**
     * Count total, created, updated, and deleted events in one grouped query.
     *
     * @param  Builder<Activity>  $query
     * @return array{total: int, created: int, updated: int, deleted: int}
     */
    protected function countByEvent(Builder $query): array
    {
        $rows = (clone $query)
            ->selectRaw('COALESCE(event, description, \'\') as action_key, COUNT(*) as aggregate_count')
            ->groupBy('action_key')
            ->get();

        $counts = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
        ];

        foreach ($rows as $row) {
            $count = (int) $row->getAttribute('aggregate_count');
            $counts['total'] += $count;

            $action = mb_strtolower(trim((string) $row->getAttribute('action_key')));
            if ($action === 'created') {
                $counts['created'] += $count;
            } elseif ($action === 'updated') {
                $counts['updated'] += $count;
            } elseif ($action === 'deleted') {
                $counts['deleted'] += $count;
            }
        }

        return $counts;
    }

    /**
     * Build monthly counts ordered by highest count first (so index 0 is peak month).
     *
     * @return list<array{year: int, month: int, key: string, label: string, count: int, percentage: float}>
     */
    protected function buildMonthlyBreakdown(int $totalActivities): array
    {
        $monthExpr = $this->monthGroupExpression();

        $rows = Activity::query()
            ->selectRaw("{$monthExpr} as month_key, COUNT(*) as aggregate_count")
            ->whereNotNull('created_at')
            ->groupBy('month_key')
            ->orderByDesc('aggregate_count')
            ->get();

        $locale = App::getLocale();
        $result = [];

        foreach ($rows as $row) {
            $monthKey = (string) $row->getAttribute('month_key');
            if (preg_match('/^(\d{4})-(\d{2})$/', $monthKey, $matches) !== 1) {
                continue;
            }

            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $count = (int) $row->getAttribute('aggregate_count');
            $carbon = Carbon::create($year, $month, 1)->locale($locale);

            $result[] = [
                'year' => $year,
                'month' => $month,
                'key' => $monthKey,
                'label' => $carbon->translatedFormat('F Y'),
                'count' => $count,
                'percentage' => $totalActivities > 0 ? round(($count / $totalActivities) * 100, 1) : 0.0,
            ];
        }

        return $result;
    }

    /**
     * Cross-driver SQL expression extracting YYYY-MM from created_at.
     */
    protected function monthGroupExpression(): string
    {
        $driver = DB::connection(config('activitylog.database_connection'))->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            default => "DATE_FORMAT(created_at, '%Y-%m')",
        };
    }

    /**
     * @return list<array{
     *     rank: int,
     *     user_id: int,
     *     name: string,
     *     email: string,
     *     role: string,
     *     initial: string,
     *     count: int,
     *     percentage: float
     * }>
     */
    protected function buildTopUsers(int $totalActivities): array
    {
        $rows = Activity::query()
            ->selectRaw('causer_id, COUNT(*) as aggregate_count')
            ->where('causer_type', User::class)
            ->whereNotNull('causer_id')
            ->groupBy('causer_id')
            ->orderByDesc('aggregate_count')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $userIds = $rows->pluck('causer_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $usersMap = User::query()
            ->with('roles:id,name')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $list = [];
        foreach ($rows as $index => $row) {
            $userId = (int) $row->getAttribute('causer_id');
            /** @var User|null $user */
            $user = $usersMap->get($userId);
            $name = $user !== null ? $user->name : (string) __('activities.values.unknown_user');
            $firstRole = $user !== null ? $user->roles->first() : null;
            $roleName = $firstRole instanceof Role ? $firstRole->name : '';
            $count = (int) $row->getAttribute('aggregate_count');

            $list[] = [
                'rank' => $index + 1,
                'user_id' => $userId,
                'name' => $name,
                'email' => $user !== null ? $user->email : '',
                'role' => $roleName,
                'initial' => mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8'),
                'count' => $count,
                'percentage' => $totalActivities > 0 ? round(($count / $totalActivities) * 100, 1) : 0.0,
            ];
        }

        return $list;
    }

    /**
     * @return array{key: string, label: string, count: int}|null
     */
    protected function resolveTopModule(): ?array
    {
        $row = Activity::query()
            ->selectRaw('log_name, COUNT(*) as aggregate_count')
            ->whereNotNull('log_name')
            ->where('log_name', '!=', '')
            ->groupBy('log_name')
            ->orderByDesc('aggregate_count')
            ->first();

        if ($row === null) {
            return null;
        }

        $logName = (string) $row->getAttribute('log_name');

        return [
            'key' => $logName,
            'label' => $this->registry->label($logName),
            'count' => (int) $row->getAttribute('aggregate_count'),
        ];
    }
}
