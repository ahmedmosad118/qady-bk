<?php

namespace App\Models;

use App\Enums\ContactType;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('mobile', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('tax_number', 'like', "%{$term}%")
                ->orWhere('commercial_register', 'like', "%{$term}%")
                ->orWhere('company_contact_person', 'like', "%{$term}%");
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
}
