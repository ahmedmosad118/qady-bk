<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'status', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsModelActivity, Notifiable;

    /**
     * Default Spatie permission guard for user roles and permissions.
     */
    protected string $guard_name = 'web';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'status' => UserStatus::class,
        ];
    }

    /**
     * Configure activity log options for user changes without exposing secrets.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('user');
    }

    /**
     * Determine whether the user account is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }

    /**
     * Determine whether the user holds a Super Admin role.
     */
    public function isSuperAdmin(): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains(
            static fn (mixed $role): bool => $role instanceof Role && $role->isSuperAdmin()
        );
    }

    public function getAvatarUrlAttribute(): string
    {
        return 'https://ui-avatars.com/api/?background=00C896&color=fff&name='.urlencode($this->name);
    }

    public function posSales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'created_by');
    }

    public function posReturns(): HasMany
    {
        return $this->hasMany(PosReturn::class, 'created_by');
    }

    public function posShiftsOpened(): HasMany
    {
        return $this->hasMany(PosShift::class, 'opened_by');
    }

    public function posShiftsClosed(): HasMany
    {
        return $this->hasMany(PosShift::class, 'closed_by');
    }

    public function purchaseOrdersCreated(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'created_by');
    }

    public function purchaseOrdersConfirmed(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'confirmed_by');
    }

    public function purchaseInvoicesCreated(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'created_by');
    }

    public function expensesCreated(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }
}
