<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Contact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ContactRepository
{
    /**
     * Get paginated contacts with the default address eager-loaded.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Contact::query()->with([
            'defaultAddress',
            'receivableAccount:id,code,name',
            'payableAccount:id,code,name',
        ]);

        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['is_customer'])) {
            $query->customers();
        }

        if (! empty($filters['is_supplier'])) {
            $query->suppliers();
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['name', 'type', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'id';

        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * Aggregate counters used by the contacts dashboard cards.
     *
     * @return array{total: int, customers: int, suppliers: int, active: int, inactive: int}
     */
    public function stats(): array
    {
        $row = Contact::query()
            ->selectRaw(
                'COUNT(*) as total, '
                .'COALESCE(SUM(CASE WHEN is_customer = 1 THEN 1 ELSE 0 END), 0) as customers, '
                .'COALESCE(SUM(CASE WHEN is_supplier = 1 THEN 1 ELSE 0 END), 0) as suppliers, '
                .'COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) as active, '
                .'COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) as inactive'
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'customers' => (int) ($row->customers ?? 0),
            'suppliers' => (int) ($row->suppliers ?? 0),
            'active' => (int) ($row->active ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
        ];
    }
}
