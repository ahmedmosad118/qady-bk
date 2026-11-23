<?php

namespace App\Models;

use App\Enums\ContactType;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'type',
    'name',
    'company_contact_person',
    'phone',
    'mobile',
    'email',
    'tax_number',
    'tax_registration_type',
    'commercial_register',
    'is_customer',
    'is_supplier',
    'receivable_account_id',
    'payable_account_id',
    'is_active',
    'notes',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'receivable_account_id' => 'integer',
            'payable_account_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * All registered addresses for this contact.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class);
    }

    /**
     * The designated default address for this contact.
     */
    public function defaultAddress(): HasOne
    {
        return $this->hasOne(ContactAddress::class)->where('is_default', true);
    }

    /**
     * Scope to customer contacts only.
     */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('is_customer', true);
    }

    /**
     * Scope to supplier contacts only.
     */
    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->where('is_supplier', true);
    }

    /**
     * Scope to active contacts only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to individual contacts.
     */
    public function scopeIndividual(Builder $query): Builder
    {
        return $query->where('type', ContactType::INDIVIDUAL);
    }

    /**
     * Scope to company contacts.
     */
    public function scopeCompany(Builder $query): Builder
    {
        return $query->where('type', ContactType::COMPANY);
    }

    /**
     * Scope search query across common contact fields.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $normalizedDigits = strtr($term, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return $query->where(function (Builder $q) use ($term, $normalizedDigits) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('mobile', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('tax_number', 'like', "%{$term}%")
                ->orWhere('commercial_register', 'like', "%{$term}%")
                ->orWhere('company_contact_person', 'like', "%{$term}%");

            if ($normalizedDigits !== $term) {
                $q->orWhere('phone', 'like', "%{$normalizedDigits}%")
                    ->orWhere('mobile', 'like', "%{$normalizedDigits}%");
            }
        });
    }

    public function isIndividual(): bool
    {
        return $this->type === ContactType::INDIVIDUAL;
    }

    public function isCompany(): bool
    {
        return $this->type === ContactType::COMPANY;
    }

    /**
     * Purchase orders placed with this supplier.
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    /**
     * Purchase invoices issued by this supplier.
     */
    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'supplier_id');
    }

    /**
     * General expenses optionally linked to this contact.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'contact_id');
    }

    /**
     * Receivable account linked to this customer contact.
     */
    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }

    /**
     * Payable account linked to this supplier contact.
     */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }
}
