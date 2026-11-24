<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int|string $id
 * @property string $name
 * @property string $guard_name
 * @property string|null $description
 * @property bool $is_super_admin
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends SpatieRole
{
    use LogsModelActivity;

    protected string $guard_name = 'web';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'description',
        'is_super_admin',
        'is_system',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'guard_name' => 'web',
        'is_super_admin' => false,
        'is_system' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_super_admin' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Configure activity log options for role changes.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'is_super_admin', 'is_system'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('role');
    }

    /**
     * Determine whether this role grants unrestricted Super Admin access.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Determine whether this role is a protected system-defined role.
     */
    public function isSystemRole(): bool
    {
        return (bool) $this->is_system;
    }

    /**
     * Scope query to Super Admin roles.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->where('is_super_admin', true);
    }

    /**
     * Scope query to custom (non-Super-Admin) roles.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_super_admin', false);
    }
}
