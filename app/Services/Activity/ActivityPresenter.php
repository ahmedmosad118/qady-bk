<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Activity;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class ActivityPresenter
{
    /** @var list<string> */
    private const IGNORED_DIFF_KEYS = [
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

    public function __construct(
        protected ActivityModuleRegistry $registry,
    ) {}

    /**
     * Present an Activity record into a structured frontend payload.
     *
     * @return array<string, mixed>
     */
    public function present(Activity $activity, ?Model $resolvedSubject = null): array
    {
        $logName = (string) ($activity->log_name ?? '');
        $meta = $this->registry->get($logName);
        $properties = $activity->getNormalizedProperties();
        $eventKey = $this->normalizeEventKey($activity->event, $activity->description);

        return [
            'id' => $activity->id,
            'log_name' => $logName,
            'module_label' => $this->registry->label($logName),
            'icon' => $meta['icon'] ?? 'activity',
            'event_key' => $eventKey,
            'event' => $this->translateEvent($eventKey, $activity->event),
            'description' => (string) ($activity->description ?? ''),
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'subject_name' => $this->resolveSubjectName($activity, $properties, $meta, $resolvedSubject),
            'link' => $this->resolveLink($activity, $eventKey, $meta),
            'causer' => $this->formatCauser($activity->causer),
            'row_changes' => $this->extractRowChanges($properties),
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /**
     * Normalize raw event/description strings to canonical action keys.
     */
    public function normalizeEventKey(?string $event, ?string $description = null): string
    {
        $raw = mb_strtolower(trim((string) ($event ?? $description ?? '')));

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

    protected function translateEvent(string $eventKey, ?string $rawEvent): string
    {
        if ($eventKey !== 'other') {
            return (string) __("activities.events.{$eventKey}");
        }

        if ($rawEvent !== null && $rawEvent !== '') {
            $key = 'activities.events.'.mb_strtolower($rawEvent);

            return Lang::has($key) ? (string) __($key) : $rawEvent;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array{key: string, icon: string, route_prefix: ?string, append_id: bool, main_fields: list<string>}|null  $meta
     */
    protected function resolveSubjectName(
        Activity $activity,
        array $properties,
        ?array $meta,
        ?Model $resolvedSubject
    ): string {
        $fields = array_values(array_unique(array_merge(
            $meta['main_fields'] ?? [],
            self::DEFAULT_SUBJECT_FIELDS
        )));

        foreach (['attributes', 'old'] as $bucket) {
            $payload = $properties[$bucket] ?? null;
            if (! is_array($payload)) {
                continue;
            }

            foreach ($fields as $field) {
                $candidate = $payload[$field] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    return Str::limit(trim(strip_tags($candidate)), 60);
                }
            }
        }

        $subject = $resolvedSubject ?? ($activity->relationLoaded('subject') ? $activity->subject : null);
        if ($subject instanceof Model) {
            foreach ($fields as $field) {
                $candidate = $subject->getAttribute($field);
                if (is_string($candidate) && trim($candidate) !== '') {
                    return Str::limit(trim(strip_tags($candidate)), 60);
                }
            }
        }

        return '';
    }

    /**
     * @param  array{key: string, icon: string, route_prefix: ?string, append_id: bool, main_fields: list<string>}|null  $meta
     */
    protected function resolveLink(Activity $activity, string $eventKey, ?array $meta): ?string
    {
        if ($eventKey === 'deleted' || $meta === null || empty($meta['route_prefix'])) {
            return null;
        }

        if (! $meta['append_id']) {
            return $meta['route_prefix'];
        }

        if ($activity->subject_id === null) {
            return null;
        }

        return $meta['route_prefix'].$activity->subject_id;
    }

    /**
     * @return array{id: int|string, name: string, email: ?string, initial: string}|null
     */
    protected function formatCauser(mixed $causer): ?array
    {
        if (! $causer instanceof User) {
            return null;
        }

        $name = trim((string) $causer->name) ?: (string) __('activities.values.unknown_user');

        return [
            'id' => $causer->id,
            'name' => $name,
            'email' => $causer->email,
            'initial' => mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8'),
        ];
    }

    /**
     * Extract human-readable field diffs from Spatie old/attributes buckets.
     *
     * @param  array<string, mixed>  $properties
     * @return list<array{key: string, label: string, old: string, new: string}>
     */
    protected function extractRowChanges(array $properties): array
    {
        $old = $properties['old'] ?? null;
        $attributes = $properties['attributes'] ?? null;

        if (! is_array($old) || ! is_array($attributes)) {
            return [];
        }

        $changes = [];

        foreach ($old as $key => $oldVal) {
            $keyStr = (string) $key;

            if ($this->shouldIgnoreField($keyStr) || ! array_key_exists($keyStr, $attributes)) {
                continue;
            }

            $newVal = $attributes[$keyStr];
            if ($this->valuesAreEquivalent($oldVal, $newVal)) {
                continue;
            }

            if (is_array($oldVal) || is_array($newVal) || $this->isJsonString($oldVal) || $this->isJsonString($newVal)) {
                continue;
            }

            $oldFormatted = $this->formatScalarValue($oldVal);
            $newFormatted = $this->formatScalarValue($newVal);

            if ($oldFormatted === '' && $newFormatted === '') {
                continue;
            }

            $none = (string) __('activities.values.none');

            $changes[] = [
                'key' => $keyStr,
                'label' => $this->translateField($keyStr),
                'old' => $oldFormatted !== '' ? $oldFormatted : $none,
                'new' => $newFormatted !== '' ? $newFormatted : $none,
            ];
        }

        return $changes;
    }

    protected function shouldIgnoreField(string $key): bool
    {
        if (in_array($key, self::IGNORED_DIFF_KEYS, true)) {
            return true;
        }

        return str_ends_with($key, '_id')
            || str_contains($key, 'token')
            || str_contains($key, 'password');
    }

    protected function valuesAreEquivalent(mixed $oldVal, mixed $newVal): bool
    {
        if ($oldVal instanceof BackedEnum) {
            $oldVal = $oldVal->value;
        }

        if ($newVal instanceof BackedEnum) {
            $newVal = $newVal->value;
        }

        return $oldVal === $newVal || ((string) $oldVal === (string) $newVal && is_bool($oldVal) === is_bool($newVal));
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

    protected function translateField(string $field): string
    {
        $key = "activities.fields.{$field}";
        if (Lang::has($key)) {
            return (string) __($key);
        }

        return Str::headline(str_replace(['.', '_'], ' ', $field));
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
