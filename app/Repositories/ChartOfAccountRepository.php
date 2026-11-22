<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Services\Accounting\AccountingSettingRegistry;
use App\Services\Accounting\AccountingSettingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ChartOfAccountRepository
{
    public function __construct(
        protected AccountingSettingService $accountingSettingService,
    ) {}

    /**
     * Build the full or filtered hierarchical tree in constant SQL queries (zero N+1).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getTree(array $filters = []): array
    {
        /** @var Collection<int, ChartOfAccount> $all */
        $all = ChartOfAccount::query()
            ->withCount('children')
            ->orderBy('code')
            ->get(['id', 'parent_id', 'code', 'name', 'account_type', 'description', 'created_at', 'updated_at']);

        if ($all->isEmpty()) {
            return [];
        }

        $linkedSettingIds = $this->getLinkedSettingAccountIdSet();
        $linkedContactIds = $this->getLinkedContactAccountIdSet();

        $byId = [];
        $childrenMap = [];

        foreach ($all as $account) {
            $byId[$account->id] = $account;
            $parentKey = $account->parent_id ?? 0;
            $childrenMap[$parentKey][] = $account->id;
        }

        $search = isset($filters['search']) ? mb_strtolower(trim((string) $filters['search'])) : '';
        $accountType = isset($filters['account_type']) && $filters['account_type'] !== ''
            ? (string) $filters['account_type']
            : null;
        $rootFilterId = isset($filters['parent_id']) && $filters['parent_id'] !== '' && $filters['parent_id'] !== null
            ? (int) $filters['parent_id']
            : null;

        $hasFilter = $search !== '' || $accountType !== null || $rootFilterId !== null;
        $includedSet = null;
        $matchedSet = [];

        if ($hasFilter) {
            $subtreeSet = null;
            if ($rootFilterId !== null) {
                $subtreeSet = isset($byId[$rootFilterId])
                    ? $this->collectSubtreeIds($rootFilterId, $childrenMap)
                    : [];
            }

            foreach ($all as $account) {
                if ($subtreeSet !== null && ! isset($subtreeSet[$account->id])) {
                    continue;
                }

                if ($accountType !== null && $account->account_type->value !== $accountType) {
                    continue;
                }

                if ($search !== '') {
                    $haystack = mb_strtolower($account->code.' '.$account->name.' '.($account->description ?? ''));
                    if (! str_contains($haystack, $search)) {
                        continue;
                    }
                }

                $matchedSet[$account->id] = true;
            }

            $includedSet = [];
            foreach (array_keys($matchedSet) as $matchedId) {
                $cursor = $matchedId;
                $guard = 0;
                while ($cursor !== null && isset($byId[$cursor]) && $guard < 100) {
                    if (isset($includedSet[$cursor])) {
                        break;
                    }
                    $includedSet[$cursor] = true;
                    $cursor = $byId[$cursor]->parent_id;
                    $guard++;
                }
            }
        }

        $buildNode = function (int $accountId, int $level) use (
            &$buildNode,
            $byId,
            $childrenMap,
            $linkedSettingIds,
            $linkedContactIds,
            $includedSet,
            $matchedSet,
            $hasFilter
        ): array {
            $account = $byId[$accountId];
            $isGroup = (int) ($account->children_count ?? 0) > 0;
            $isLinkedSetting = isset($linkedSettingIds[$account->id]);
            $isLinkedContact = isset($linkedContactIds[$account->id]);

            $childNodes = [];
            foreach ($childrenMap[$accountId] ?? [] as $childId) {
                if ($includedSet !== null && ! isset($includedSet[$childId])) {
                    continue;
                }
                $childNodes[] = $buildNode($childId, $level + 1);
            }

            return [
                'id' => $account->id,
                'parent_id' => $account->parent_id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'account_type_label' => $account->account_type->label(),
                'description' => $account->description,
                'level' => $level,
                'children_count' => (int) ($account->children_count ?? 0),
                'is_group' => $isGroup,
                'is_postable' => ! $isGroup,
                'is_linked_to_settings' => $isLinkedSetting,
                'is_linked_to_contact' => $isLinkedContact,
                'can_delete' => ! $isGroup && ! $isLinkedSetting && ! $isLinkedContact,
                'matches_filter' => ! $hasFilter || isset($matchedSet[$account->id]),
                'children' => $childNodes,
            ];
        };

        $tree = [];
        foreach ($childrenMap[0] ?? [] as $rootId) {
            if ($includedSet !== null && ! isset($includedSet[$rootId])) {
                continue;
            }
            $tree[] = $buildNode($rootId, 1);
        }

        return $tree;
    }

    /**
     * Paginated flat listing of chart accounts with server-side filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ChartOfAccount::query()
            ->with(['parent:id,code,name'])
            ->withCount('children');

        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (! empty($filters['account_type'])) {
            $query->ofType((string) $filters['account_type']);
        }

        if (array_key_exists('parent_id', $filters) && $filters['parent_id'] !== null && $filters['parent_id'] !== '') {
            if ($filters['parent_id'] === 'root') {
                $query->roots();
            } else {
                $query->where('parent_id', (int) $filters['parent_id']);
            }
        }

        if (! empty($filters['postable_only'])) {
            $query->postable();
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['code', 'name', 'account_type', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'code';

        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * Load detailed view of a single account with usage flags and level.
     *
     * @return array<string, mixed>
     */
    public function getDetails(ChartOfAccount $account): array
    {
        $account->load([
            'parent:id,parent_id,code,name,account_type',
            'children' => fn ($q) => $q->withCount('children')->orderBy('code'),
            'receivableContact:id,name',
            'payableContact:id,name',
        ])->loadCount('children');

        $isGroup = $account->isGroup();
        $linkedSettingIds = $this->getLinkedSettingAccountIdSet();
        $linkedContactIds = $this->getLinkedContactAccountIdSet();

        $isLinkedSetting = isset($linkedSettingIds[$account->id]);
        $isLinkedContact = isset($linkedContactIds[$account->id]);

        return [
            'id' => $account->id,
            'parent_id' => $account->parent_id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type->value,
            'account_type_label' => $account->account_type->label(),
            'description' => $account->description,
            'level' => $account->calculateLevel(),
            'children_count' => (int) ($account->children_count ?? 0),
            'is_group' => $isGroup,
            'is_postable' => ! $isGroup,
            'is_linked_to_settings' => $isLinkedSetting,
            'is_linked_to_contact' => $isLinkedContact,
            'can_delete' => ! $isGroup && ! $isLinkedSetting && ! $isLinkedContact,
            'parent' => $account->parent,
            'children' => $account->children,
            'receivable_contact' => $account->receivableContact,
            'payable_contact' => $account->payableContact,
            'created_at' => $account->created_at,
            'updated_at' => $account->updated_at,
        ];
    }

    /**
     * Summary counters for Chart of Accounts overview cards.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $byType = ChartOfAccount::query()
            ->selectRaw('account_type, COUNT(*) as total')
            ->groupBy('account_type')
            ->pluck('total', 'account_type');

        $total = 0;
        foreach ($byType as $count) {
            $total += (int) $count;
        }

        $groupCount = ChartOfAccount::query()->whereHas('children')->count();

        return [
            'total' => $total,
            'groups' => $groupCount,
            'postable' => $total - $groupCount,
            AccountType::ASSET->value => (int) ($byType[AccountType::ASSET->value] ?? 0),
            AccountType::LIABILITY->value => (int) ($byType[AccountType::LIABILITY->value] ?? 0),
            AccountType::EQUITY->value => (int) ($byType[AccountType::EQUITY->value] ?? 0),
            AccountType::REVENUE->value => (int) ($byType[AccountType::REVENUE->value] ?? 0),
            AccountType::EXPENSE->value => (int) ($byType[AccountType::EXPENSE->value] ?? 0),
        ];
    }

    /**
     * Collect all descendant IDs including $rootId using in-memory adjacency map.
     *
     * @param  array<int, array<int, int>>  $childrenMap
     * @return array<int, true>
     */
    private function collectSubtreeIds(int $rootId, array $childrenMap): array
    {
        $result = [$rootId => true];
        $queue = [$rootId];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($childrenMap[$current] ?? [] as $childId) {
                if (! isset($result[$childId])) {
                    $result[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, true>
     */
    private function getLinkedSettingAccountIdSet(): array
    {
        $setting = Setting::query()
            ->where('type', AccountingSettingRegistry::SETTING_TYPE)
            ->first();

        if (! $setting || ! is_array($setting->data)) {
            return [];
        }

        $set = [];
        foreach (AccountingSettingRegistry::keys() as $key) {
            $id = $setting->data[$key] ?? null;
            if (is_numeric($id) && (int) $id > 0) {
                $set[(int) $id] = true;
            }
        }

        return $set;
    }

    /**
     * @return array<int, true>
     */
    private function getLinkedContactAccountIdSet(): array
    {
        return $this->accountingSettingService->getContactAccountIdSet();
    }
}
