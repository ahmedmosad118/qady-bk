<?php

namespace App\Models;

use Database\Factories\ContactAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'contact_id',
    'label',
    'address',
    'city',
    'governorate',
    'postal_code',
    'country',
    'is_default',
])]
class ContactAddress extends Model
{
    /** @use HasFactory<ContactAddressFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * The contact this address belongs to.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    protected static function booted(): void
    {
        // When setting an address as default, unset default on all other addresses of this contact.
        static::saving(function (ContactAddress $address): void {
            if ($address->is_default && $address->contact_id) {
                static::where('contact_id', $address->contact_id)
                    ->when($address->id, fn ($q) => $q->where('id', '!=', $address->id))
                    ->update(['is_default' => false]);
            }
        });

        // When a default address is deleted, assign default to the oldest active remaining address.
        static::deleted(function (ContactAddress $address): void {
            if ($address->is_default && $address->contact_id) {
                static::where('contact_id', $address->contact_id)
                    ->orderBy('id', 'asc')
                    ->first()
                    ?->updateQuietly(['is_default' => true]);
            }
        });
    }
}
