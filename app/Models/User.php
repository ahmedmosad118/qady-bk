<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'status', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
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
