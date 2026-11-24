<?php

declare(strict_types=1);

namespace App\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as BaseActivity;

class Activity extends BaseActivity
{
    /** @var list<string> */
    private const IGNORED_KEYS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'password',
        'remember_token',
        'token',
        'client_uuid',
        'translations',
    ];

    /** @var list<string> */
    private const DEFAULT_SUBJECT_FIELDS = [
        'name',
        'title',
        'invoice_number',
        'order_number',
        'document_number',
        'stocktake_number',
        'expense_number',
        'return_number',
        'shift_number',
        'code',
        'label',
        'email',
        'type',
        'description',
    ];

    /** @var list<string> */
    protected $appends = [
        'icon',
        'link',
        'subject_name',
        'row_changes',
        'module_label',
        'event_key',
    ];

    /**
     * Module icon, route link, and primary subject fields (ported from plamx-v-5).
     *
     * @return array<string, array{icon: string, link: ?string, append_id: bool, main_field?: string}>
     */
    public function getActivityDataAttribute(): array
    {
        return [
            'user' => ['icon' => 'users', 'link' => '/users?showId=', 'append_id' => true, 'main_field' => 'name'],
            'role' => ['icon' => 'shield-check', 'link' => '/roles?showId=', 'append_id' => true, 'main_field' => 'name'],
            'setting' => ['icon' => 'settings', 'link' => '/settings', 'append_id' => false, 'main_field' => 'type'],
            'product' => ['icon' => 'book-open', 'link' => '/products?showId=', 'append_id' => true, 'main_field' => 'name'],
            'product_category' => ['icon' => 'folder-tree', 'link' => '/product-categories?showId=', 'append_id' => true, 'main_field' => 'name'],
            'contact' => ['icon' => 'contact', 'link' => '/contacts?showId=', 'append_id' => true, 'main_field' => 'name'],
            'contact_address' => ['icon' => 'map-pin', 'link' => '/contacts', 'append_id' => false, 'main_field' => 'label'],
            'purchase_order' => ['icon' => 'clipboard-list', 'link' => '/purchase-orders?showId=', 'append_id' => true, 'main_field' => 'order_number'],
            'purchase_invoice' => ['icon' => 'file-spreadsheet', 'link' => '/purchase-invoices?showId=', 'append_id' => true, 'main_field' => 'invoice_number'],
            'warehouse' => ['icon' => 'warehouse', 'link' => '/warehouses/', 'append_id' => true, 'main_field' => 'name'],
            'inventory_document' => ['icon' => 'boxes', 'link' => '/inventory?showId=', 'append_id' => true, 'main_field' => 'document_number'],
            'stocktake' => ['icon' => 'clipboard-check', 'link' => '/inventory/stocktakes?showId=', 'append_id' => true, 'main_field' => 'stocktake_number'],
            'pos_shift' => ['icon' => 'clock', 'link' => '/pos/shifts?showId=', 'append_id' => true, 'main_field' => 'shift_number'],
            'pos_shift_transaction' => ['icon' => 'arrow-left-right', 'link' => '/pos/shifts', 'append_id' => false, 'main_field' => 'name'],
            'pos_sale' => ['icon' => 'receipt', 'link' => '/invoices?showId=', 'append_id' => true, 'main_field' => 'invoice_number'],
            'pos_return' => ['icon' => 'rotate-ccw', 'link' => '/pos/returns?showId=', 'append_id' => true, 'main_field' => 'return_number'],
            'expense_category' => ['icon' => 'tags', 'link' => '/expense-categories?showId=', 'append_id' => true, 'main_field' => 'name'],
            'expense' => ['icon' => 'wallet', 'link' => '/expenses?showId=', 'append_id' => true, 'main_field' => 'expense_number'],
            'account' => ['icon' => 'landmark', 'link' => '/accounting/accounts?showId=', 'append_id' => true, 'main_field' => 'name'],
            'journal' => ['icon' => 'book-marked', 'link' => '/accounting/journals?showId=', 'append_id' => true, 'main_field' => 'name'],
            'fiscal_year' => ['icon' => 'calendar-range', 'link' => '/accounting/fiscal-years?showId=', 'append_id' => true, 'main_field' => 'name'],
            'accounting_period' => ['icon' => 'calendar-days', 'link' => '/accounting/accounting-periods?showId=', 'append_id' => true, 'main_field' => 'name'],
            'journal_entry' => ['icon' => 'book-open-check', 'link' => '/accounting/journal-entries?showId=', 'append_id' => true, 'main_field' => 'entry_number'],
            'payment_method' => ['icon' => 'credit-card', 'link' => '/accounting/payment-methods?showId=', 'append_id' => true, 'main_field' => 'name'],
        ];
    }

    public function getModuleLabelAttribute(): string
    {
        $logName = (string) ($this->attributes['log_name'] ?? '');
        if ($logName === '') {
            return '';
        }

        $key = "activities.modules.{$logName}";

        return Lang::has($key) ? (string) __($key) : Str::headline(str_replace('_', ' ', $logName));
    }

    public function getEventKeyAttribute(): string
    {
        $raw = mb_strtolower(trim((string) ($this->attributes['event'] ?? $this->attributes['description'] ?? '')));

        if ($raw === 'created' || str_contains($raw, 'create') || str_contains($raw, 'إنشاء') || str_contains($raw, 'انشاء') || str_contains($raw, 'إضافة')) {
            return 'created';
        }

        if ($raw === 'updated' || str_contains($raw, 'update') || str_contains($raw, 'تحديث') || str_contains($raw, 'تعديل')) {
            return 'updated';
        }

        if ($raw === 'deleted' || str_contains($raw, 'delete') || str_contains($raw, 'حذف')) {
            return 'deleted';
        }

        return 'other';
    }

    public function getEventAttribute(?string $value): string
    {
        $eventKey = $this->event_key;
        if ($eventKey !== 'other') {
            return (string) __("activities.events.{$eventKey}");
        }

        if ($value !== null && $value !== '') {
            $key = 'activities.events.'.mb_strtolower($value);

            return Lang::has($key) ? (string) __($key) : $value;
        }

        return '';
    }

    public function getIconAttribute(): string
    {
        $data = $this->activityData[(string) ($this->attributes['log_name'] ?? '')] ?? null;

        return $data['icon'] ?? 'activity';
    }

    public function getLinkAttribute(): ?string
    {
        $data = $this->activityData[(string) ($this->attributes['log_name'] ?? '')] ?? null;

        if ($this->event_key === 'deleted' || $data === null || empty($data['link'])) {
            return null;
        }

        if (! $data['append_id']) {
            return $data['link'];
        }

        if ($this->subject_id === null) {
            return null;
        }

        return $data['link'].$this->subject_id;
    }

    public function getSubjectNameAttribute(): string
    {
        $properties = $this->getNormalizedProperties();
        $data = $this->activityData[(string) ($this->attributes['log_name'] ?? '')] ?? null;
        $mainField = $data['main_field'] ?? null;

        $fields = array_values(array_unique(array_filter(array_merge(
            [$mainField],
            self::DEFAULT_SUBJECT_FIELDS
        ))));

        foreach (['attributes', 'old'] as $bucket) {
            if (isset($properties[$bucket]) && is_array($properties[$bucket])) {
                foreach ($fields as $field) {
                    $val = $properties[$bucket][$field] ?? null;
                    if (is_string($val) && trim($val) !== '') {
                        return Str::limit(trim(strip_tags($val)), 60);
                    }
                }
            }
        }

        $subject = $this->resolveSubjectModel();
        if ($subject instanceof Model) {
            foreach ($fields as $field) {
                $val = $subject->getAttribute($field);
                if (is_string($val) && trim($val) !== '') {
                    return Str::limit(trim(strip_tags($val)), 60);
                }
            }
        }

        return '';
    }

    /**
     * @return list<array{key: string, label: string, old: string, new: string}>
     */
    public function getRowChangesAttribute(): array
    {
        $properties = $this->getNormalizedProperties();
        $old = $properties['old'] ?? null;
        $attributes = $properties['attributes'] ?? null;

        if (! is_array($old) || ! is_array($attributes)) {
            return [];
        }

        $changes = [];
        $none = (string) __('activities.values.none');

        foreach ($old as $key => $oldVal) {
            $keyStr = (string) $key;

            if (in_array($keyStr, self::IGNORED_KEYS, true) || str_ends_with($keyStr, '_id') || str_contains($keyStr, 'token') || str_contains($keyStr, 'password')) {
                continue;
            }

            if (! array_key_exists($keyStr, $attributes)) {
                continue;
            }

            $newVal = $attributes[$keyStr];

            if ($oldVal instanceof BackedEnum) {
                $oldVal = $oldVal->value;
            }
            if ($newVal instanceof BackedEnum) {
                $newVal = $newVal->value;
            }

            if ($oldVal === $newVal || ((string) $oldVal === (string) $newVal && is_bool($oldVal) === is_bool($newVal))) {
                continue;
            }

            if (is_array($oldVal) || is_array($newVal) || $this->isJsonString($oldVal) || $this->isJsonString($newVal)) {
                continue;
            }

            $oldText = $this->formatScalarValue($oldVal);
            $newText = $this->formatScalarValue($newVal);

            if ($oldText === '' && $newText === '') {
                continue;
            }

            $fieldKey = "activities.fields.{$keyStr}";
            $label = Lang::has($fieldKey) ? (string) __($fieldKey) : Str::headline(str_replace(['.', '_'], ' ', $keyStr));

            $changes[] = [
                'key' => $keyStr,
                'label' => $label,
                'old' => $oldText !== '' ? $oldText : $none,
                'new' => $newText !== '' ? $newText : $none,
            ];
        }

        return $changes;
    }

    /**
     * Safely normalize properties into a clean PHP array.
     *
     * @return array<string, mixed>
     */
    public function getNormalizedProperties(): array
    {
        /** @var mixed $properties */
        $properties = $this->getAttribute('properties');

        if ($properties instanceof Collection) {
            return $properties->toArray();
        }

        if (is_array($properties)) {
            return $properties;
        }

        if (is_string($properties) && $properties !== '') {
            $decoded = json_decode($properties, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Format activity payload cleanly for JSON serialization (preserving causer initial for UI).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if ($this->causer instanceof User) {
            $name = trim((string) $this->causer->name) ?: (string) __('activities.values.unknown_user');
            $array['causer'] = [
                'id' => $this->causer->id,
                'name' => $name,
                'email' => $this->causer->email,
                'initial' => mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8'),
            ];
        } else {
            $array['causer'] = null;
        }

        unset($array['subject'], $array['properties']);

        return $array;
    }

    protected function resolveSubjectModel(): ?Model
    {
        if ($this->relationLoaded('subject') && $this->subject instanceof Model) {
            return $this->subject;
        }

        $rawType = $this->attributes['subject_type'] ?? null;
        $subjectId = $this->attributes['subject_id'] ?? null;
        if (! is_string($rawType) || $rawType === '' || $subjectId === null) {
            return null;
        }

        $morphed = Relation::getMorphedModel($rawType);
        $modelClass = is_string($morphed) ? $morphed : $rawType;
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            /** @phpstan-ignore method.notFound */
            $query->withTrashed();
        }

        return $query->find($subjectId);
    }

    protected function formatScalarValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return (string) __($value ? 'activities.values.yes' : 'activities.values.no');
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return trim((string) $value);
    }

    protected function isJsonString(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $trimmed = ltrim($value);
        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return false;
        }

        json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
