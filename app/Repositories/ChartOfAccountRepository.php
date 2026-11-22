<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\Accounting\AccountingSettingRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ChartOfAccountRepository
{
    /**
     * Paginated flat listing of chart accounts with server-side filters and usage flags.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ChartOfAccount::query();

        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (! empty($filters['account_type'])) {
            $query->ofType((string) $filters['account_type']);
        }

        if (! empty($filters['account_purpose'])) {
            $query->ofPurpose((string) $filters['account_purpose']);
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['code', 'name', 'account_type', 'account_purpose', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'code';

        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $limit = min(max($perPage, 1), 100);

        $paginator = $query->orderBy($sortBy, $sortOrder)->paginate($limit);

        $items = $paginator->getCollection();
        if ($items->isEmpty()) {
            return $paginator;
        }

        $accountIds = $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $linkedSettingIds = $this->getLinkedSettingAccountIdSet();
        $linkedPaymentMethodIds = $this->getLinkedPaymentMethodAccountIdSet($accountIds);

        $paginator->setCollection($items->map(function (ChartOfAccount $account) use (
            $linkedSettingIds,
            $linkedPaymentMethodIds
        ): array {
            $isLinkedSetting = isset($linkedSettingIds[$account->id]);
            $isLinkedPaymentMethod = isset($linkedPaymentMethodIds[$account->id]);
            $purpose = $account->account_purpose ?? AccountPurpose::GENERAL;

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'account_type_label' => $account->account_type->label(),
                'account_purpose' => $purpose->value,
                'account_purpose_label' => $purpose->label(),
                'description' => $account->description,
                'is_postable' => true,
                'is_linked_to_settings' => $isLinkedSetting,
                'is_linked_to_payment_method' => $isLinkedPaymentMethod,
                'can_delete' => ! $isLinkedSetting && ! $isLinkedPaymentMethod,
                'created_at' => $account->created_at,
                'updated_at' => $account->updated_at,
            ];
        }));

        return $paginator;
    }

    /**
     * Load detailed view of a single account with usage flags and linked entities.
     *
     * @return array<string, mixed>
     */
    public function getDetails(ChartOfAccount $account): array
    {
        $account->load([
            'paymentMethods:id,name,type,chart_of_account_id',
        ]);

        $linkedSettingIds = $this->getLinkedSettingAccountIdSet();
        $linkedPaymentMethodIds = $this->getLinkedPaymentMethodAccountIdSet([$account->id]);

        $isLinkedSetting = isset($linkedSettingIds[$account->id]);
        $isLinkedPaymentMethod = isset($linkedPaymentMethodIds[$account->id]);
        $purpose = $account->account_purpose ?? AccountPurpose::GENERAL;

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type->value,
            'account_type_label' => $account->account_type->label(),
            'account_purpose' => $purpose->value,
            'account_purpose_label' => $purpose->label(),
            'description' => $account->description,
            'is_postable' => true,
            'is_linked_to_settings' => $isLinkedSetting,
            'is_linked_to_payment_method' => $isLinkedPaymentMethod,
            'can_delete' => ! $isLinkedSetting && ! $isLinkedPaymentMethod,
            'payment_methods' => $account->paymentMethods,
            'created_at' => $account->created_at,
            'updated_at' => $account->updated_at,
        ];
    }

    /**
     * Summary counters by account type.
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

        return [
            'total' => $total,
            AccountType::ASSET->value => (int) ($byType[AccountType::ASSET->value] ?? 0),
            AccountType::LIABILITY->value => (int) ($byType[AccountType::LIABILITY->value] ?? 0),
            AccountType::EQUITY->value => (int) ($byType[AccountType::EQUITY->value] ?? 0),
            AccountType::REVENUE->value => (int) ($byType[AccountType::REVENUE->value] ?? 0),
            AccountType::EXPENSE->value => (int) ($byType[AccountType::EXPENSE->value] ?? 0),
        ];
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
     * @param  array<int, int>  $filterIds
     * @return array<int, true>
     */
    private function getLinkedPaymentMethodAccountIdSet(array $filterIds): array
    {
        $ids = PaymentMethod::query()
            ->whereIn('chart_of_account_id', $filterIds)
            ->pluck('chart_of_account_id');

        $set = [];
        foreach ($ids as $id) {
            if ($id !== null) {
                $set[(int) $id] = true;
            }
        }

        return $set;
    }
}
