<?php

declare(strict_types=1);

namespace App\Services\Activity;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class ActivityModuleRegistry
{
    /**
     * Canonical module definitions keyed by Spatie log_name.
     *
     * @return array<string, array{
     *     key: string,
     *     icon: string,
     *     route_prefix: ?string,
     *     append_id: bool,
     *     main_fields: list<string>
     * }>
     */
    public function all(): array
    {
        return [
            'user' => [
                'key' => 'user',
                'icon' => 'users',
                'route_prefix' => '/users?showId=',
                'append_id' => true,
                'main_fields' => ['name', 'email'],
            ],
            'role' => [
                'key' => 'role',
                'icon' => 'shield-check',
                'route_prefix' => '/roles?showId=',
                'append_id' => true,
                'main_fields' => ['name'],
            ],
            'setting' => [
                'key' => 'setting',
                'icon' => 'settings',
                'route_prefix' => '/settings',
                'append_id' => false,
                'main_fields' => ['type'],
            ],
            'product' => [
                'key' => 'product',
                'icon' => 'book-open',
                'route_prefix' => '/products?showId=',
                'append_id' => true,
                'main_fields' => ['name', 'barcode'],
            ],
            'product_category' => [
                'key' => 'product_category',
                'icon' => 'folder-tree',
                'route_prefix' => '/product-categories?showId=',
                'append_id' => true,
                'main_fields' => ['name'],
            ],
            'contact' => [
                'key' => 'contact',
                'icon' => 'contact',
                'route_prefix' => '/contacts?showId=',
                'append_id' => true,
                'main_fields' => ['name', 'phone'],
            ],
            'contact_address' => [
                'key' => 'contact_address',
                'icon' => 'map-pin',
                'route_prefix' => '/contacts',
                'append_id' => false,
                'main_fields' => ['label', 'address', 'city'],
            ],
            'purchase_order' => [
                'key' => 'purchase_order',
                'icon' => 'clipboard-list',
                'route_prefix' => '/purchase-orders?showId=',
                'append_id' => true,
                'main_fields' => ['order_number'],
            ],
            'purchase_invoice' => [
                'key' => 'purchase_invoice',
                'icon' => 'file-spreadsheet',
                'route_prefix' => '/purchase-invoices?showId=',
                'append_id' => true,
                'main_fields' => ['invoice_number'],
            ],
            'warehouse' => [
                'key' => 'warehouse',
                'icon' => 'warehouse',
                'route_prefix' => '/warehouses/',
                'append_id' => true,
                'main_fields' => ['name', 'code'],
            ],
            'inventory_document' => [
                'key' => 'inventory_document',
                'icon' => 'boxes',
                'route_prefix' => '/inventory?showId=',
                'append_id' => true,
                'main_fields' => ['document_number'],
            ],
            'stocktake' => [
                'key' => 'stocktake',
                'icon' => 'clipboard-check',
                'route_prefix' => '/inventory/stocktakes?showId=',
                'append_id' => true,
                'main_fields' => ['stocktake_number'],
            ],
            'pos_shift' => [
                'key' => 'pos_shift',
                'icon' => 'clock',
                'route_prefix' => '/pos/shifts?showId=',
                'append_id' => true,
                'main_fields' => ['shift_number'],
            ],
            'pos_shift_transaction' => [
                'key' => 'pos_shift_transaction',
                'icon' => 'arrow-left-right',
                'route_prefix' => '/pos/shifts',
                'append_id' => false,
                'main_fields' => ['name', 'description', 'amount'],
            ],
            'pos_sale' => [
                'key' => 'pos_sale',
                'icon' => 'receipt',
                'route_prefix' => '/invoices?showId=',
                'append_id' => true,
                'main_fields' => ['invoice_number'],
            ],
            'pos_return' => [
                'key' => 'pos_return',
                'icon' => 'rotate-ccw',
                'route_prefix' => '/pos/returns?showId=',
                'append_id' => true,
                'main_fields' => ['return_number'],
            ],
            'expense_category' => [
                'key' => 'expense_category',
                'icon' => 'tags',
                'route_prefix' => '/expense-categories?showId=',
                'append_id' => true,
                'main_fields' => ['name'],
            ],
            'expense' => [
                'key' => 'expense',
                'icon' => 'wallet',
                'route_prefix' => '/expenses?showId=',
                'append_id' => true,
                'main_fields' => ['expense_number'],
            ],
            'account' => [
                'key' => 'account',
                'icon' => 'landmark',
                'route_prefix' => '/accounting/accounts?showId=',
                'append_id' => true,
                'main_fields' => ['name', 'code'],
            ],
            'journal' => [
                'key' => 'journal',
                'icon' => 'book-marked',
                'route_prefix' => '/accounting/journals?showId=',
                'append_id' => true,
                'main_fields' => ['name', 'code'],
            ],
            'fiscal_year' => [
                'key' => 'fiscal_year',
                'icon' => 'calendar-range',
                'route_prefix' => '/accounting/fiscal-years?showId=',
                'append_id' => true,
                'main_fields' => ['name', 'code'],
            ],
            'accounting_period' => [
                'key' => 'accounting_period',
                'icon' => 'calendar-days',
                'route_prefix' => '/accounting/accounting-periods?showId=',
                'append_id' => true,
                'main_fields' => ['name'],
            ],
            'payment_method' => [
                'key' => 'payment_method',
                'icon' => 'credit-card',
                'route_prefix' => '/accounting/payment-methods?showId=',
                'append_id' => true,
                'main_fields' => ['name'],
            ],
        ];
    }

    /**
     * Get module metadata for a given log_name.
     *
     * @return array{key: string, icon: string, route_prefix: ?string, append_id: bool, main_fields: list<string>}|null
     */
    public function get(?string $logName): ?array
    {
        if ($logName === null || $logName === '') {
            return null;
        }

        return $this->all()[$logName] ?? null;
    }

    /**
     * Localized human-readable module label.
     */
    public function label(?string $logName): string
    {
        if ($logName === null || $logName === '') {
            return '';
        }

        $translationKey = "activities.modules.{$logName}";
        if (Lang::has($translationKey)) {
            return (string) __($translationKey);
        }

        return Str::headline(str_replace('_', ' ', $logName));
    }

    /**
     * Build sorted module dropdown options merging registered modules and extra DB log names.
     *
     * @param  list<string>  $extraLogNames
     * @return list<array{key: string, label: string}>
     */
    public function options(array $extraLogNames = []): array
    {
        $keys = array_unique(array_merge(array_keys($this->all()), array_filter($extraLogNames)));

        $options = array_map(
            fn (string $key): array => [
                'key' => $key,
                'label' => $this->label($key),
            ],
            $keys
        );

        usort($options, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * Resolve a stored subject_type (morph alias or FQCN) to an Eloquent model class.
     */
    public function resolveSubjectClass(?string $subjectType): ?string
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        $morphed = Relation::getMorphedModel($subjectType);
        $candidate = is_string($morphed) ? $morphed : $subjectType;

        return class_exists($candidate) ? $candidate : null;
    }
}
