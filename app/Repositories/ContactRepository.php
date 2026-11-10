<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Contact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ContactRepository
{
    /**
     * Get paginated contacts with the default address eager-loaded.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Contact::query()->with('defaultAddress');

        if (!empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['is_customer'])) {
            $query->customers();
        }

        if (!empty($filters['is_supplier'])) {
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
        return [
            'total' => Contact::query()->count(),
            'customers' => Contact::query()->where('is_customer', true)->count(),
            'suppliers' => Contact::query()->where('is_supplier', true)->count(),
            'active' => Contact::query()->where('is_active', true)->count(),
            'inactive' => Contact::query()->where('is_active', false)->count(),
        ];
    }
}
