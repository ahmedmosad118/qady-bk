<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PermissionCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * @property int|string $id
 * @property string $name
 * @property string $guard_name
 * @property string|null $category
 * @property string|null $slug
 * @property string|null $module
 * @property string|null $module_name
 * @property string|null $action
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Permission extends SpatiePermission
{
    /**
     * Standard CRUD actions separated from advanced/workflow permissions in the matrix UI.
     *
     * @var array<int, string>
     */
    public const BASIC_ACTIONS = ['view', 'create', 'update', 'delete'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'category',
        'slug',
        'module',
        'module_name',
        'action',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Determine whether this permission represents a standard CRUD action.
     */
    public function isBasicAction(): bool
    {
        return in_array((string) $this->action, self::BASIC_ACTIONS, true);
    }

    /**
     * Scope query to a specific permission category.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeInCategory(Builder $query, PermissionCategory|string $category): Builder
    {
        $value = $category instanceof PermissionCategory ? $category->value : $category;

        return $query->where('category', $value);
    }

    /**
     * Scope query to a specific permission module.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    /**
     * Order permissions deterministically by sort order then primary key.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
